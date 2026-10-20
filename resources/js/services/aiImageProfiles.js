import { $api } from '@/utils/api'

const unwrap = response => response?.success && 'data' in response ? response.data : response
const base = '/admin/ai/image-profiles'

export const aiImageProfilesService = {
  list: async page => unwrap(await $api(base, { query: { page }, retry: 0 })),
  options: async () => unwrap(await $api(`${base}/options`, { retry: 0 })),
  create: async body => unwrap(await $api(base, { method: 'POST', body, retry: 0 })),
  show: async id => unwrap(await $api(`${base}/${id}`, { retry: 0 })),
  save: async (id, body) => unwrap(await $api(`${base}/${id}`, { method: 'PUT', body, retry: 0 })),
  remove: async id => unwrap(await $api(`${base}/${id}`, { method: 'DELETE', retry: 0 })),
}
