/**
 * =====================================================================
 * CHỨC NĂNG FILE: API client Post với media và metadata SEO; slug qua Pinia store riêng.
 * CÁC HÀM/METHOD TRONG FILE: unwrap(), normalize(), toPayload(),
 * list(), authors(), show(), create(), update(), submitReview(), publish(),
 * reject(), archive(), remove().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): state form/ID/query -> payload API hoặc Post.
 * AI lineage nhiều run được gửi dưới dạng ai_runs; khóa legacy vẫn được giữ cho
 * client cũ. Provider/model chỉ do backend resolve.
 * =====================================================================
 */
import { $api } from '@/utils/api'
/* eslint-disable camelcase -- Request fields follow the Laravel API contract. */

/**
 * =====================================================================
 * CHỨC NĂNG: Bỏ envelope response Post
 * =====================================================================
 * INPUT: Response API dạng success/data hoặc payload trực tiếp.
 * OUTPUT: Data bên trong envelope.
 * SIDE EFFECT: Hàm thuần; không gọi API hoặc thay đổi input.
 * EXCEPTION/TRANSACTION: Không tự xử lý lỗi API.
 * =====================================================================
 */
const unwrap = response => response?.success && 'data' in response ? response.data : response

/**
 * =====================================================================
 * CHỨC NĂNG: Chuẩn hóa Post cho form kể cả khi thiếu media/taxonomy
 * =====================================================================
 * INPUT: Post nullable từ API.
 * OUTPUT: Shape có thumbnail/gallery_images/categories/tags ổn định; content giữ HTML/link.
 * SIDE EFFECT: Tạo object mới; không mutate Post đầu vào.
 * EXCEPTION/TRANSACTION: Không gọi API hoặc mở transaction.
 * =====================================================================
 */
const normalize = post => post ? {
  ...post,
  media: {
    thumbnail: post.media?.thumbnail ?? null,
    'gallery_images': Array.isArray(post.media?.gallery_images) ? post.media.gallery_images : [],
  },
  categories: Array.isArray(post.categories) ? post.categories : [],
  tags: Array.isArray(post.tags) ? post.tags : [],
} : null

/**
 * =====================================================================
 * CHỨC NĂNG: Chuyển form sang payload whitelist của Post API
 * =====================================================================
 * INPUT: Form state gồm SEO, media và lineage các run AI.
 * OUTPUT: Payload backend; không gửi score/slug preview hoặc provider identity.
 * SIDE EFFECT: Hàm thuần; sao chép các mảng field lineage.
 * EXCEPTION/TRANSACTION: Không gọi API; validation do backend đảm nhiệm.
 * =====================================================================
 */
const toPayload = (payload, includeStatus = true) => ({
  title: payload.title,
  content: payload.content,
  ...(includeStatus ? { status: payload.status } : {}),
  excerpt: payload.excerpt,
  'focus_keyword': payload.seo?.focusKeyword,
  'seo_title': payload.seo?.title,
  'seo_description': payload.seo?.description,
  'canonical_url': payload.seo?.canonicalUrl,
  'robots_index': payload.seo?.robotsIndex,
  'robots_follow': payload.seo?.robotsFollow,
  'og_title': payload.seo?.ogTitle,
  'og_description': payload.seo?.ogDescription,
  'og_image_id': payload.seo?.ogImage?.id ?? payload.seo?.ogImageId ?? null,
  ['category_ids']: Array.isArray(payload.categories)
    ? payload.categories.map(category => typeof category === 'object' ? category.id : category).filter(Boolean)
    : [],
  ['tag_ids']: Array.isArray(payload.tags)
    ? payload.tags.map(tag => typeof tag === 'object' ? tag.id : tag).filter(Boolean)
    : [],
  media: {
    'thumbnail_id': payload.thumbnail?.id ?? null,
    'gallery_image_ids': Array.isArray(payload.galleryImages)
      ? payload.galleryImages.map(asset => asset.id).filter(Boolean)
      : [],
  },
  ...(payload.aiProvenance?.runId ? {
    'ai_run_id': payload.aiProvenance.runId,
    'ai_fields': Array.isArray(payload.aiProvenance.fields) ? [...payload.aiProvenance.fields] : [],
  } : {}),
  ...(Array.isArray(payload.aiRuns) && payload.aiRuns.length ? {
    'ai_runs': payload.aiRuns.map(run => ({ run_id: run.run_id, fields: [...(run.fields ?? [])] })),
  } : {}),
})

export const postService = {
  /**
   * =====================================================================
   * CHỨC NĂNG: Xếp hàng tạo nội dung bằng AI
   * =====================================================================
   * INPUT: URL/text và options provider/model/prompt.
   * OUTPUT: Job queued hoặc run trùng idempotent để tiếp tục polling.
   * SIDE EFFECT: Gọi POST Admin API; backend ghi run và dispatch job.
   * EXCEPTION/TRANSACTION: Lỗi API truyền lên caller; không gọi provider từ browser.
   * =====================================================================
   */
  async aiImport(payload) {
    return unwrap(await $api('/admin/posts/ai/import', { method: 'POST', body: payload }))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc tiến trình của AI import
   * =====================================================================
   * INPUT: Job UUID thuộc admin hiện tại.
   * OUTPUT: Status, progress và result khi ready.
   * SIDE EFFECT: Gọi GET Admin API; không ghi Post.
   * EXCEPTION/TRANSACTION: Lỗi API truyền lên caller.
   * =====================================================================
   */
  async aiImportStatus(jobId) {
    return unwrap(await $api(`/admin/posts/ai/import/${jobId}`))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Tải danh sách Post có phân trang
   * =====================================================================
   * INPUT: Query filters và pagination tùy chọn.
   * OUTPUT: Items, tổng số và pagination.
   * SIDE EFFECT: Gọi GET Admin API.
   * EXCEPTION/TRANSACTION: Lỗi API truyền lên caller.
   * =====================================================================
   */
  async list(params = {}) {
    const response = await $api('/admin/posts', { query: params })
    const payload = unwrap(response)

    return {
      items: Array.isArray(payload) ? payload : payload?.items ?? [],
      itemsLength: response?.meta?.pagination?.total ?? (Array.isArray(payload) ? payload.length : 0),
      pagination: response?.meta?.pagination ?? null,
    }
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Tải danh sách tác giả đang có Post
   * =====================================================================
   * INPUT: Không có; backend tự lấy các User đang xuất hiện ở Post.
   * OUTPUT: Danh sách { id, name, email } cho bộ lọc tác giả.
   * SIDE EFFECT: Gọi GET Admin API; không ghi dữ liệu.
   * EXCEPTION/TRANSACTION: Lỗi API truyền lên caller.
   * =====================================================================
   */
  async authors() {
    const response = await $api('/admin/posts/authors')
    const payload = unwrap(response)

    return Array.isArray(payload) ? payload : payload?.items ?? []
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Tải một Post và chuẩn hóa dữ liệu form
   * =====================================================================
   * INPUT: Post ID.
   * OUTPUT: Post đã normalize.
   * SIDE EFFECT: Gọi GET Admin API.
   * EXCEPTION/TRANSACTION: Lỗi API truyền lên caller.
   * =====================================================================
   */
  async show(id) {
    return normalize(unwrap(await $api(`/admin/posts/${id}`)))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Lưu Post mới từ form đã chọn
   * =====================================================================
   * INPUT: Form gồm nội dung/media/SEO và AI lineage.
   * OUTPUT: Post đã lưu, gồm slug/backend metadata.
   * SIDE EFFECT: Gọi POST Admin API; backend quản lý transaction và provenance.
   * EXCEPTION/TRANSACTION: Lỗi validation/permission/API truyền lên caller.
   * =====================================================================
   */
  async create(payload) {
    return normalize(unwrap(await $api('/admin/posts', { method: 'POST', body: toPayload(payload) })))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Lưu thay đổi Post bằng payload whitelist
   * =====================================================================
   * INPUT: Post ID và form state.
   * OUTPUT: Post sau cập nhật đã normalize.
   * SIDE EFFECT: Gọi PUT Admin API; backend quản lý transaction và provenance.
   * EXCEPTION/TRANSACTION: Lỗi validation/permission/API truyền lên caller.
   * =====================================================================
   */
  async update(id, payload) {
    return normalize(unwrap(await $api(`/admin/posts/${id}`, { method: 'PUT', body: toPayload(payload, false) })))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Gửi Post vào hàng chờ review
   * =====================================================================
   * INPUT: Post ID.
   * OUTPUT: Post pending_review đã normalize.
   * SIDE EFFECT: Gọi POST lifecycle API; backend ghi audit và kiểm permission.
   * EXCEPTION/TRANSACTION: Lỗi API truyền lên caller.
   * =====================================================================
   */
  async submitReview(id) {
    return normalize(unwrap(await $api(`/admin/posts/${id}/submit-review`, { method: 'POST' })))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Publish Post qua endpoint lifecycle
   * =====================================================================
   * INPUT: Post ID.
   * OUTPUT: Post published có published_at từ server.
   * SIDE EFFECT: Gọi POST lifecycle API; không tự đặt thời gian phía client.
   * EXCEPTION/TRANSACTION: Lỗi API truyền lên caller.
   * =====================================================================
   */
  async publish(id) {
    return normalize(unwrap(await $api(`/admin/posts/${id}/publish`, { method: 'POST' })))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Từ chối Post đang chờ review
   * =====================================================================
   * INPUT: Post ID và reason.
   * OUTPUT: Post rejected đã normalize.
   * SIDE EFFECT: Gọi POST lifecycle API; backend lưu reason vào audit.
   * EXCEPTION/TRANSACTION: Lỗi validation/permission/API truyền lên caller.
   * =====================================================================
   */
  async reject(id, reason) {
    return normalize(unwrap(await $api(`/admin/posts/${id}/reject`, { method: 'POST', body: { reason } })))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Archive Post qua endpoint lifecycle
   * =====================================================================
   * INPUT: Post ID.
   * OUTPUT: Post archived đã normalize.
   * SIDE EFFECT: Gọi POST lifecycle API; published_at lịch sử được giữ server-side.
   * EXCEPTION/TRANSACTION: Lỗi API truyền lên caller.
   * =====================================================================
   */
  async archive(id) {
    return normalize(unwrap(await $api(`/admin/posts/${id}/archive`, { method: 'POST' })))
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Yêu cầu xóa Post và để backend quản lý media usage
   * =====================================================================
   * INPUT: Post ID.
   * OUTPUT: Response xóa từ Admin API.
   * SIDE EFFECT: Gọi DELETE Admin API.
   * EXCEPTION/TRANSACTION: Lỗi API truyền lên caller; browser không xóa file media trực tiếp.
   * =====================================================================
   */
  async remove(id) {
    return $api(`/admin/posts/${id}`, { method: 'DELETE' })
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: Tải các lựa chọn taxonomy cho Post
   * =====================================================================
   * INPUT: Taxonomy type và query.
   * OUTPUT: Danh sách item ID/name.
   * SIDE EFFECT: Gọi GET Admin API.
   * EXCEPTION/TRANSACTION: Lỗi API truyền lên caller.
   * =====================================================================
   */
  async taxonomy(type, params = {}) {
    const response = await $api(`/admin/${type}`, { query: { ['per_page']: 100, ...params } })
    const payload = unwrap(response)
    const items = Array.isArray(payload) ? payload : payload?.items ?? []

    return items.map(item => ({ id: item.id, name: item.name }))
  },
}
