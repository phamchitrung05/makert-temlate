/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuẩn hóa candidate AI thành payload form Post an toàn.
 * =====================================================================
 *
 * Utility thuần xử lý canonical output/group field, không gọi API hoặc giữ
 * state. PostForm merge từng field được chọn để giữ nguyên dữ liệu còn lại.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - toPostPayload()/valueOf(): map output canonical sang PostForm payload.
 * - mergePostCandidate(): merge partial payload vào form state.
 * - overwrittenFields(): tìm field có dữ liệu cần xác nhận ghi đè.
 * - mergeAiLineage()/activeAiLineage()/lineageValue(): giữ provenance từng nhóm khi form còn cùng giá trị.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : canonical AI output, selected field keys và form hiện tại.
 * - OUTPUT: payload/field list; không mutate input hoặc ghi database.
 * =====================================================================
 */
/* eslint-disable camelcase -- AI canonical contract uses Laravel field keys. */

const seoMap = {
  focus_keyword: 'focusKeyword', seo_title: 'title', seo_description: 'description',
  canonical_url: 'canonicalUrl', robots_index: 'robotsIndex', robots_follow: 'robotsFollow',
  og_title: 'ogTitle', og_description: 'ogDescription',
}

/**
 * =====================================================================
 * CHỨC NĂNG: payload partial cho PostForm.
 * =====================================================================
 * INPUT: canonical output và field selection.
 * OUTPUT: payload partial cho PostForm.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
export function toPostPayload(output = {}, fields = []) {
  const payload = {}
  const seo = {}

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc giá trị canonical có hoặc không có envelope value.
   * =====================================================================
   * INPUT: key output trong candidate.
   * OUTPUT: giá trị field hoặc undefined khi không tồn tại.
   * SIDE EFFECT: hàm thuần; không suy ra taxonomy từ key suggested legacy.
   * =====================================================================
   */
  const valueOf = key => output[key]?.value ?? output[key]

  fields.forEach(key => {
    const value = valueOf(key)
    if (key === 'content' || key === 'content_html') payload.content = valueOf('content_html') ?? value
    else if (key === 'seo' && value && typeof value === 'object') Object.assign(seo, value)
    else if (key in seoMap) seo[seoMap[key]] = value
    else if (key === 'taxonomy') {
      if (valueOf('category_ids') !== undefined) payload.categories = valueOf('category_ids')
      if (valueOf('tag_ids') !== undefined) payload.tags = valueOf('tag_ids')
    }
    else if (key === 'category_ids' && value !== undefined) payload.categories = value
    else if (key === 'tag_ids' && value !== undefined) payload.tags = value
    else if (['title', 'excerpt'].includes(key)) payload[key] = value
    else if (key === 'thumbnail' && value?.asset) payload.thumbnail = value.asset
  })
  if (Object.keys(seo).length) payload.seo = seo

  return payload
}

/**
 * =====================================================================
 * CHỨC NĂNG: object form mới giữ field không chọn.
 * =====================================================================
 * INPUT: form hiện tại/payload partial.
 * OUTPUT: object form mới giữ field không chọn.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
export function mergePostCandidate(form, payload) {
  return {
    ...form,
    ...payload,
    seo: { ...form.seo, ...(payload.seo ?? {}) },
    categories: payload.categories ? [...payload.categories] : form.categories,
    tags: payload.tags ? [...payload.tags] : form.tags,
  }
}

/**
 * =====================================================================
 * CHỨC NĂNG: field labels có giá trị khác cần ghi đè.
 * =====================================================================
 * INPUT: form và payload partial.
 * OUTPUT: field labels có giá trị khác cần ghi đè.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
export function overwrittenFields(form, payload) {
  const fields = []

  Object.entries(payload).forEach(([key, value]) => {
    if (key === 'seo') {
      Object.entries(value).forEach(([seoKey, seoValue]) => {
        if (form.seo?.[seoKey] && form.seo[seoKey] !== seoValue) fields.push(`SEO ${seoKey}`)
      })
    }
    else if (form[key] && JSON.stringify(form[key]) !== JSON.stringify(value) && (!Array.isArray(form[key]) || form[key].length)) fields.push(key)
  })

  return fields
}

/**
 * =====================================================================
 * CHỨC NĂNG: Gộp lineage nhiều AI run mà không làm mất field trước đó
 * =====================================================================
 * INPUT: lineage hiện tại, lineage mới và payload vừa apply.
 * OUTPUT: danh sách run/field không overlap, kèm snapshot để loại bỏ khi sửa tay.
 * SIDE EFFECT: chỉ tạo object mới; không gọi API hoặc mutate form.
 * EXCEPTION/TRANSACTION: không mở transaction.
 * =====================================================================
 */
export function mergeAiLineage(current = [], incoming = null, payload = {}) {
  if (!incoming?.runId || !incoming.fields?.length)
    return Array.isArray(current) ? current : []

  const fields = [...new Set(incoming.fields)]

  const next = (Array.isArray(current) ? current : []).map(run => ({
    ...run, fields: (run.fields ?? []).filter(field => !fields.includes(field)),
    snapshot: { ...(run.snapshot ?? {}) },
  })).filter(run => run.fields.length)

  const own = next.find(run => run.runId === incoming.runId)
  const snapshot = Object.fromEntries(fields.map(field => [field, lineageValue(field, payload)]))

  if (own) {
    own.fields = [...new Set([...own.fields, ...fields])]
    Object.assign(own.snapshot, snapshot)
  } else next.push({ runId: incoming.runId, fields, snapshot })

  return next
}

/**
 * =====================================================================
 * CHỨC NĂNG: Giữ lineage của nhóm chưa thay đổi sau khi áp dụng.
 * =====================================================================
 * INPUT: lineage/snapshot và giá trị form hiện tại.
 * OUTPUT: danh sách run có nhóm vẫn khớp giá trị đã áp dụng.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
export function activeAiLineage(lineage = [], form = {}) {
  return (Array.isArray(lineage) ? lineage : []).map(run => ({
    run_id: run.runId,
    fields: (run.fields ?? []).filter(field => run.snapshot?.[field] === undefined
      || run.snapshot[field] === lineageValue(field, form)),
  })).filter(run => run.fields.length)
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tạo snapshot primitive cho một nhóm field.
 * =====================================================================
 * INPUT: nhóm canonical và form.
 * OUTPUT: giá trị primitive, không giữ tham chiếu object reactive.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
function lineageValue(field, form) {
  if (field === 'thumbnail') return form.thumbnail?.id ?? null
  if (field === 'taxonomy') return JSON.stringify([form.categories ?? [], form.tags ?? []])
  if (field === 'seo') return JSON.stringify(form.seo ?? {})

  return form[field]
}
