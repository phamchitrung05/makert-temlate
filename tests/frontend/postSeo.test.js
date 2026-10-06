/* eslint-disable camelcase */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm checklist SEO theo vị trí ảnh content và Gallery độc lập.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: evaluate(), các test word/keyword/URL/alt/fallback.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): HTML/form/media giả -> checklist/score;
 * không gọi AI/API hoặc ghi database.
 * =====================================================================
 */
import { describe, expect, it } from 'vitest'
import { analyzeContent, analyzeSeo, createSeo } from '@/composables/seoMetadata'

const origin = 'https://example.test'

/**
 * =====================================================================
 * Input: form overrides và slug đã kiểm. Output: checklist public từ HTML thật.
 * =====================================================================
 */
const evaluate = (overrides = {}, checked = false) => {
  const form = { title: '', excerpt: '', content: '', seo: createSeo(), thumbnail: null, galleryImages: [], ...overrides }

  return analyzeSeo({ form, slug: 'duong-den-thanh-cong', checked, url: `${origin}/blog/test`, content: analyzeContent(form.content, origin) })
}

describe('Post SEO rules', () => {
  it.each([299, 300, 301])('shows exact word progress at %i words', count => {
    const result = evaluate({ content: `<p>${Array(count).fill('từ').join(' ')}</p>` })
    const rule = result.rules.find(item => item.id === 'words')

    expect(rule.progress).toBe(`${count}/300`)
    expect(rule.passed).toBe(count >= 300)
  })

  it('parses entities and blocks without counting scripts, styles or punctuation', () => {
    const result = analyzeContent('<p>Xin&nbsp;chào</p><p>Việt <strong>Nam</strong> &amp; !!!</p><script>fake words</script><style>fake words</style><p hidden>hidden text</p>', origin)

    expect(result.words).toEqual(['Xin', 'chào', 'Việt', 'Nam'])
    expect(analyzeContent('<p><br></p>', origin).words).toHaveLength(0)
    expect(analyzeContent('<p>hel<strong>lo</strong></p>', origin).words).toEqual(['hello'])
  })

  it('distinguishes internal links and ignores unsafe, empty and fragment URLs', () => {
    const result = analyzeContent('<h2>A</h2><a href="/blog/a">A</a><a href="https://example.test/b">B</a><a href="https://other.test">C</a><a href="javascript:alert(1)">D</a><a href="#top">E</a><a href="">F</a>', origin)

    expect(result.headings).toBe(1)
    expect(result.links).toBe(2)
  })

  it('does not award dependent rules for missing keywords or missing images', () => {
    const result = evaluate()

    expect(result.score).toBe(0)
    expect(result.rules.find(rule => rule.id === 'alt').status).toBe('na')
    expect(result.rules.find(rule => rule.id === 'title-keyword').passed).toBe(false)
  })

  it('uses separate SEO overrides and fallback without mutating article data', () => {
    const form = { title: 'Bài viết', excerpt: 'Tóm tắt' }
    const fallback = evaluate(form)
    const override = evaluate({ ...form, seo: { ...createSeo(), title: 'SEO riêng', description: 'Mô tả', ogTitle: 'Chia sẻ' } })

    expect(fallback.title).toBe('Bài viết')
    expect(fallback.description).toBe('Tóm tắt')
    expect(override.title).toBe('SEO riêng')
    expect(override.ogTitle).toBe('Chia sẻ')
    expect(form.title).toBe('Bài viết')
  })

  it('counts alt per content image and unique media asset, ignoring decorative images', () => {
    const asset = { id: 1, alt_text: 'Ảnh' }
    const result = evaluate({ thumbnail: asset, galleryImages: [asset, { id: 2, alt_text: '' }], content: '<img alt="Mô tả"><img alt="" role="presentation">' })

    expect(result.rules.find(rule => rule.id === 'alt').progress).toBe('2/3')
    expect(result.rules.find(rule => rule.id === 'alt').passed).toBe(false)
  })

  it('checks inline alt and gallery metadata independently when an image is explicitly selected for both roles', () => {
    const result = evaluate({ galleryImages: [{ id: 7, alt_text: '' }], content: '<figure><img data-media-asset-id="7" src="https://example.test/image.png" alt="Mô tả tại vị trí chèn"><figcaption>Chú thích</figcaption></figure>' })

    expect(result.rules.find(rule => rule.id === 'alt').progress).toBe('1/2')
    expect(result.rules.find(rule => rule.id === 'alt').passed).toBe(false)
  })

  it('checks every repeated content occurrence and the independent gallery images', () => {
    const result = evaluate({ galleryImages: [{ id: 7, alt_text: 'Metadata không thay thế alt HTML' }, { id: 8, alt_text: 'Ảnh trang trí' }, { id: 9, alt_text: 'Ảnh gallery riêng' }], content: '<img data-media-asset-id="7" alt="Mô tả"><img data-media-asset-id="7" alt=""><img data-media-asset-id="8" role="presentation" alt="">' })

    expect(result.rules.find(rule => rule.id === 'alt').progress).toBe('4/5')
    expect(result.rules.find(rule => rule.id === 'alt').passed).toBe(false)
  })

  it('normalizes Vietnamese keywords and awards exactly 100 when every rule passes', () => {
    const keyword = 'đường đến thành công'

    const result = evaluate({
      title: 'Đường đến thành công cho người mới bắt đầu',
      excerpt: `Đường đến thành công ${'a'.repeat(110)}`,
      seo: { ...createSeo(), focusKeyword: keyword.normalize('NFD') },
      content: `<h2>Hướng dẫn</h2><p>${keyword} ${'từ '.repeat(300)}</p><a href="/blog/a">Đọc thêm</a>`,
      thumbnail: { id: 1, alt_text: 'Đường đi' },
    }, true)

    expect(result.rules.every(rule => rule.passed)).toBe(true)
    expect(result.score).toBe(100)
  })
})
