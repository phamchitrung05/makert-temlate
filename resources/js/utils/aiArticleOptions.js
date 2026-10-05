/* eslint-disable camelcase -- Field theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuẩn hóa brief, nguồn nguyên bản và override khi tạo bài.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: emptyWritingPreferences(), writingOptions(), articleSourcePayload(),
 * appendFormValue(), articleRequestBody(), pipelineStepLabel(), pipelineProgress().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): lựa chọn người dùng -> payload JSON/FormData
 * và nhãn public; không extract HTML bằng trình duyệt hoặc tự gọi model.
 * =====================================================================
 */
export const briefFields = Object.freeze({ audience: 'Độc giả', article_type: 'Dạng bài', purpose: 'Mục đích', angle: 'Góc tiếp cận', length: 'Độ dài mong muốn' })

/**
 * =====================================================================
 * Input: chế độ create/regenerate. Output: lựa chọn mới; regenerate mặc định kế thừa.
 * =====================================================================
 */
export const emptyWritingPreferences = (regenerate = false) => ({ profile: regenerate ? 'inherit' : 'default', brief: {}, overrideBrief: !regenerate })

/**
 * =====================================================================
 * Input: preferences và regenerate. Output: whitelist; null = mặc định website,
 * thiếu field = kế thừa snapshot parent; brief rỗng có chủ ý xóa brief parent.
 * =====================================================================
 */
export function writingOptions(preferences, regenerate = false) {
  const value = preferences ?? emptyWritingPreferences(regenerate)
  const payload = {}
  if (!regenerate || value.profile !== 'inherit') payload.writing_profile_id = value.profile === 'default' ? null : Number(value.profile)
  if (!regenerate || value.overrideBrief) payload.writing_brief = Object.fromEntries(Object.keys(briefFields)
    .map(key => [key, String(value.brief?.[key] ?? '').trim()]).filter(([, text]) => text))

  return payload
}

/**
 * =====================================================================
 * Input: source UI (type/url/text/html/file/prompt), target. Output: đúng một nguồn
 * JSON hoặc File gốc; giữ encoding/code/table/link để backend extract.
 * =====================================================================
 */
export function articleSourcePayload(source, targetType = 'post') {
  const type = source.type ?? source.inputType
  const value = source.inputValue ?? source[type] ?? ''
  const payload = { target_type: source.targetType || targetType }
  if (type === 'url') payload.url = String(value || source.url || '').trim()
  else if (type === 'file') {
    const file = Array.isArray(source.file) ? source.file[0] : source.file
    if (!file) throw new Error('Chọn tệp HTML để đọc nội dung.')
    payload.html_file = file
    payload.source_encoding = source.sourceEncoding || 'UTF-8'
  }
  else if (type === 'html') {
    payload.html = String(value).trim()
    payload.source_encoding = source.sourceEncoding || 'UTF-8'
  }
  else payload.text = String(value).trim()

  return payload
}

/**
 * =====================================================================
 * Input: FormData/key/value. Output: thêm field Laravel dạng ngoặc; bool 1/0,
 * File giữ bytes gốc. Array rỗng không cần gửi trong create (mặc định [] backend).
 * =====================================================================
 */
function appendFormValue(form, key, value) {
  if (value === null || value === undefined) return
  if (value instanceof Blob) form.append(key, value)
  else if (typeof value === 'object') Object.entries(value).forEach(([child, item]) => appendFormValue(form, `${key}[${child}]`, item))
  else form.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value))
}

/**
 * =====================================================================
 * Input: payload có thể có html_file. Output: JSON hoặc FormData; không tự đặt
 * Content-Type/boundary và không đọc rồi chuyển file thành plain text.
 * =====================================================================
 */
export function articleRequestBody(payload) {
  if (!payload.html_file) return payload
  const form = new FormData()

  Object.entries(payload).forEach(([key, value]) => appendFormValue(form, key, value))

  return form
}

export const pipelineStages = Object.freeze({
  queued: 'Chờ worker', fetching: 'Đọc nguồn', extracting: 'Trích xuất nguồn',
  analyzing: 'Phân tích & lập dàn ý', planning: 'Lập dàn ý', writing: 'Viết bài',
  editing: 'Biên tập', validating: 'Kiểm tra kết quả', rewriting: 'Viết nội dung',
  seo: 'Kiểm tra SEO', thumbnail: 'Chuẩn bị ảnh', ready: 'Sẵn sàng duyệt',
  failed: 'Thất bại', cancelled: 'Đã hủy', expired: 'Đã hết hạn',
  'article.analysis-plan': 'Phân tích & lập dàn ý', 'article.writer': 'Viết bài', 'article.editor': 'Biên tập',
})

/**
 * =====================================================================
 * Input: key bước public. Output: nhãn tiếng Việt, không suy diễn thời gian/token.
 * =====================================================================
 */
export const pipelineStepLabel = key => pipelineStages[key] ?? key ?? 'Đang chuẩn bị'

/**
 * =====================================================================
 * Input: session/polling. Output: tiến độ cho card Post, tương thích nhánh cũ/ngắn.
 * =====================================================================
 */
export function pipelineProgress(session, polling) {
  const current = session?.current_step || session?.status || 'queued'
  const order = ['queued', 'fetching', 'extracting', 'analyzing', 'writing', 'editing', 'validating', 'ready']
  if (['rewriting', 'seo', 'thumbnail'].includes(current)) order.splice(3, 4, 'rewriting', 'seo', 'thumbnail')
  const index = order.indexOf(current === 'planning' ? 'analyzing' : current)

  return order.map((key, position) => ({
    id: position + 1, title: pipelineStepLabel(key), subtitle: key === 'analyzing' ? 'Hiểu nguồn và chọn cấu trúc phù hợp' : '',
    status: session?.status === 'ready' || (index >= 0 && position < index) ? 'done' : position === index && polling ? 'processing' : 'pending',
  }))
}
