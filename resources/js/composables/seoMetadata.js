/**
 * =====================================================================
 * CHỨC NĂNG FILE: Utility SEO dùng chung cho mọi form nội dung.
 * =====================================================================
 *
 * File không giữ state; các hàm thuần chuẩn hóa metadata, phân tích HTML và
 * dựng checklist. Composable `useSeoMetadata` dùng lại các hàm này cho Post,
 * Resource và các model nội dung tương lai.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - createSeo(): tạo state SEO từ payload API
 * - wordsOf()/analyzeContent(): thống kê nội dung HTML
 * - analyzeSeo(): tính checklist, score và metadata hiệu lực
 * - buildContentUrl(): dựng URL public theo path model
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : metadata, title/content/excerpt và media của model nội dung.
 * - OUTPUT: state SEO, thống kê content hoặc kết quả checklist không ghi DB.
 * =====================================================================
 */

/** Input: payload API Post/Resource tùy chọn. Output: state SEO camelCase độc lập model. */
export const createSeo = (model = {}) => {
  const nested = model?.seo_metadata ?? model?.seo ?? model

  return {
    focusKeyword: nested?.focus_keyword ?? nested?.focusKeyword ?? '',
    title: nested?.seo_title ?? nested?.title ?? '',
    description: nested?.seo_description ?? nested?.description ?? '',
    canonicalUrl: nested?.canonical_url ?? nested?.canonicalUrl ?? '',
    robotsIndex: nested?.robots_index ?? nested?.robotsIndex ?? true,
    robotsFollow: nested?.robots_follow ?? nested?.robotsFollow ?? true,
    ogTitle: nested?.og_title ?? nested?.ogTitle ?? '',
    ogDescription: nested?.og_description ?? nested?.ogDescription ?? '',
    ogImageId: nested?.og_image_id ?? nested?.ogImageId ?? null,
    ogImage: nested?.og_image ?? nested?.ogImage ?? model?.og_image ?? null,
  }
}

/** Input: text. Output: đơn vị phân cách khoảng trắng chứa chữ/số. */
export const wordsOf = text => String(text).split(/\s+/u).filter(word => /[\p{L}\p{N}]/u.test(word))

/** Input: keyword/text. Output: lowercase NFC, giữ dấu tiếng Việt để so khớp tự nhiên. */
const normalizeKeyword = text => String(text).normalize('NFC').toLocaleLowerCase('vi').replace(/[^\p{L}\p{N}]+/gu, ' ').trim()

/** Input: keyword. Output: chuỗi không dấu để so khớp trong slug. */
const slugKeyword = text => normalizeKeyword(text).normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/\s+/g, '-')

/** Input: slug, origin và path model. Output: URL public đã encode slug. */
export const buildContentUrl = (slug, origin, path = '/blog') => {
  const basePath = String(path || '/').replace(/\/$/u, '') || '/'

  return new URL(`${basePath}/${encodeURIComponent(slug || '')}`, origin).href
}

/** Input: HTML editor và origin. Output: text/words/headings/links/images. */
export function analyzeContent(html, origin = typeof window !== 'undefined' ? window.location.origin : 'http://localhost') {
  const template = document.createElement('template')

  template.innerHTML = html || ''

  const root = template.content

  root.querySelectorAll('script,style,template,noscript,[hidden]').forEach(node => node.remove())

  const headings = root.querySelectorAll('h2').length

  const images = [...root.querySelectorAll('img')]
    .filter(node => !['presentation', 'none'].includes(node.getAttribute('role')) && node.getAttribute('aria-hidden') !== 'true')
    .map(node => ({ alt: node.getAttribute('alt')?.trim() || '' }))

  const links = [...root.querySelectorAll('a[href]')].filter(node => {
    const href = node.getAttribute('href').trim()
    if (!href || href.startsWith('#'))
      return false
    try {
      const url = new URL(href, origin)

      return ['http:', 'https:'].includes(url.protocol) && url.origin === new URL(origin).origin
    }
    catch { return false }
  }).length

  root.querySelectorAll('p,div,section,article,li,h1,h2,h3,h4,h5,h6,blockquote,tr,td,br,hr').forEach(node => {
    node.before(document.createTextNode(' '))
    node.after(document.createTextNode(' '))
  })

  const text = (root.textContent || '').replace(/\s+/gu, ' ').trim()

  return { text, words: wordsOf(text), headings, links, images }
}

/**
 * Input: form/model, slug đã kiểm tra, URL và thống kê HTML.
 * Output: checklist SEO, score và metadata hiệu lực; không gửi score lên API.
 */
export function analyzeSeo({ form = {}, slug = '', checked = false, url = '', content }) {
  const seo = form.seo ?? form.seoMetadata ?? form.seo_metadata ?? {}
  const titleSource = form.title ?? ''
  const descriptionSource = form.excerpt ?? form.shortDescription ?? form.short_description ?? ''
  const title = String(seo.title ?? seo.seo_title ?? '').trim() || String(titleSource).trim()
  const description = String(seo.description ?? seo.seo_description ?? '').trim() || String(descriptionSource).trim()
  const keyword = normalizeKeyword(seo.focusKeyword ?? seo.focus_keyword ?? '')
  const contains = text => Boolean(keyword) && (` ${normalizeKeyword(text)} `).includes(` ${keyword} `)
  const featuredImage = form.thumbnail ?? form.featuredImage ?? form.cover ?? null
  const contentImages = form.contentImages ?? form.imageGallery ?? form.content_images ?? form.preview ?? []
  const assets = [featuredImage, ...(Array.isArray(contentImages) ? contentImages : [])]
  const uniqueAssets = [...new Map(assets.filter(Boolean).map(asset => [asset.id, asset])).values()]
  const images = [...(content?.images ?? []), ...uniqueAssets.map(asset => ({ alt: asset.alt_text?.trim() || asset.altText?.trim() || '' }))]
  const altCount = images.filter(image => image.alt).length
  const titleLength = [...title].length
  const descriptionLength = [...description].length
  const rules = []

  /** Input: rule data. Output: một item checklist có trạng thái chuẩn hóa. */
  const add = (id, label, passed, progress, weight, hint, applicable = true) => rules.push({
    id, label, passed: Boolean(passed), progress, weight, hint,
    status: !applicable ? 'na' : passed ? 'passed' : 'pending',
  })

  const keywordHint = keyword ? 'Viết tự nhiên, có nhắc đến từ khóa chính.' : 'Chưa có từ khóa chính.'
  const wordCount = content?.words?.length ?? 0
  const headingCount = content?.headings ?? 0
  const linkCount = content?.links ?? 0

  add('words', 'Nội dung tối thiểu 300 từ', wordCount >= 300, `${wordCount}/300`, 20, `Cần thêm ${Math.max(0, 300 - wordCount)} từ.`)
  add('keyword', 'Có từ khóa chính', keyword, `${keyword ? 1 : 0}/1`, 5, 'Nhập từ khóa chính để phân tích.')
  add('title-length', 'Độ dài tiêu đề SEO', titleLength >= 30 && titleLength <= 60, `${titleLength} ký tự · mục tiêu 30–60`, 10, 'Điều chỉnh tiêu đề SEO trong khoảng đề xuất.')
  add('title-keyword', 'Từ khóa trong tiêu đề SEO', contains(title), `${contains(title) ? 1 : 0}/1`, 10, keywordHint)
  add('slug', 'Slug đã được backend kiểm tra', checked, checked ? 'Đã kiểm tra' : 'Chưa kiểm tra', 5, 'Bỏ focus Title để kiểm tra slug; chưa giữ chỗ cho đến khi lưu.')

  const keywordSlug = slugKeyword(keyword)
  const slugMatches = checked && keywordSlug && (`-${slug}-`).includes(`-${keywordSlug}-`)

  add('slug-keyword', 'Từ khóa trong slug', slugMatches, `${slugMatches ? 1 : 0}/1`, 5, keywordHint)
  add('description-length', 'Độ dài mô tả SEO', descriptionLength >= 120 && descriptionLength <= 160, `${descriptionLength} ký tự · mục tiêu 120–160`, 10, 'Điều chỉnh mô tả SEO trong khoảng đề xuất.')
  add('description-keyword', 'Từ khóa trong mô tả SEO', contains(description), `${contains(description) ? 1 : 0}/1`, 5, keywordHint)
  add('intro', 'Từ khóa trong 100 từ đầu', contains((content?.words ?? []).slice(0, 100).join(' ')), `${contains((content?.words ?? []).slice(0, 100).join(' ')) ? 1 : 0}/1`, 10, keywordHint)
  add('heading', 'Có tiêu đề H2', headingCount >= 1, `${headingCount}/1`, 5, 'Chia nội dung thành các phần có tiêu đề H2.')
  add('links', 'Có liên kết nội bộ', linkCount >= 1, `${linkCount}/1`, 5, 'Thêm liên kết hữu ích đến trang cùng website.')
  add('thumbnail', 'Có Featured Image', Boolean(featuredImage?.id), `${featuredImage?.id ? 1 : 0}/1`, 5, 'Chọn ảnh đại diện từ Media Library.')
  add('alt', 'Ảnh nội dung có mô tả alt', images.length > 0 && altCount === images.length, images.length ? `${altCount}/${images.length}` : 'Không áp dụng', 5, 'Bổ sung alt cho ảnh trong editor hoặc Media Library.', images.length > 0)

  const applicable = rules.filter(rule => rule.status !== 'na')
  const total = applicable.reduce((sum, rule) => sum + rule.weight, 0)
  const earned = applicable.reduce((sum, rule) => sum + (rule.passed ? rule.weight : 0), 0)

  return {
    rules, score: total ? Math.round(earned / total * 100) : 0,
    title, description, url: String(seo.canonicalUrl ?? seo.canonical_url ?? '').trim() || url,
    ogTitle: String(seo.ogTitle ?? seo.og_title ?? '').trim() || title,
    ogDescription: String(seo.ogDescription ?? seo.og_description ?? '').trim() || description,
    ogImageId: seo.ogImageId ?? seo.og_image_id ?? featuredImage?.id ?? null,
  }
}
