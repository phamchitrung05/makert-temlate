/**
 * API Settings: list/update/readOperations/testMail/preferences.
 * Input: group + payload. Output: DTO BaseResponse; không giữ secret ở storage.
 */
import { $api } from '@/utils/api'

const unwrap = response => response.data

export const settingsService = {
  list: async () => unwrap(await $api('/admin/settings')),
  update: async (group, payload) => unwrap(await $api(`/admin/settings/${group}`, { method: 'PATCH', body: payload, retry: 0 })),
  readOperations: async group => unwrap(await $api(`/admin/settings/${group}`)),
  preferences: async () => unwrap(await $api('/admin/settings/preferences')),
  testMail: async recipient => $api('/admin/settings/mail/test', { method: 'POST', body: { recipient }, retry: 0 }),
}
