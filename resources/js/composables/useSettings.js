/**
 * =====================================================================
 * CHỨC NĂNG FILE: State từng nhóm Settings và branding chưa lưu.
 * CÁC HÀM/METHOD TRONG FILE:
 * - clone()/editableValues()/useSettings(): chuẩn hóa và khởi tạo state.
 * - load()/updateField()/save()/reset()/reloadGroup(): form/version và xung đột.
 * - selectBrandingFile()/removeBrandingFile(): đổi file có kiểm quyền/busy.
 * - loadOperation()/testMail(): đọc vận hành và gửi thử theo yêu cầu.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : service API; OUTPUT: draft, branding, dirty/error/conflict.
 * - SIDE EFFECT: HTTP; giữ draft khi lỗi và chỉ áp dụng branding sau lưu thành công.
 * =====================================================================
 */
import { computed, shallowRef } from 'vue'
import { settingsService } from '@/services/settings'
import { useSettingsBranding } from '@/composables/useSettingsBranding'
import { applySiteBranding } from '@/composables/useSiteBranding'

/** Input: DTO JSON. Output: bản sao cho form; File được giữ ở composable branding riêng. */
const clone = value => JSON.parse(JSON.stringify(value))

/** Input: values DTO. Output: form có password trống, giữ cờ secret theo response. */
const editableValues = values => ({ ...values, ...(Object.hasOwn(values, 'password_configured') ? { password: '' } : {}) })

/** Input: service API tùy chọn. Output: state/actions; HTTP chỉ chạy từ các action rõ ràng. */
export function useSettings(service = settingsService) {
  const branding = useSettingsBranding()
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

  const dirtyGroups = computed(() => [...new Set([
    ...Object.keys(drafts.value).filter(group => JSON.stringify(drafts.value[group]) !== JSON.stringify(editableValues(sections.value[group]?.values ?? {}))),
    ...(branding.dirty.value ? ['site'] : []),
  ])])

  /** Input: kind/File. Output: đổi preview khi có quyền và không tải/lưu; không gọi API. */
  function selectBrandingFile(kind, file) {
    if (!canManage.value || saving.value || loading.value) return
    branding.selectFile(kind, file)
    fieldErrors.value = { ...fieldErrors.value, site: { ...fieldErrors.value.site, [`${kind}_file`]: undefined } }
    notices.value = { ...notices.value, site: null }
  }

  /** Input: kind. Output: đánh dấu gỡ sau lưu; chặn thao tác lúc request đang chạy. */
  function removeBrandingFile(kind) {
    if (!canManage.value || saving.value || loading.value) return
    branding.removeFile(kind)
    fieldErrors.value = { ...fieldErrors.value, site: { ...fieldErrors.value.site, [`${kind}_file`]: undefined } }
    notices.value = { ...notices.value, site: null }
  }

  /** Input: không có. Output: tải/retry và trả success; giữ draft/file đang sửa. */
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
      if (sections.value.site) applySiteBranding(sections.value.site.values)

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

  /** Input: group/key/value. Output: đổi một field, dọn lỗi cũ, giữ tab khác và cờ conflict. */
  function updateField(group, key, value) {
    if (!canManage.value || saving.value === group || !Object.hasOwn(drafts.value[group] ?? {}, key)) return
    if (JSON.stringify(drafts.value[group][key]) === JSON.stringify(value)) return
    drafts.value = { ...drafts.value, [group]: { ...drafts.value[group], [key]: value } }
    fieldErrors.value = { ...fieldErrors.value, [group]: { ...fieldErrors.value[group], [key]: undefined } }
    notices.value = { ...notices.value, [group]: null }
    if (group === 'mail') mailNotice.value = null
  }

  /** Input: group. Output: reset tab/file sau xác nhận bỏ thay đổi; không ghi API. */
  function reset(group) {
    if (saving.value === group || !sections.value[group]) return
    if (group === 'site') branding.reset()
    drafts.value = { ...drafts.value, [group]: clone(editableValues(sections.value[group].values)) }
    fieldErrors.value = { ...fieldErrors.value, [group]: {} }
    notices.value = { ...notices.value, [group]: null }
  }

  /** Input: group. Output: success và DTO/file state; lưu cùng version, giữ input khi 409/422. */
  async function save(group) {
    if (saving.value || loading.value || !canManage.value || conflicts.value[group] || !dirtyGroups.value.includes(group)) return false
    saving.value = group
    fieldErrors.value = { ...fieldErrors.value, [group]: {} }
    try {
      const payload = { ...drafts.value[group], version: sections.value[group].version }

      delete payload.password_configured
      if (group === 'site') Object.assign(payload, branding.payload())

      const section = await service.update(group, payload)

      sections.value = { ...sections.value, [group]: section }
      drafts.value = { ...drafts.value, [group]: clone(editableValues(section.values)) }
      if (group === 'site') {
        branding.reset()
        applySiteBranding(section.values)
      }
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

  /** Input: group. Output: tải lại sau xác nhận; giữ tab khác và không đổi draft khi fetch lỗi. */
  async function reloadGroup(group) {
    if (saving.value || loading.value) return false
    loading.value = true
    try {
      const data = await service.list()

      sections.value = { ...sections.value, [group]: data.sections[group] }
      reset(group)
      if (group === 'site') applySiteBranding(data.sections.site.values)
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

  /** Input: group. Output: lazy read vận hành, lỗi và retry độc lập theo tab. */
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

  /** Input: recipient. Output: success/notice; gửi cấu hình đã lưu khi mail form sạch. */
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

  return { sections, drafts, options, loading, loaded, error, canManage, saving, fieldErrors, notices, conflicts, dirtyGroups, operations, mailTesting, mailNotice, branding, selectBrandingFile, removeBrandingFile, load, updateField, reset, save, reloadGroup, loadOperation, testMail }
}
