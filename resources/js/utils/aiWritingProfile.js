/* eslint-disable camelcase -- Rules và payload theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuẩn hóa nguồn, form và báo cáo văn phong bằng hàm thuần.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: referenceText(), emptyProfileForm(), resultToForm(),
 * profileToForm(), profilePayload(), validateProfileForm(), analysisReport().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : HTML/text, result/profile DTO và form người dùng.
 * - OUTPUT: text giữ ranh giới đoạn, payload whitelist, lỗi field, Markdown.
 * - SIDE EFFECT: DOM tách rời; không render HTML, gọi model hoặc ghi DB.
 * =====================================================================
 */
export const writingRuleLabels = {
  tone: 'Giọng điệu', pronouns: 'Cách xưng hô', emotion: 'Sắc thái cảm xúc', opening: 'Mở bài',
  sentence_rhythm: 'Nhịp câu', paragraph_rhythm: 'Nhịp đoạn', transitions: 'Chuyển ý', vocabulary: 'Từ vựng',
  technical_terms: 'Thuật ngữ', structure_patterns: 'Cách tổ chức ý', headings: 'Tiêu đề', bullets: 'Danh sách',
  examples: 'Ví dụ', ending: 'Kết bài', avoid: 'Điều cần tránh', uncertainties: 'Điểm chưa chắc chắn',
}

/**
 * =====================================================================
 * CHỨC NĂNG: Đổi HTML thành bài tham khảo text, không nối liền các block.
 * Input: HTML không tin cậy. Output: text sạch giữ xuống dòng/code.
 * SIDE EFFECT: chỉ DOM tách rời; script và nguồn nhúng không được thực thi.
 * =====================================================================
 */
export function referenceText(html) {
  const parsed = new DOMParser().parseFromString(String(html ?? ''), 'text/html')

  parsed.querySelectorAll('script, style, template, noscript, iframe, nav, footer').forEach(element => element.remove())
  parsed.querySelectorAll('p, h1, h2, h3, h4, h5, h6, li, pre, blockquote, div, tr, br').forEach(element => {
    element.before(parsed.createTextNode('\n'))
    element.after(parsed.createTextNode('\n'))
  })

  return (parsed.body.textContent ?? '').replace(/\r\n?/gu, '\n').trim()
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo form trống cho lần phân tích mới.
 * Input: không có. Output: object mới, không dùng chung array giữa các form.
 * =====================================================================
 */
export const emptyProfileForm = () => ({ name: '', description: '', rules_json: {}, evidence_json: [], style_instructions: '', is_enabled: true, setAsDefault: false })

/**
 * =====================================================================
 * CHỨC NĂNG: Chép result đã validate sang bản người dùng duyệt.
 * Input: result và tên analysis. Output: form độc lập, không mutate result.
 * =====================================================================
 */
export function resultToForm(result, name) {
  return { ...emptyProfileForm(), name, rules_json: structuredClone(result.rules ?? {}), evidence_json: structuredClone(result.evidence ?? []), style_instructions: result.style_instructions ?? '' }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Khôi phục form từ profile mới nhất sau 409/reload.
 * Input: profile và default ID. Output: form độc lập để sửa version mới.
 * =====================================================================
 */
export function profileToForm(profile, defaultId) {
  return { ...emptyProfileForm(), name: profile.name, description: profile.description ?? '', rules_json: structuredClone(profile.rules_json ?? {}), evidence_json: structuredClone(profile.evidence_json ?? []), style_instructions: profile.style_instructions, is_enabled: profile.is_enabled, setAsDefault: profile.id === defaultId }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Chỉ gửi field nghiệp vụ, phân biệt create với versioned update.
 * Input: form, analysis ID và profile đã lưu. Output: payload API whitelist.
 * =====================================================================
 */
export function profilePayload(form, analysisId, savedProfile) {
  const rules = Object.fromEntries(Object.entries(form.rules_json)
    .map(([key, value]) => [key, Array.isArray(value) ? value.map(item => String(item).trim()).filter(Boolean) : String(value).trim()])
    .filter(([key, value]) => key in writingRuleLabels && value.length))

  return {
    name: form.name.trim(), description: form.description.trim() || null,
    rules_json: rules, evidence_json: structuredClone(form.evidence_json),
    style_instructions: form.style_instructions.trim(), is_enabled: form.is_enabled,
    ...(savedProfile ? { version: savedProfile.version } : { analysis_id: analysisId }),
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: Kiểm field cơ bản trước thao tác lưu, server vẫn validate đầy đủ.
 * Input: form. Output: errors theo field; không cắt chuỗi vượt giới hạn.
 * =====================================================================
 */
export function validateProfileForm(form) {
  const errors = {}
  const length = value => Array.from(String(value ?? '')).length

  if (!form.name.trim() || length(form.name.trim()) > 160) errors.name = ['Nhập tên văn phong, tối đa 160 ký tự.']
  if (length(form.description) > 2000) errors.description = ['Mô tả tối đa 2000 ký tự.']
  if (!form.style_instructions.trim() || length(form.style_instructions) > 10000) errors.style_instructions = ['Nhập hướng dẫn văn phong, tối đa 10000 ký tự.']
  if (!Object.values(form.rules_json).some(value => Array.isArray(value) ? value.some(item => String(item).trim()) : String(value).trim())) errors.rules_json = ['Cần ít nhất một quy tắc văn phong.']
  for (const [key, value] of Object.entries(form.rules_json)) {
    const items = Array.isArray(value) ? value.filter(item => String(item).trim()) : []

    if (Array.isArray(value) ? items.length > 20 || items.some(item => length(item.trim()) > 2000) : length(value) > 2000) errors[`rules_json.${key}`] = ['Quy tắc tối đa 2000 ký tự; danh sách tối đa 20 mục.']
  }

  return errors
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo báo cáo từ analysis thật và bản hướng dẫn đã sửa.
 * Input: analysis/form/profile. Output: Markdown UTF-8, không kèm bài nguồn.
 * =====================================================================
 */
export function analysisReport(analysis, form, savedProfile) {
  const rules = Object.entries(form.rules_json).map(([key, value]) => `- **${writingRuleLabels[key] ?? key}:** ${Array.isArray(value) ? value.join('; ') : value}`)
  const evidence = form.evidence_json.map(item => `> ${item.excerpt}\n\n${item.explanation}`)

  return [`# ${form.name || analysis.name}`, `Model: ${analysis.provider ?? ''} / ${analysis.model ?? ''}`, `Phân tích: ${analysis.completed_at ?? analysis.created_at}`, savedProfile ? `Đã lưu mẫu #${savedProfile.id}, version ${savedProfile.version}; nội dung báo cáo là bản đang xem.` : 'Bản đang xem/sửa, chưa lưu thành mẫu.', '## Tóm tắt văn phong', analysis.result?.summary ?? '', '## Quy tắc', ...rules, '## Dẫn chứng', ...evidence, '## Hướng dẫn văn phong', form.style_instructions].join('\n\n')
}
