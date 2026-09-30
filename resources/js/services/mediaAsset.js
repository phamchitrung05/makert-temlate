/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cung cấp API client cho Media Library admin
 * =====================================================================
 *
 * Service là boundary duy nhất giữa Pinia Media Asset store và Laravel API
 * (hoặc MSW fake API). Mọi response BaseResponse được unwrap tại đây để store
 * và component chỉ làm việc với payload nghiệp vụ ổn định.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - list(): lấy danh sách, filter, sort và pagination
 * - show(): lấy chi tiết asset
 * - upload(): upload multipart và báo tiến độ
 * - update(): cập nhật metadata/visibility
 * - attach()/detach()/reorder(): quản lý usage nghiệp vụ
 * - remove(): soft-delete asset
 * - retry(): retry scan/conversion
 * - download(): lấy public hoặc temporary download payload
 * - unwrapApiResponse(): bỏ BaseResponse envelope
 * - uploadWithProgress(): gửi multipart có progress callback
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : query, id, metadata, File và usage payload từ store
 * - OUTPUT: payload đã unwrap hoặc lỗi HTTP; không chứa logic UI
 * =====================================================================
 */
import { $api, getAdminAccessToken } from '@/utils/api'

/**
 * Lấy data payload từ BaseResponse.
 *
 * Input: response JSON dạng `{ success, data, meta }` hoặc payload phẳng.
 * Output: data bên trong envelope; response phẳng được giữ nguyên.
 */
export const unwrapApiResponse = response => {
  if (response && typeof response === 'object' && 'success' in response && 'data' in response)
    return response.data

  return response
}

/**
 * Chuẩn hóa lỗi từ XMLHttpRequest để service có cùng hành vi với ofetch.
 *
 * Input: HTTP status, response body và fallback message.
 * Output: Error có status/data/response để store hoặc form xử lý tiếp.
 */
const createUploadError = (status, response, fallbackMessage) => {
  const error = new Error(response?.message || fallbackMessage)

  error.status = status
  error.data = response
  error.response = response

  return error
}

/**
 * Upload multipart bằng XMLHttpRequest để nhận progress event.
 *
 * Input: endpoint, FormData và callback phần trăm tùy chọn.
 * Output: JSON response đã parse; reject khi HTTP hoặc parse lỗi.
 */
const uploadWithProgress = (endpoint, formData, onProgress) => {
  if (typeof XMLHttpRequest === 'undefined')
    return $api(endpoint, { method: 'POST', body: formData })

  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest()
    const baseUrl = import.meta.env.VITE_API_BASE_URL || '/api'

    request.open('POST', `${baseUrl.replace(/\/$/, '')}/${endpoint.replace(/^\//, '')}`)
    request.setRequestHeader('Accept', 'application/json')

    const accessToken = getAdminAccessToken()
    if (accessToken)
      request.setRequestHeader('Authorization', `Bearer ${accessToken}`)

    request.upload.addEventListener('progress', event => {
      if (event.lengthComputable && typeof onProgress === 'function')
        onProgress(Math.round((event.loaded / event.total) * 100))
    })

    request.addEventListener('load', () => {
      let response = null

      try {
        response = request.responseText ? JSON.parse(request.responseText) : null
      }
      catch {
        reject(createUploadError(request.status, null, 'API upload trả về dữ liệu không hợp lệ.'))

        return
      }

      if (request.status >= 200 && request.status < 300) {
        resolve(response)

        return
      }

      reject(createUploadError(request.status, response, 'Upload media thất bại.'))
    })

    request.addEventListener('error', () => {
      reject(createUploadError(0, null, 'Không thể kết nối tới API upload media.'))
    })

    request.addEventListener('abort', () => {
      reject(createUploadError(0, null, 'Upload media đã bị hủy.'))
    })

    request.send(formData)
  })
}

/**
 * Tạo multipart payload theo contract MediaAssetUploadRequest.
 *
 * Input: File và metadata upload.
 * Output: FormData với key snake_case đúng backend.
 */
const toUploadFormData = ({ file, ...payload }) => {
  const formData = new FormData()

  formData.append('file', file)
  Object.entries(payload).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '')
      formData.append(key, String(value))
  })

  return formData
}

/**
 * Bỏ các bộ lọc chưa được chọn trước khi tạo query string.
 *
 * Input: object filter từ store/component.
 * Output: object chỉ còn giá trị có nghĩa để Laravel không validate key rỗng.
 */
const compactQuery = params => Object.fromEntries(
  Object.entries(params).filter(([key, value]) => (
    key !== 'owner' && value !== undefined && value !== null && value !== ''
  )),
)

/**
 * API client Media Library.
 *
 * Input: tham số nghiệp vụ của từng endpoint.
 * Output: payload đã bỏ envelope BaseResponse.
 */
export const mediaAssetService = {
  async list(params = {}) {
    const response = await $api('/admin/media-assets', { query: compactQuery(params) })
    const payload = unwrapApiResponse(response) || {}

    return {
      items: payload.items ?? [],
      itemsLength: payload.itemsLength ?? 0,
      pagination: response?.meta?.pagination ?? null,
    }
  },

  async show(id) {
    const response = await $api(`/admin/media-assets/${id}`)

    return unwrapApiResponse(response)
  },

  async upload(payload, onProgress) {
    const response = await uploadWithProgress(
      '/admin/media-assets',
      toUploadFormData(payload),
      onProgress,
    )

    return unwrapApiResponse(response)
  },

  async update(id, payload) {
    const response = await $api(`/admin/media-assets/${id}`, {
      method: 'PATCH',
      body: payload,
    })

    return unwrapApiResponse(response)
  },

  async attach(id, payload) {
    const response = await $api(`/admin/media-assets/${id}/usages`, {
      method: 'POST',
      body: payload,
    })

    return unwrapApiResponse(response)
  },

  async detach(id, usageId) {
    const response = await $api(`/admin/media-assets/${id}/usages/${usageId}`, {
      method: 'DELETE',
    })

    return unwrapApiResponse(response)
  },

  async reorder(payload) {
    const response = await $api('/admin/media-assets/usages/reorder', {
      method: 'POST',
      body: payload,
    })

    return unwrapApiResponse(response)
  },

  async remove(id) {
    const response = await $api(`/admin/media-assets/${id}`, { method: 'DELETE' })

    return unwrapApiResponse(response)
  },

  async retry(id) {
    const response = await $api(`/admin/media-assets/${id}/retry`, { method: 'POST' })

    return unwrapApiResponse(response)
  },

  async download(id) {
    const response = await $api(`/admin/media-assets/${id}/download`)

    return unwrapApiResponse(response)
  },
}

// Tên ngắn giữ API dễ dùng cho các feature picker sau này.
export const mediaAssetApi = mediaAssetService

