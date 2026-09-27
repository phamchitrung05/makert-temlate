import { $api } from '@/utils/api'

/**
 * Lấy payload nghiệp vụ từ API envelope chung của Laravel.
 *
 * Input: response JSON có dạng { success, message, data, errors, meta } hoặc
 * response phẳng từ mock API trong lúc phát triển local.
 * Output: object payload để auth store không phụ thuộc cấu trúc HTTP envelope.
 */
const unwrapApiResponse = response => {
  if (response && typeof response === 'object' && 'success' in response && 'data' in response)
    return response.data

  return response
}

/**
 * Gọi các endpoint authentication stateless của admin.
 *
 * Input: payload đăng nhập hoặc không có input với các endpoint hiện tại.
 * Output: payload data từ Laravel Sanctum; lỗi HTTP giữ envelope để UI xử lý.
 */
export const adminAuthService = {
  login(payload) {
    const { deviceName, ...credentials } = payload

    return $api('/admin/login', {
      method: 'POST',
      body: {
        ...credentials,
        'device_name': deviceName,
      },
    }).then(unwrapApiResponse)
  },

  me() {
    return $api('/admin/me').then(unwrapApiResponse)
  },

  revokeCurrentToken() {
    return $api('/auth/token/revoke', {
      method: 'POST',
    })
  },
}
