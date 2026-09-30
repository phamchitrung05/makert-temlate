/**
 * =====================================================================
 * CHỨC NĂNG FILE: API client Post với media và metadata SEO; slug qua Pinia store riêng.
 * CÁC HÀM/METHOD TRONG FILE: unwrap(), normalize(), toPayload(),
 * list(), show(), create(), update(), remove().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): state form/ID/query -> payload API hoặc Post.
 * =====================================================================
 */
import { $api } from '@/utils/api'

/** Input: response API. Output: data đã bỏ envelope. */
const unwrap = response => response?.success && 'data' in response ? response.data : response

/** Input: Post có thể thiếu media. Output: shape ổn định cho form. */
const normalize = post => post ? {
  ...post,
  media: {
    thumbnail: post.media?.thumbnail ?? null,
    'content_images': Array.isArray(post.media?.content_images) ? post.media.content_images : [],
  },
  categories: Array.isArray(post.categories) ? post.categories : [],
  tags: Array.isArray(post.tags) ? post.tags : [],
} : null

/** Input: form state. Output: whitelist thuộc tính backend, không gửi score/slug preview. */
const toPayload = payload => ({
  title: payload.title,
  content: payload.content,
  status: payload.status,
  excerpt: payload.excerpt,
  'focus_keyword': payload.seo?.focusKeyword,
  'seo_title': payload.seo?.title,
  'seo_description': payload.seo?.description,
  'canonical_url': payload.seo?.canonicalUrl,
  'robots_index': payload.seo?.robotsIndex,
  'robots_follow': payload.seo?.robotsFollow,
  'og_title': payload.seo?.ogTitle,
  'og_description': payload.seo?.ogDescription,
  ['category_ids']: Array.isArray(payload.categories)
    ? payload.categories.map(category => typeof category === 'object' ? category.id : category).filter(Boolean)
    : [],
  ['tag_ids']: Array.isArray(payload.tags)
    ? payload.tags.map(tag => typeof tag === 'object' ? tag.id : tag).filter(Boolean)
    : [],
  media: {
    'thumbnail_id': payload.thumbnail?.id ?? null,
    'content_image_ids': Array.isArray(payload.contentImages)
      ? payload.contentImages.map(asset => asset.id).filter(Boolean)
      : [],
  },
})

export const postService = {
  /** Input: URL/options. Output: structured draft returned by AI import API. */
  async aiImport(payload) {
    return unwrap(await $api('/admin/posts/ai/import', { method: 'POST', body: payload }))
  },

  /** Input: query phân trang. Output: items và tổng số bài. */
  async list(params = {}) {
    const response = await $api('/admin/posts', { query: params })
    const payload = unwrap(response)

    return {
      items: Array.isArray(payload) ? payload : payload?.items ?? [],
      itemsLength: response?.meta?.pagination?.total ?? (Array.isArray(payload) ? payload.length : 0),
      pagination: response?.meta?.pagination ?? null,
    }
  },

  /** Input: ID. Output: Post chuẩn hóa; lỗi API truyền về caller. */
  async show(id) {
    return normalize(unwrap(await $api(`/admin/posts/${id}`)))
  },

  /** Input: form. Output: Post đã lưu cùng slug thực tế. */
  async create(payload) {
    return normalize(unwrap(await $api('/admin/posts', { method: 'POST', body: toPayload(payload) })))
  },

  /** Input: ID/form. Output: Post sau cập nhật. */
  async update(id, payload) {
    return normalize(unwrap(await $api(`/admin/posts/${id}`, { method: 'PUT', body: toPayload(payload) })))
  },

  /** Input: ID. Output: request xóa Post, backend quản lý detach media. */
  async remove(id) {
    return $api(`/admin/posts/${id}`, { method: 'DELETE' })
  },

  /** Input: taxonomy type và query. Output: danh sách item taxonomy chuẩn hóa. */
  async taxonomy(type, params = {}) {
    const response = await $api(`/admin/${type}`, { query: { ['per_page']: 100, ...params } })
    const payload = unwrap(response)
    const items = Array.isArray(payload) ? payload : payload?.items ?? []

    return items.map(item => ({ id: item.id, name: item.name }))
  },
}
