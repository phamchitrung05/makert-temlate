/* eslint-disable camelcase -- Payload dùng contract Laravel AI Agent. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuẩn hóa và kiểm tra nguồn của form tạo bài AI.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: createAiContentSource(), validateAiContentSource(),
 * extractHtmlText(), buildAiContentRequest(), buildAiContentRegenerateRequest().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : URL/file HTML/text/đề bài và model catalog đã chọn.
 * - OUTPUT: lý do chưa thể tạo hoặc payload API; đọc file cục bộ khi gửi.
 * - SIDE EFFECT: không execute HTML, fetch URL hoặc gọi provider.
 * =====================================================================
 */
const maxTextLength = 200000
const maxFileBytes = 5 * 1024 * 1024

/** Input: nhóm đầu ra mặc định từ catalog. Output: nguồn mới; null chờ catalog khởi tạo lựa chọn. */
export const createAiContentSource = (defaults = {}) => ({
  targetType: 'post', type: 'url', url: '', text: '', prompt: '', file: null,
  provider: '', model: '', language: 'vi', length: 'medium',
  outputs: Array.isArray(defaults.outputs) ? [...defaults.outputs] : null,
  title: '',
})

/** Input: nguồn/catalog. Output: thông báo validation hoặc chuỗi rỗng; hàm thuần. */
export function validateAiContentSource(source, catalog) {
  if (catalog.loading) return 'Đang tải provider và model…'
  if (catalog.error) return 'Không tải được model. Hãy tải lại catalog.'
  if (catalog.targetOptions && !catalog.targetOptions.some(target => target.value === source.targetType))
    return 'Chọn tài nguyên được phép tạo nội dung AI.'
  if (!source.provider || !catalog.selectedModel) return 'Chọn provider và model để viết bài.'
  if (!catalog.selectedModel.capabilities?.includes('text_generation'))
    return 'Model này không hỗ trợ viết nội dung. Chọn model có khả năng Text generation trong AI Settings.'
  const availableOutputs = (catalog.outputOptions ?? []).filter(option => !option.props?.disabled).map(option => option.value)
  const outputs = source.outputs ?? []

  if (!outputs.length) return 'Chọn ít nhất một hạng mục AI sẽ tạo.'
  if (outputs.some(output => !availableOutputs.includes(output))) return 'Chọn các hạng mục AI được hỗ trợ cho tài nguyên và nguồn này.'
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
  else if (['text', 'prompt'].includes(source.type)) {
    const text = source[source.type].trim()
    if (!text) return source.type === 'prompt' ? 'Nhập yêu cầu viết bài.' : 'Nhập nội dung nguồn.'
    if (text.length > maxTextLength) return 'Nguồn không được dài quá 200.000 ký tự.'
  }
  else return 'Chọn loại nguồn được hỗ trợ.'

  return ''
}

/** Input: HTML cục bộ. Output: text bài viết; DOM tách rời, không execute script hoặc chèn vào page. */
export function extractHtmlText(html) {
  const template = document.createElement('template')

  template.innerHTML = html
  template.content.querySelectorAll('script, style, noscript, template, iframe, object, svg, nav, footer').forEach(node => node.remove())

  const article = template.content.querySelector('article, main') ?? template.content

  article.querySelectorAll('p, div, section, h1, h2, h3, h4, li, br, pre').forEach(node => node.append('\n'))

  return (article.textContent ?? '').replace(/[^\S\n]+/g, ' ').replace(/ *\n */g, '\n').replace(/\n{3,}/g, '\n\n').trim()
}

/** Input: nguồn và model đã validate. Output: payload Post create; đọc file, throw nếu nội dung rỗng/quá dài. */
export async function buildAiContentRequest(source, model) {
  const outputs = [...new Set(source.outputs ?? [])].filter(output => output !== 'thumbnail' || source.type === 'url')
  let input
  if (source.type === 'url') input = { type: 'url', url: source.url.trim() }
  else {
    const file = Array.isArray(source.file) ? source.file[0] : source.file
    const text = source.type === 'file' ? extractHtmlText(await file.text()) : source[source.type].trim()
    if (!text) throw new Error('Không tìm thấy nội dung trong file HTML.')
    if (text.length > maxTextLength) throw new Error('Nội dung nguồn không được dài quá 200.000 ký tự.')
    input = { type: 'text', text }
  }
  const lengths = { short: '300–500 từ', medium: '700–1.000 từ', long: '1.500–2.000 từ' }

  const instructions = [
    `Viết bài bằng ${source.language === 'en' ? 'tiếng Anh' : 'tiếng Việt'}, độ dài khoảng ${lengths[source.length] ?? lengths.medium}.`,
    source.type === 'prompt' ? 'Đầu vào là đề bài và yêu cầu viết, hãy phát triển thành một bài viết mới hoàn chỉnh.' : 'Bám sát nguồn, không bịa thông tin.',
    outputs.includes('title') ? 'Tạo tiêu đề phù hợp với bài viết.' : `Dùng chính xác tiêu đề: ${source.title.trim()}`,
    outputs.includes('seo') ? 'Tối ưu tiêu đề, mô tả SEO và cấu trúc heading tự nhiên.' : '',
  ].filter(Boolean).join('\n')

  if (!outputs.includes('title')) input.title = source.title.trim()

  return {
    target_type: source.targetType || 'post', operation: 'create', input, output_language: source.language,
    provider: source.provider, model: source.model, model_id: model.id, instructions,
    requested_outputs: outputs,
    generate_seo: outputs.includes('seo'),
    generate_thumbnail: outputs.includes('thumbnail') && source.type === 'url', thumbnail_mode: 'source',
  }
}

/** Input: nhóm field và override tùy chọn. Output: contract regenerate, bỏ các override trống; không chứa input create. */
export function buildAiContentRegenerateRequest(options = {}) {
  const allowed = ['title', 'excerpt', 'content', 'seo', 'taxonomy', 'thumbnail']

  const groupFor = field => {
    if (['content_html', 'description', 'documentation', 'lyrics'].includes(field)) return 'content'
    if (['focus_keyword', 'seo_title', 'seo_description', 'canonical_url', 'robots_index', 'robots_follow', 'og_title', 'og_description'].includes(field)) return 'seo'
    if (['suggested_category_ids', 'suggested_tag_ids', 'category_ids', 'tag_ids'].includes(field)) return 'taxonomy'
    if (['thumbnail_prompt', 'thumbnail_alt_text', 'cover'].includes(field)) return 'thumbnail'

    return field
  }

  const payload = { fields: [...new Set((options.fields ?? []).map(groupFor))].filter(field => allowed.includes(field)) }

  for (const key of ['instructions', 'prompt_key', 'provider', 'model', 'model_id']) {
    const value = options[key]
    if (value !== null && value !== undefined && value !== '') payload[key] = value
  }

  return payload
}
