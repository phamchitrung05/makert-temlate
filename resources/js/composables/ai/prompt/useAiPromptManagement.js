/* eslint-disable camelcase -- Profile/Settings contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: CRUD profile bất kỳ từ List, tạo thủ công không cần analysis.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: useAiPromptManagement(), open(), load(), save(), confirm(), close(),
 * canSave/dirty (computed), onScopeDispose().
 * INPUT/OUTPUT CỦA CLASS (tổng thể): thao tác profile/phiên bản -> form/dialog/feedback.
 * SIDE EFFECT: GET/POST/PUT/DELETE qua service, version 409 giữ bản sửa; không gọi AI.
 * =====================================================================
 */
import { computed, onScopeDispose, shallowRef } from 'vue'
import { aiWritingProfilesService } from '@/services/aiWritingProfiles'
import { emptyProfileForm, profilePayload, profileToForm, validateProfileForm } from '@/utils/aiWritingProfile'
import { formatAiError } from '@/utils/aiErrors'

/**
 * =====================================================================
 * Input: callback thay đổi để refresh List. Output: API/state page-scoped.
 * =====================================================================
 */
export function useAiPromptManagement(onChanged = () => {}) {
  const action = shallowRef(null)
  const profile = shallowRef(null)
  const form = shallowRef(emptyProfileForm())
  const loading = shallowRef(false)
  const saving = shallowRef(false)
  const error = shallowRef('')
  const errors = shallowRef({})
  const conflict = shallowRef(false)
  const uncertain = shallowRef(false)
  const defaultId = shallowRef(null)
  const defaultError = shallowRef('')
  const notice = shallowRef('')
  let baseline = ''
  let sequence = 0
  let controller
  const dirty = computed(() => JSON.stringify(form.value) !== baseline)

  const canSave = computed(() => !loading.value && !saving.value && !conflict.value && !uncertain.value
    && !(action.value?.id && !profile.value) && !Object.keys(validateProfileForm(form.value)).length)

  /**
   * =====================================================================
   * Input: ID đã chọn hoặc create. Output: detail/default/version mới; chỉ GET.
   * Khi tải sau 409 caller phải xác nhận bỏ bản sửa trước gọi load().
   * =====================================================================
   */
  async function load() {
    if (!action.value || saving.value) return
    controller?.abort()
    controller = new AbortController()

    const token = ++sequence

    loading.value = true
    error.value = ''
    try {
      const [options, current] = await Promise.all([
        aiWritingProfilesService.options(controller.signal),
        action.value.id ? aiWritingProfilesService.profile(action.value.id, controller.signal) : Promise.resolve(null),
      ])

      if (token !== sequence) return
      defaultId.value = options.default_writing_profile_id ?? null
      profile.value = current
      form.value = current ? profileToForm(current, defaultId.value) : emptyProfileForm()
      baseline = JSON.stringify(form.value)
      conflict.value = false
      errors.value = {}
      defaultError.value = ''
    }
    catch (reason) { if (token === sequence) error.value = formatAiError(reason, 'Không đọc được mẫu/mặc định. Hãy tải lại trước khi sửa.') }
    finally { if (token === sequence) loading.value = false }
  }

  /**
   * =====================================================================
   * Input: edit/create/toggle/delete và row. Output: mở một action, đọc detail;
   * không mutation theo click mở dialog hoặc tự bỏ form đang sửa.
   * =====================================================================
   */
  function open(kind, item = null) {
    if (action.value || saving.value) return
    action.value = { kind, id: item?.id ?? null, name: item?.name ?? '' }
    profile.value = null
    form.value = emptyProfileForm()
    baseline = JSON.stringify(form.value)
    error.value = ''
    uncertain.value = false
    void load()
  }

  /**
   * =====================================================================
   * Input: form đã duyệt. Output: profile/version mới, update default riêng;
   * 409 giữ form; POST mất kết quả khóa gửi lại để tránh tạo mẫu trùng.
   * =====================================================================
   */
  async function save() {
    if (!canSave.value || !action.value || error.value && !profile.value && action.value.id) return
    const validation = validateProfileForm(form.value)
    if (Object.keys(validation).length) { errors.value = validation

      return }
    const token = sequence
    const isCreate = !profile.value
    const requestedDefault = form.value.setAsDefault

    saving.value = true
    error.value = ''
    errors.value = {}
    defaultError.value = ''
    try {
      const current = await aiWritingProfilesService.save(profilePayload(form.value, null, profile.value), profile.value?.id)
      if (token !== sequence) return
      profile.value = current
      action.value = { ...action.value, id: current.id }
      if (form.value.setAsDefault && current.is_enabled && defaultId.value !== current.id || !form.value.setAsDefault && defaultId.value === current.id) {
        try {
          const settings = await aiWritingProfilesService.updateDefault({ default_writing_profile_id: form.value.setAsDefault && current.is_enabled ? current.id : null })
          if (token !== sequence) return
          defaultId.value = settings.default_writing_profile_id ?? settings.settings?.default_writing_profile_id ?? null
        }
        catch (reason) { if (token === sequence) defaultError.value = formatAiError(reason, 'Hãy thử cập nhật mặc định lại.') }
      }
      if (!current.is_enabled && defaultId.value === current.id) defaultId.value = null
      if (token !== sequence) return
      form.value = profileToForm(current, defaultId.value)
      if (defaultError.value) form.value = { ...form.value, setAsDefault: requestedDefault }
      baseline = JSON.stringify(form.value)
      onChanged()
      if (!defaultError.value) {
        notice.value = `Đã lưu văn phong ${current.name} · v${current.version}.`
        action.value = null
      }
    }
    catch (reason) {
      if (token !== sequence) return
      error.value = formatAiError(reason, 'Không lưu được mẫu. Bản chỉnh sửa vẫn được giữ.')
      errors.value = reason?.data?.errors ?? {}

      const status = Number(reason?.status ?? reason?.statusCode ?? reason?.response?.status)

      conflict.value = status === 409
      if (isCreate && (!status || status >= 500)) {
        uncertain.value = true
        error.value += ' Kết quả tạo mẫu chưa xác định. Kiểm tra danh sách trước khi tạo lại.'
        onChanged()
      }
    }
    finally { if (token === sequence) saving.value = false }
  }

  /**
   * =====================================================================
   * Input: xác nhận toggle/delete trên profile/version vừa đọc. Output: mutation
   * một lần; backend gỡ default khi tắt/xóa, 409 yêu cầu người dùng tải lại.
   * =====================================================================
   */
  async function confirm() {
    if (saving.value || loading.value || conflict.value || !profile.value) return
    const token = sequence

    saving.value = true
    error.value = ''
    try {
      if (action.value.kind === 'delete') await aiWritingProfilesService.remove(profile.value.id, profile.value.version)
      else await aiWritingProfilesService.save({ version: profile.value.version, is_enabled: !profile.value.is_enabled }, profile.value.id)
      if (token !== sequence) return
      notice.value = action.value.kind === 'delete' ? 'Đã xóa văn phong. Snapshot các bài cũ được giữ.' : 'Đã cập nhật trạng thái văn phong.'
      onChanged()
      action.value = null
    }
    catch (reason) { if (token === sequence) { error.value = formatAiError(reason); conflict.value = Number(reason?.status ?? reason?.statusCode) === 409 } }
    finally { if (token === sequence) saving.value = false }
  }

  /**
   * =====================================================================
   * Input: caller đã xác nhận bỏ bản chưa lưu. Output: đóng và vô hiệu GET cũ.
   * Không xóa profile/analysis server; giữ dialog khi mutation đang gửi.
   * =====================================================================
   */
  function close() {
    if (saving.value) return
    sequence += 1
    controller?.abort()
    action.value = null
    loading.value = false
  }

  // =====================================================================
  // Input: page unmount. Output: dừng GET và bỏ response muộn.
  // =====================================================================
  onScopeDispose(() => { sequence += 1; controller?.abort() })

  return { action, profile, form, loading, saving, error, errors, conflict, uncertain, defaultId, defaultError, notice, dirty, canSave, open, load, save, confirm, close }
}
