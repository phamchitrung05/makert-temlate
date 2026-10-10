/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cổng gọi API quản lý Category trong admin.
 * =====================================================================
 *
 * Service gom contract CRUD của Category để page không phụ thuộc trực tiếp
 * vào envelope response hoặc tên field snake_case của Laravel.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - unwrap(): lấy payload nghiệp vụ từ response envelope.
 * - normalize(): chuẩn hóa một Category cho frontend.
 * - toPayload(): whitelist field mà Category API hỗ trợ.
 * - list(): tải danh sách Category có phân trang.
 * - show(): tải chi tiết một Category.
 * - create(): tạo Category mới.
 * - update(): cập nhật Category.
 * - remove(): xóa mềm Category.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : query, id và dữ liệu form Category; Bearer token được $api gắn.
 * - OUTPUT: DTO Category hoặc danh sách DTO; lỗi HTTP truyền nguyên vẹn cho UI.
 * - SIDE EFFECT: Gọi Admin Category API; không tự lưu state giao diện.
 * =====================================================================
 */
import { $api } from '@/utils/api'
/* eslint-disable camelcase -- Laravel payload and query keys follow API contract. */

/**
 * Lấy payload nghiệp vụ từ envelope chung của API.
 *
 * Input: response JSON từ Laravel hoặc payload phẳng của mock API.
 * Output: trường data nếu response là envelope, ngược lại trả response.
 */
const unwrap = response => response?.success && 'data' in response ? response.data : response

/**
 * Chuẩn hóa Category từ snake_case của Laravel sang shape dùng trong Vue.
 *
 * Input: một item trong response Category API.
 * Output: DTO ổn định cho cây Category và form chỉnh sửa.
 */
const normalize = item => ({
  ...item,
  id: item.id,
  name: item.name ?? '',
  slug: item.slug ?? null,
  description: item.description ?? '',
  parentId: item.parent_id ?? null,
  sortOrder: Number(item.sort_order ?? 0),
  status: item.status === 'inactive' ? 'inactive' : 'active',
  showOnMenu: item.show_on_menu !== false,
  thumbnail: item.media?.thumbnail ?? null,
  postCount: Number(item.post_count ?? item.posts_count ?? 0),
})

/**
 * Chỉ gửi những field được CategoryCreateRequest/CategoryUpdateRequest hỗ trợ.
 *
 * Input: dữ liệu form camelCase hoặc payload đã chuẩn hóa.
 * Output: payload Laravel gồm name, parent_id, description, status và sort_order.
 */
const toPayload = payload => ({
  name: String(payload.name ?? '').trim(),
  parent_id: payload.parentId ?? payload.parent ?? null,
  description: payload.description?.trim() || null,
  status: payload.status ?? (payload.isActive === false ? 'inactive' : 'active'),
  show_on_menu: payload.showOnMenu !== false,
  ...(payload.thumbnailDirty ? {
    media: {
      thumbnail_id: payload.thumbnail?.id ?? null,
    },
  } : {}),
  ...(payload.sortOrder !== undefined ? { sort_order: payload.sortOrder } : {}),
})

/**
 * API Category dùng cho page quản trị.
 */
const categoryService = {
  /**
   * Tải danh sách Category dạng phẳng để page dựng cây theo parent_id.
   *
   * Input: page/perPage/search tùy chọn.
   * Output: items, tổng số và metadata phân trang.
   */
  async list({ page = 1, perPage = 100, search = '' } = {}) {
    const response = await $api('/admin/categories', {
      query: {
        page,
        per_page: perPage,
        search: search || undefined,
      },
    })

    const payload = unwrap(response)
    const items = Array.isArray(payload) ? payload : payload?.items ?? []

    return {
      items: items.map(normalize),
      itemsLength: response?.meta?.pagination?.total ?? items.length,
      pagination: response?.meta?.pagination ?? null,
    }
  },

  /**
   * Tải chi tiết Category theo ID.
   *
   * Input: id Category.
   * Output: DTO Category đã normalize.
   */
  async show(id) {
    return normalize(unwrap(await $api(`/admin/categories/${id}`)))
  },

  /**
   * Tạo Category mới.
   *
   * Input: dữ liệu form Category.
   * Output: Category vừa tạo.
   * Side effect: POST /admin/categories.
   */
  async create(payload) {
    return normalize(unwrap(await $api('/admin/categories', {
      method: 'POST',
      body: toPayload(payload),
    })))
  },

  /**
   * Cập nhật Category theo ID.
   *
   * Input: id Category và dữ liệu form.
   * Output: Category sau cập nhật.
   * Side effect: PUT /admin/categories/{id}.
   */
  async update(id, payload) {
    return normalize(unwrap(await $api(`/admin/categories/${id}`, {
      method: 'PUT',
      body: toPayload(payload),
    })))
  },

  /**
   * Xóa mềm Category theo ID.
   *
   * Input: id Category.
   * Output: Promise hoàn tất với HTTP 204.
   * Side effect: DELETE /admin/categories/{id}.
   */
  async remove(id) {
    return $api(`/admin/categories/${id}`, { method: 'DELETE' })
  },
}

export { categoryService, normalize as normalizeCategory, toPayload as categoryPayload }
