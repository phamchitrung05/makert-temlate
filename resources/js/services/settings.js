/**
 * =====================================================================
 * CHỨC NĂNG FILE: API Settings, hỗ trợ multipart branding cùng version nhóm site.
 * CÁC HÀM/METHOD TRONG FILE: unwrap(), update(), list/readOperations/testMail/preferences.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : group + payload/Files; OUTPUT: DTO BaseResponse.
 * - SIDE EFFECT: HTTP; upload không tự retry và không tự lưu file khi mới chọn.
 * =====================================================================
 */
import { $api } from '@/utils/api'

/** Input: BaseResponse. Output: DTO trong data. */
const unwrap = response => response.data

// Settings requests must release loading/save state when the server stops responding.
export const SETTINGS_REQUEST_TIMEOUT_MS = 15000
const requestOptions = { timeout: SETTINGS_REQUEST_TIMEOUT_MS, retry: 0 }

/** Input: group/payload. Output: saved DTO; file dùng POST spoof PATCH để PHP đọc multipart. */
async function update(group, payload) {
  const values = { ...payload }
  if (group === 'site') {
    ['logo_url', 'favicon_url', 'logo_configured', 'favicon_configured', 'favicon_type'].forEach(key => { delete values[key] })
  }
  const hasFiles = group === 'site' && Boolean(values.logo_file || values.favicon_file)
  let body = values

  if (hasFiles) {
    body = new FormData()
    body.append('_method', 'PATCH')
    Object.entries(values).forEach(([key, value]) => {
      body.append(key, value instanceof File ? value : value === null ? '' : typeof value === 'boolean' ? String(Number(value)) : String(value))
    })
  }

  return unwrap(await $api(`/admin/settings/${group}`, { ...requestOptions, method: hasFiles ? 'POST' : 'PATCH', body }))
}

export const settingsService = {
  list: async () => unwrap(await $api('/admin/settings', requestOptions)),
  update,
  readOperations: async group => unwrap(await $api(`/admin/settings/${group}`, requestOptions)),
  preferences: async () => unwrap(await $api('/admin/settings/preferences', requestOptions)),
  testMail: async recipient => $api('/admin/settings/mail/test', { method: 'POST', body: { recipient }, timeout: 120000, retry: 0 }),
}
