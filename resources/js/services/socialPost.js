/* eslint-disable camelcase -- Request keys follow the Laravel Social Post contract. */

/**
 * =====================================================================
 * CHỨC NĂNG FILE: API client cho Marketing Social Post.
 * =====================================================================
 * Service giữ response unwrap và gọi đúng lifecycle endpoint; caption dùng
 * chung cho mọi target, không tạo platform_caption ở frontend.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - unwrap(): lấy data từ BaseResponse.
 * - list()/show()/create()/update()/remove(): CRUD.
 * - generate()/regenerateCaption()/regenerateImage(): queue AI.
 * - submitReview()/approve()/reject()/requestRevision()/publish(): lifecycle.
 * - products()/campaigns()/accounts(): legacy options loading; Product is never
 *   included in Social Post create/update payloads.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : query/id/payload từ Vue.
 * - OUTPUT: JSON normalized hoặc lỗi do $api ném.
 * =====================================================================
 */
import { $api } from '@/utils/api'

/** Input: API response. Output: envelope data hoặc response phẳng. */
const unwrap = response => response?.success && 'data' in response ? response.data : response

/** Input: server SocialPost. Output: item ổn định cho component. */
const normalize = post => post ? ({
  ...post,
  generationStatus: post.generation_status ?? post.generationStatus,
  reviewStatus: post.review_status ?? post.reviewStatus,
  publicationStatus: post.publication_status ?? post.publicationStatus,
}) : null

export const socialPostService = {
  /** Input: filters/page. Output: items + pagination/stats. */
  async list(query = {}) {
    const response = await $api('/admin/social-posts', { query })

    return {
      items: (unwrap(response) ?? []).map(normalize),
      pagination: response?.meta?.pagination ?? null,
      stats: response?.meta?.stats ?? {},
    }
  },

  /** Input: post id. Output: normalized detail. */
  async show(id) { return normalize(unwrap(await $api(`/admin/social-posts/${id}`))) },

  /** Input: post id. Output: immutable version list. */
  async versions(id) { return unwrap(await $api(`/admin/social-posts/${id}/versions`)) },

  /** Input: post id. Output: platform variants with ordered images. */
  async variants(id) { return unwrap(await $api(`/admin/social-posts/${id}/variants`)) },

  /** Input: create payload. Output: `{ post, generation }`. */
  async create(payload) { return unwrap(await $api('/admin/social-posts', { method: 'POST', body: payload })) },

  /** Input: id/payload. Output: normalized post. */
  async update(id, payload) { return normalize(unwrap(await $api(`/admin/social-posts/${id}`, { method: 'PUT', body: payload }))) },

  /** Input: id. Output: HTTP delete response. */
  async remove(id) { return $api(`/admin/social-posts/${id}`, { method: 'DELETE' }) },

  /** Input: id/model options. Output: queued generation. */
  async generate(id, payload = {}) { return unwrap(await $api(`/admin/social-posts/${id}/generate`, { method: 'POST', body: payload })) },

  /** Input: id/model options. Output: queued caption job. */
  async regenerateCaption(id, payload = {}) { return unwrap(await $api(`/admin/social-posts/${id}/regenerate-caption`, { method: 'POST', body: payload })) },

  /** Input: id/model options. Output: queued image job. */
  async regenerateImage(id, payload = {}) { return unwrap(await $api(`/admin/social-posts/${id}/regenerate-image`, { method: 'POST', body: payload })) },

  /** Input: id/content. Output: normalized Facebook variant parent. */
  async updateFacebookContent(id, content) { return normalize(unwrap(await $api(`/admin/social-posts/${id}/variants/facebook/content`, { method: 'PUT', body: { content } }))) },

  /** Input: id/model options. Output: queued Facebook content jobs. */
  async generateFacebookContent(id, payload = {}) { return unwrap(await $api(`/admin/social-posts/${id}/variants/facebook/generate-content`, { method: 'POST', body: payload })) },

  /** Input: id/model options. Output: queued Facebook image slot jobs. */
  async generateFacebookImages(id, payload = {}) { return unwrap(await $api(`/admin/social-posts/${id}/variants/facebook/generate-images`, { method: 'POST', body: payload })) },

  async regenerateImageSlot(id, slot, payload = {}) { return unwrap(await $api(`/admin/social-posts/${id}/variants/facebook/images/${slot}/regenerate`, { method: 'POST', body: payload })) },

  /** Input: id/asset IDs. Output: normalized post with gallery. */
  async replaceFacebookImages(id, assetIds) { return normalize(unwrap(await $api(`/admin/social-posts/${id}/variants/facebook/images/replace`, { method: 'POST', body: { asset_ids: assetIds } }))) },

  /** Input: id/usage IDs. Output: normalized post with reordered gallery. */
  async reorderFacebookImages(id, usageIds) { return normalize(unwrap(await $api(`/admin/social-posts/${id}/variants/facebook/images/reorder`, { method: 'POST', body: { usage_ids: usageIds } }))) },

  /** Input: id. Output: pending_review post. */
  async submitReview(id) { return normalize(unwrap(await $api(`/admin/social-posts/${id}/submit-review`, { method: 'POST' }))) },

  /** Input: id. Output: approved post. */
  async approve(id) { return normalize(unwrap(await $api(`/admin/social-posts/${id}/approve`, { method: 'POST' }))) },

  /** Input: id/reason. Output: rejected post. */
  async reject(id, reason = '') { return normalize(unwrap(await $api(`/admin/social-posts/${id}/reject`, { method: 'POST', body: { reason } }))) },

  /** Input: id/reason. Output: revision_requested post. */
  async requestRevision(id, reason = '') { return normalize(unwrap(await $api(`/admin/social-posts/${id}/request-revision`, { method: 'POST', body: { reason } }))) },

  /** Input: id. Output: queued publications. */
  async publish(id) { return unwrap(await $api(`/admin/social-posts/${id}/publish`, { method: 'POST' })) },

  /** Input: options. Output: legacy product options for callers being migrated. */
  async products(query = {}) { return unwrap(await $api('/admin/marketing/products', { query })) },

  /** Input: options. Output: campaign list. */
  async campaigns(query = {}) { return unwrap(await $api('/admin/marketing/campaigns', { query })) },

  /** Input: none. Output: connected Page list. */
  async accounts() { return unwrap(await $api('/admin/social-accounts')) },

  /** Input: none. Output: OAuth URL. */
  async connectFacebook() { return unwrap(await $api('/admin/social-accounts/facebook/connect', { method: 'POST' })) },
}
