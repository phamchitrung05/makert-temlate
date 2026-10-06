/**
 * State từng nhóm Settings: load/updateField/save/reset/loadOperation/testMail.
 * Input: service API. Output: draft, dirty/error/conflict; giữ draft khi request lỗi.
 * Side effect: HTTP, không lưu secret vào storage; chặn response cũ và lưu trùng.
 */
import { computed, shallowRef } from 'vue'
import { settingsService } from '@/services/settings'

const clone = value => JSON.parse(JSON.stringify(value))
const editableValues = values => ({ ...values, ...(Object.hasOwn(values, 'password_configured') ? { password: '' } : {}) })

export function useSettings(service = settingsService) {
  const sections = shallowRef({})
  const drafts = shallowRef({})
  const options = shallowRef({})
  const loading = shallowRef(false)
  const loaded = shallowRef(false)
  const error = shallowRef('')
  const canManage = shallowRef(false)
  const saving = shallowRef('')
  const fieldErrors = shallowRef({})
  const notices = shallowRef({})
  const conflicts = shallowRef({})
  const operations = shallowRef({})
  const mailTesting = shallowRef(false)
  const mailNotice = shallowRef(null)
  let requestId = 0

  const dirtyGroups = computed(() => Object.keys(drafts.value)
    .filter(group => JSON.stringify(drafts.value[group]) !== JSON.stringify(editableValues(sections.value[group]?.values ?? {}))))

  /** Load/retry; chỉ gọi khi chưa có draft cần giữ. Refresh có chủ ý qua reset nhóm. */
  async function load() {
    if (saving.value || dirtyGroups.value.length) return false
    const current = ++requestId

    loading.value = true
    error.value = ''
    try {
      const data = await service.list()
      if (current !== requestId) return false
      sections.value = data.sections ?? {}
      drafts.value = Object.fromEntries(Object.entries(sections.value).map(([group, section]) => [group, clone(editableValues(section.values))]))
      options.value = data.options ?? {}
      canManage.value = Boolean(data.can_manage)
      loaded.value = true

      return true
    }
    catch (reason) {
      if (current === requestId) error.value = reason?.data?.message ?? 'Không thể tải cài đặt. Hãy thử lại.'

      return false
    }
    finally {
      if (current === requestId) loading.value = false
    }
  }

  /** Update một field; loại bỏ lỗi field cũ, giữ các tab khác và cờ xung đột. */
  function updateField(group, key, value) {
    if (!canManage.value || saving.value === group || !Object.hasOwn(drafts.value[group] ?? {}, key)) return
    if (JSON.stringify(drafts.value[group][key]) === JSON.stringify(value)) return
    drafts.value = { ...drafts.value, [group]: { ...drafts.value[group], [key]: value } }
    fieldErrors.value = { ...fieldErrors.value, [group]: { ...fieldErrors.value[group], [key]: undefined } }
    notices.value = { ...notices.value, [group]: null }
    if (group === 'mail') mailNotice.value = null
  }

  /** Reset chỉ tab chọn, không ghi API; dùng sau xác nhận bỏ thay đổi. */
  function reset(group) {
    if (saving.value === group || !sections.value[group]) return
    drafts.value = { ...drafts.value, [group]: clone(editableValues(sections.value[group].values)) }
    fieldErrors.value = { ...fieldErrors.value, [group]: {} }
    notices.value = { ...notices.value, [group]: null }
  }

  /** Save từng nhóm với version; redaction state sau thành công, giữ input khi 409/422. */
  async function save(group) {
    if (saving.value || loading.value || !canManage.value || conflicts.value[group] || !dirtyGroups.value.includes(group)) return false
    saving.value = group
    fieldErrors.value = { ...fieldErrors.value, [group]: {} }
    try {
      const payload = { ...drafts.value[group], version: sections.value[group].version }

      delete payload.password_configured

      const section = await service.update(group, payload)

      sections.value = { ...sections.value, [group]: section }
      drafts.value = { ...drafts.value, [group]: clone(editableValues(section.values)) }
      notices.value = { ...notices.value, [group]: { type: 'success', message: 'Đã lưu cài đặt.' } }

      return true
    }
    catch (reason) {
      const conflict = reason?.status === 409 || reason?.data?.status === 409 || reason?.response?.status === 409

      conflicts.value = { ...conflicts.value, [group]: conflict }
      fieldErrors.value = { ...fieldErrors.value, [group]: reason?.data?.errors ?? {} }
      notices.value = { ...notices.value, [group]: {
        type: conflict ? 'warning' : 'error',
        message: reason?.data?.message ?? 'Không thể lưu cài đặt. Thay đổi của bạn vẫn được giữ.',
      } }

      return false
    }
    finally {
      saving.value = ''
    }
  }

  /** Tải lại một nhóm xung đột sau xác nhận; giữ draft khác và không ghi đè khi fetch lỗi. */
  async function reloadGroup(group) {
    if (saving.value || loading.value) return false
    loading.value = true
    try {
      const data = await service.list()

      sections.value = { ...sections.value, [group]: data.sections[group] }
      reset(group)
      conflicts.value = { ...conflicts.value, [group]: false }

      return true
    }
    catch (reason) {
      notices.value = { ...notices.value, [group]: { type: 'error', message: reason?.data?.message ?? 'Không thể tải lại cài đặt.' } }

      return false
    }
    finally {
      loading.value = false
    }
  }

  /** Lazy read vận hành; trạng thái độc lập theo tab, response lỗi có retry. */
  async function loadOperation(group) {
    if (operations.value[group]?.loading) return
    operations.value = { ...operations.value, [group]: { ...operations.value[group], loading: true, error: '' } }
    try {
      const data = await service.readOperations(group)

      operations.value = { ...operations.value, [group]: { data, loading: false, error: '' } }
    }
    catch (reason) {
      operations.value = { ...operations.value, [group]: { loading: false, error: reason?.data?.message ?? 'Không thể tải thông tin.' } }
    }
  }

  /** Gửi mail thử theo thao tác rõ ràng, chỉ dùng cấu hình đã lưu; không gửi khi form dirty. */
  async function testMail(recipient) {
    if (mailTesting.value || !canManage.value || dirtyGroups.value.includes('mail')) return false
    mailTesting.value = true
    mailNotice.value = null
    try {
      const response = await service.testMail(recipient)

      mailNotice.value = { type: 'success', message: response.message }

      return true
    }
    catch (reason) {
      mailNotice.value = { type: 'error', message: reason?.data?.errors?.recipient?.[0] ?? reason?.data?.message ?? 'Không thể gửi email thử.' }

      return false
    }
    finally {
      mailTesting.value = false
    }
  }

  return { sections, drafts, options, loading, loaded, error, canManage, saving, fieldErrors, notices, conflicts, dirtyGroups, operations, mailTesting, mailNotice, load, updateField, reset, save, reloadGroup, loadOperation, testMail }
}
