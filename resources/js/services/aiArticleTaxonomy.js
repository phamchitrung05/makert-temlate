/* eslint-disable camelcase -- Query chuẩn Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đọc đầy đủ danh mục/tag active để người dùng chọn thủ công.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: all().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): type/signal -> ID/name/status, không tạo tag
 * hoặc đề xuất taxonomy bằng model; giữ phân trang server, không giới hạn 100 dòng.
 * =====================================================================
 */
import { $api } from '@/utils/api'

export const aiArticleTaxonomyService = {
  /**
   * =====================================================================
   * Input: categories/tags, AbortSignal, `resourceType` tùy chọn
   * (`'visual_style'` chỉ trả item đã gắn vào Visual Style; mặc định mọi item).
   * Output: mọi item active theo API.
   * SIDE EFFECT: GET lần lượt các trang; lỗi giữ caller ở trạng thái tải lại.
   * =====================================================================
   */
  async all(type, signal, resourceType) {
    if (!['categories', 'tags'].includes(type)) throw new Error('Loại taxonomy không hợp lệ.')
    const items = []
    let page = 1
    let lastPage = 1
    do {
      const result = await $api(`/admin/${type}`, {
        query: { page, per_page: 100, status: 'active', type: resourceType },
        signal,
        retry: 0,
      })

      items.push(...(Array.isArray(result.data) ? result.data : result.data?.items ?? []))
      lastPage = Number(result.meta?.pagination?.last_page ?? 1)
      page += 1
    } while (page <= lastPage)

    return items.filter(item => !item.status || item.status === 'active')
  },
}
