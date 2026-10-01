/**
 * =====================================================================
 * CHỨC NĂNG FILE: API client tạo ảnh và tải asset sau khi worker hoàn tất
 * =====================================================================
 *
 * Client chỉ truyền prompt/model override; API key và provider snapshot ở
 * server-side. Asset được đọc lại qua Media Library trước khi emit vào Post.
 */
import { $api } from '@/utils/api'

const unwrap = response => response?.success && 'data' in response ? response.data : response

export const aiImageGenerationService = {
  /**
   * =====================================================================
   * CHỨC NĂNG: queued image run.
   * =====================================================================
   * INPUT: prompt/provider/model override.
   * OUTPUT: queued image run.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  create(payload) {
    return $api('/admin/ai-image/generations', { method: 'POST', body: payload }).then(unwrap)
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: image lifecycle and picker-safe asset when ready.
   * =====================================================================
   * INPUT: job UUID.
   * OUTPUT: image lifecycle and picker-safe asset when ready.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  status(id) {
    return $api(`/admin/ai-image/generations/${id}`).then(unwrap)
  },

  /**
   * =====================================================================
   * CHỨC NĂNG: picker-compatible asset for legacy responses.
   * =====================================================================
   * INPUT: media asset ID.
   * OUTPUT: picker-compatible asset for legacy responses.
   * SIDE EFFECT: Gọi Admin API qua service; không gọi provider trực tiếp hoặc lưu API key cục bộ.
   * EXCEPTION/TRANSACTION: Không mở transaction.
   * =====================================================================
   */
  asset(id) {
    return $api(`/admin/media-assets/${id}`).then(unwrap)
  },
}
