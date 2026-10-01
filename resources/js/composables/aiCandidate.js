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

/** Input: canonical output và field selection. Output: payload partial cho PostForm. */
export function toPostPayload(output = {}, fields = []) {
  const payload = {}
  const seo = {}
  const valueOf = key => output[key]?.value ?? output[key]

  fields.forEach(key => {
    const value = valueOf(key)
    if (key === 'content' || key === 'content_html') payload.content = valueOf('content_html') ?? value
    else if (key === 'seo' && value && typeof value === 'object') Object.assign(seo, value)
    else if (key in seoMap) seo[seoMap[key]] = value
    else if (key === 'taxonomy') {
      payload.categories = valueOf('category_ids') ?? valueOf('suggested_category_ids') ?? []
      payload.tags = valueOf('tag_ids') ?? valueOf('suggested_tag_ids') ?? []
    }
    else if (['category_ids', 'suggested_category_ids'].includes(key)) payload.categories = value
    else if (['tag_ids', 'suggested_tag_ids'].includes(key)) payload.tags = value
    else if (['title', 'excerpt'].includes(key)) payload[key] = value
    else if (key === 'thumbnail' && value?.asset) payload.thumbnail = value.asset
  })
  if (Object.keys(seo).length) payload.seo = seo

  return payload
}

/** Input: form hiện tại/payload partial. Output: object form mới giữ field không chọn. */
export function mergePostCandidate(form, payload) {
  return {
    ...form,
    ...payload,
    seo: { ...form.seo, ...(payload.seo ?? {}) },
    categories: payload.categories ? [...payload.categories] : form.categories,
    tags: payload.tags ? [...payload.tags] : form.tags,
  }
}

/** Input: form và payload partial. Output: field labels có giá trị khác cần ghi đè. */
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
