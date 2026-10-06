/* eslint-disable camelcase -- Payload dùng contract Laravel AI Agent. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuẩn hóa và kiểm tra nguồn của form tạo bài AI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: createAiContentSource(), validateAiContentSource(),
 * withoutAiTaxonomyOutputs(), extractHtmlText(), buildAiContentRequest(), buildAiContentRegenerateRequest()/groupFor().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : URL/file HTML/text/đề bài và model catalog đã chọn.
 * - OUTPUT: lý do chưa thể tạo hoặc payload JSON/FormData giữ HTML nguyên bản.
 * - SIDE EFFECT: không execute HTML, fetch URL hoặc gọi provider.
 * =====================================================================
 */
const maxTextLength = 200000
const maxFileBytes = 5 * 1024 * 1024

/**
 * =====================================================================
 * CHỨC NĂNG: Loại nhóm/field taxonomy khỏi lựa chọn AI generation.
 * =====================================================================
 * INPUT: danh sách nhóm/field hiện tại, có thể có key legacy.
 * OUTPUT: danh sách mới giữ thứ tự, không chứa taxonomy/category/tag AI.
 * SIDE EFFECT: hàm thuần; không thay đổi lựa chọn taxonomy thủ công của Post.
 * =====================================================================
 */
import { articleRequestBody, emptyWritingPreferences, writingOptions } from '@/utils/aiArticleOptions'

export const withoutAiTaxonomyOutputs = (outputs = []) => outputs.filter(output =>
  !['taxonomy', 'category_ids', 'tag_ids', 'suggested_category_ids', 'suggested_tag_ids'].includes(output))

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo state nguồn mới theo output mặc định được hỗ trợ.
 * =====================================================================
 * INPUT: output mặc định từ catalog, có thể còn taxonomy legacy.
 * OUTPUT: nguồn mới; outputs null khi còn chờ catalog khởi tạo.
 * SIDE EFFECT: hàm thuần; không thay đổi taxonomy thủ công.
 * =====================================================================
 */
export const createAiContentSource = (defaults = {}) => ({
  targetType: 'post', type: 'url', url: '', text: '', html: '', prompt: '', file: null,
  provider: '', model: '', language: 'vi', length: 'medium',
  thumbnailMode: 'source', imageModelId: null, thumbnailPrompt: '',
  outputs: Array.isArray(defaults.outputs) ? withoutAiTaxonomyOutputs(defaults.outputs) : null,
  title: '', instructions: '', sourceEncoding: 'UTF-8', writing: emptyWritingPreferences(), category_ids: [], tag_ids: [],
})

/**
 * =====================================================================
 * CHỨC NĂNG: Kiểm tra nguồn, model và output trước khi tạo bài AI.
 * =====================================================================
 * INPUT: state nguồn và catalog backend.
 * OUTPUT: thông báo validation hoặc chuỗi rỗng khi hợp lệ.
 * SIDE EFFECT: hàm thuần; không gửi request hoặc đọc file.
 * =====================================================================
 */
export function validateAiContentSource(source, catalog) {
  if (catalog.loading) return 'Đang tải provider và model…'
  if (catalog.error) return 'Không tải được model. Hãy tải lại catalog.'
  if (catalog.targetOptions && !catalog.targetOptions.some(target => target.value === source.targetType))
    return 'Chọn tài nguyên được phép tạo nội dung AI.'
  if (!source.provider || !catalog.selectedModel) return 'Chọn provider và model để viết bài.'
  if (!catalog.selectedModel.capabilities?.includes('text_generation'))
    return 'Model này không hỗ trợ viết nội dung. Chọn model có khả năng Text generation trong AI Settings.'
  const availableOutputs = withoutAiTaxonomyOutputs((catalog.outputOptions ?? []).filter(option => !option.props?.disabled).map(option => option.value))
  const outputs = source.outputs ?? []

  if (!outputs.length) return 'Chọn ít nhất một hạng mục AI sẽ tạo.'
  if (outputs.some(output => !availableOutputs.includes(output))) return 'Chọn các hạng mục AI được hỗ trợ cho tài nguyên và nguồn này.'
  if (outputs.includes('thumbnail') && source.thumbnailMode === 'generate'
    && !(catalog.imageModelOptions ?? []).some(option => option.value === source.imageModelId))
    return 'Chọn model ảnh đã bật và khả dụng để tạo thumbnail AI.'
  if ((source.thumbnailPrompt ?? '').length > 4000) return 'Yêu cầu tạo ảnh tối đa 4.000 ký tự.'
  if (!outputs.includes('title') && !source.title.trim()) return 'Nhập tiêu đề hoặc chọn hạng mục Tiêu đề để AI tạo.'
  if (source.title.length > 255) return 'Tiêu đề không được dài quá 255 ký tự.'
  if (source.type === 'url') {
    try {
      const url = new URL(source.url.trim())
      if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error('URL')
      if (source.url.trim().length > 2048) return 'URL không được dài quá 2048 ký tự.'
    }
    catch { return 'Nhập URL nguồn hợp lệ bắt đầu bằng http:// hoặc https://.' }
  }
  else if (source.type === 'file') {
    const file = Array.isArray(source.file) ? source.file[0] : source.file
    if (!file) return 'Chọn file HTML nguồn.'
    if (!/\.html?$/i.test(file.name)) return 'Chỉ hỗ trợ file .html hoặc .htm.'
    if (file.size > maxFileBytes) return 'File HTML không được lớn hơn 5 MB.'
  }
  else if (['text', 'prompt', 'html'].includes(source.type)) {
    const text = source[source.type].trim()
    if (!text) return source.type === 'prompt' ? 'Nhập yêu cầu viết bài.' : 'Nhập nội dung nguồn.'
    if (text.length > (source.type === 'html' ? maxFileBytes : maxTextLength)) return 'Nguồn vượt giới hạn; text tối đa 200.000 ký tự, HTML tối đa 5 MB.'
  }
  else return 'Chọn loại nguồn được hỗ trợ.'

  if ((source.instructions ?? '').length > 4000) return 'Yêu cầu bổ sung tối đa 4.000 ký tự.'
  if (Object.values(source.writing?.brief ?? {}).some(value => String(value).length > 1000)) return 'Mỗi mục brief tối đa 1.000 ký tự.'

  return ''
}

/**
 * =====================================================================
 * CHỨC NĂNG: Rút text từ file HTML theo luồng form hiện có.
 * =====================================================================
 * INPUT: chuỗi HTML cục bộ.
 * OUTPUT: text bài viết sau khi bỏ thành phần dư thừa.
 * SIDE EFFECT: tạo DOM tách rời; không execute script hoặc chèn vào page.
 * =====================================================================
 */
export function extractHtmlText(html) {
  const template = document.createElement('template')

  template.innerHTML = html
  template.content.querySelectorAll('script, style, noscript, template, iframe, object, svg, nav, footer').forEach(node => node.remove())

  const article = template.content.querySelector('article, main') ?? template.content

  article.querySelectorAll('p, div, section, h1, h2, h3, h4, li, br, pre').forEach(node => node.append('\n'))

  return (article.textContent ?? '').replace(/[^\S\n]+/g, ' ').replace(/ *\n */g, '\n').replace(/\n{3,}/g, '\n\n').trim()
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chuẩn hóa nguồn và output AI thành request create.
 * =====================================================================
 * INPUT: nguồn và model đã validate.
 * OUTPUT: payload create không yêu cầu AI tạo taxonomy.
 * SIDE EFFECT: đóng gói file gốc bằng FormData; không đọc/flatten HTML hoặc gọi AI.
 * EXCEPTION: server kiểm file/encoding/budget; client kiểm nguồn trước khi gửi.
 * =====================================================================
 */
export async function buildAiContentRequest(source, model) {
  const outputs = withoutAiTaxonomyOutputs([...new Set(source.outputs ?? [])]).filter(output => output !== 'thumbnail' || source.type === 'url' || source.thumbnailMode === 'generate')
  let input
  let rawSource = {}
  if (source.type === 'url') input = { type: 'url', url: source.url.trim() }
  else if (source.type === 'file') {
    rawSource = { html_file: Array.isArray(source.file) ? source.file[0] : source.file, source_encoding: source.sourceEncoding || 'UTF-8' }
  }
  else if (source.type === 'html') input = { type: 'html', html: source.html.trim() }
  else {
    const text = source[source.type].trim()
    if (!text) throw new Error('Nhập nội dung nguồn.')
    if (text.length > maxTextLength) throw new Error('Nội dung nguồn không được dài quá 200.000 ký tự.')
    input = { type: 'text', text }
  }
  const lengths = { short: '300–500 từ', medium: '700–1.000 từ', long: '1.500–2.000 từ' }

  const instructions = [
    `Viết bài bằng ${source.language === 'en' ? 'tiếng Anh' : 'tiếng Việt'}, độ dài tham khảo ${lengths[source.length] ?? lengths.medium} nếu nguồn đủ thông tin. Ưu tiên brief riêng, không lặp ý để kéo dài bài.`,
    source.type === 'prompt' ? 'Đầu vào là đề bài và yêu cầu viết, hãy phát triển thành một bài viết mới hoàn chỉnh.' : 'Bám sát nguồn, không bịa thông tin.',
    outputs.includes('title') ? 'Tạo tiêu đề phù hợp với bài viết.' : `Dùng chính xác tiêu đề: ${source.title.trim()}`,
    outputs.includes('seo') ? 'Tối ưu tiêu đề, mô tả SEO và cấu trúc heading tự nhiên.' : '',
    source.instructions?.trim(),
  ].filter(Boolean).join('\n')

  if (!outputs.includes('title')) {
    if (input) input.title = source.title.trim()
    else rawSource.title = source.title.trim()
  }

  return articleRequestBody({
    target_type: source.targetType || 'post', operation: 'create', ...(input ? { input } : rawSource), output_language: source.language,
    provider: source.provider, model: source.model, model_id: model.id, instructions,
    ...writingOptions(source.writing),
    ...(source.targetType === 'post' || !source.targetType ? { category_ids: source.category_ids ?? [], tag_ids: source.tag_ids ?? [] } : {}),
    ...(source.type === 'html' ? { source_encoding: source.sourceEncoding || 'UTF-8' } : {}),
    requested_outputs: outputs,
    generate_seo: outputs.includes('seo'),
    generate_thumbnail: outputs.includes('thumbnail'), thumbnail_mode: source.thumbnailMode || 'source',
    ...(outputs.includes('thumbnail') && source.thumbnailMode === 'generate' ? { image_model_id: source.imageModelId, thumbnail_prompt: source.thumbnailPrompt?.trim() || '' } : {}),
  })
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chuẩn hóa nhóm AI cần tạo lại và override tùy chọn.
 * =====================================================================
 * INPUT: nhóm/field được chọn và prompt/model/instructions override.
 * OUTPUT: contract regenerate chỉ chứa output AI được hỗ trợ.
 * SIDE EFFECT: hàm thuần; không đọc nguồn hoặc gọi provider.
 * EXCEPTION: throw khi chỉ chọn taxonomy/field không hỗ trợ để tránh tạo lại toàn bộ.
 * =====================================================================
 */
export function buildAiContentRegenerateRequest(options = {}) {
  const allowed = ['title', 'excerpt', 'content', 'seo', 'thumbnail']

  /**
   * =====================================================================
   * CHỨC NĂNG: Đổi field chi tiết thành nhóm generation backend.
   * =====================================================================
   * INPUT: key field từ candidate hoặc lựa chọn nhóm.
   * OUTPUT: nhóm content/seo/thumbnail tương ứng hoặc key gốc.
   * SIDE EFFECT: hàm thuần; taxonomy không có alias generation.
   * =====================================================================
   */
  const groupFor = field => {
    if (['content_html', 'description', 'documentation', 'lyrics'].includes(field)) return 'content'
    if (['focus_keyword', 'seo_title', 'seo_description', 'canonical_url', 'robots_index', 'robots_follow', 'og_title', 'og_description'].includes(field)) return 'seo'
    if (['thumbnail_prompt', 'thumbnail_alt_text', 'cover'].includes(field)) return 'thumbnail'

    return field
  }

  const payload = { fields: [...new Set((options.fields ?? []).map(groupFor))].filter(field => allowed.includes(field)) }

  if (options.fields?.length && !payload.fields.length)
    throw new Error('Chọn hạng mục AI được hỗ trợ để tạo lại; danh mục và tag được chọn thủ công.')

  for (const key of ['instructions', 'prompt_key', 'provider', 'model', 'model_id', 'thumbnail_mode', 'thumbnail_prompt', 'image_model_id']) {
    const value = options[key]
    if (value !== null && value !== undefined && value !== '') payload[key] = value
  }
  for (const key of ['writing_profile_id', 'writing_brief', 'category_ids', 'tag_ids', 'refresh_source']) {
    if (Object.hasOwn(options, key)) payload[key] = options[key]
  }

  return payload
}
