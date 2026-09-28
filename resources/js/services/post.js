/** API client cho Post và media fields riêng của Post. */
import { $api } from '@/utils/api'

const unwrap = response => response?.success && 'data' in response ? response.data : response

const normalize = post => post ? {
  ...post,
  media: {
    thumbnail: post.media?.thumbnail ?? null,
    'content_images': Array.isArray(post.media?.content_images) ? post.media.content_images : [],
  },
} : null

const toPayload = payload => ({
  title: payload.title,
  content: payload.content,
  status: payload.status,
  media: {
    'thumbnail_id': payload.thumbnail?.id ?? null,
    'content_image_ids': Array.isArray(payload.contentImages)
      ? payload.contentImages.map(asset => asset.id).filter(Boolean)
      : [],
  },
})

export const postService = {
  async list(params = {}) {
    const response = await $api('/admin/posts', { query: params })
    const payload = unwrap(response)

    return {
      items: Array.isArray(payload) ? payload : payload?.items ?? [],
      itemsLength: response?.meta?.pagination?.total ?? (Array.isArray(payload) ? payload.length : 0),
      pagination: response?.meta?.pagination ?? null,
    }
  },
  async show(id) {
    return normalize(unwrap(await $api(`/admin/posts/${id}`)))
  },
  async create(payload) {
    return normalize(unwrap(await $api('/admin/posts', { method: 'POST', body: toPayload(payload) })))
  },
  async update(id, payload) {
    return normalize(unwrap(await $api(`/admin/posts/${id}`, { method: 'PUT', body: toPayload(payload) })))
  },
  async remove(id) {
    return $api(`/admin/posts/${id}`, { method: 'DELETE' })
  },
}
