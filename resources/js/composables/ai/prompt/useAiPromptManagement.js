/* eslint-disable camelcase -- Profile/Settings contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: CRUD profile bất kỳ từ List, tạo thủ công không cần analysis.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: useAiPromptManagement(), open(), load(), save(), confirm(), close(), finishClose(),
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
  const isOpen = shallowRef(false)
  const profile = shallowRef(null)
  const form = shallowRef(emptyProfileForm())
  const loading = shallowRef(false)
  const loaded = shallowRef(false)
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

  const canSave = computed(() => isOpen.value && loaded.value && ['create', 'edit'].includes(action.value?.kind)
    && !loading.value && !saving.value && !conflict.value && !uncertain.value
    && !(action.value?.id && !profile.value) && !Object.keys(validateProfileForm(form.value)).length)

  /**
   * =====================================================================
   * Input: ID đã chọn hoặc create. Output: detail/default/version mới; chỉ GET.
   * Khi tải sau 409 caller phải xác nhận bỏ bản sửa trước gọi load().
   * =====================================================================
   */
  async function load() {
    if (!isOpen.value || !action.value || saving.value || uncertain.value) return
    controller?.abort()
    controller = new AbortController()

    const token = ++sequence

    loading.value = true
    loaded.value = false
    error.value = ''
    try {
      const [options, current] = await Promise.all([
        aiWritingProfilesService.options(controller.signal),
        action.value.id ? aiWritingProfilesService.profile(action.value.id, controller.signal) : Promise.resolve(null),
      ])

      if (token !== sequence || !isOpen.value) return
      defaultId.value = options.default_writing_profile_id ?? null
      profile.value = current
      form.value = current ? profileToForm(current, defaultId.value) : emptyProfileForm()
      baseline = JSON.stringify(form.value)
      conflict.value = false
      errors.value = {}
      defaultError.value = ''
      loaded.value = true
    }
    catch (reason) { if (token === sequence && isOpen.value) error.value = formatAiError(reason, 'Không đọc được mẫu/mặc định. Hãy tải lại trước khi sửa.') }
    finally { if (token === sequence && isOpen.value) loading.value = false }
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
    profile.value = item ? { ...item } : null
    form.value = { ...emptyProfileForm(), name: item?.name ?? '', description: item?.description ?? '', is_enabled: item?.is_enabled ?? true }
    baseline = JSON.stringify(form.value)
    error.value = ''
    errors.value = {}
    conflict.value = false
    defaultError.value = ''
    uncertain.value = false
    loaded.value = false
    isOpen.value = true
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
      onChanged()
      if (defaultError.value) {
        profile.value = current
        action.value = { ...action.value, id: current.id }
        form.value = { ...profileToForm(current, defaultId.value), setAsDefault: requestedDefault }
        baseline = JSON.stringify(form.value)
      }
      else {
        notice.value = `Đã lưu văn phong ${current.name} · v${current.version}.`
        isOpen.value = false
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
    if (!isOpen.value || !loaded.value || !['toggle', 'delete'].includes(action.value?.kind)
      || saving.value || loading.value || conflict.value || !profile.value) return
    const token = sequence

    saving.value = true
    error.value = ''
    try {
      if (action.value.kind === 'delete') await aiWritingProfilesService.remove(profile.value.id, profile.value.version)
      else await aiWritingProfilesService.save({ version: profile.value.version, is_enabled: !profile.value.is_enabled }, profile.value.id)
      if (token !== sequence) return
      notice.value = action.value.kind === 'delete' ? 'Đã xóa văn phong. Snapshot các bài cũ được giữ.' : 'Đã cập nhật trạng thái văn phong.'
      onChanged()
      isOpen.value = false
    }
    catch (reason) { if (token === sequence) { error.value = formatAiError(reason); conflict.value = Number(reason?.status ?? reason?.statusCode) === 409 } }
    finally { if (token === sequence) saving.value = false }
  }

  /**
   * =====================================================================
   * Input: caller đã xác nhận bỏ bản chưa lưu. Output: bắt đầu đóng, vô hiệu GET cũ.
   * Giữ nguyên nội dung/loading trong hiệu ứng; không đóng khi mutation đang gửi.
   * =====================================================================
   */
  function close() {
    if (saving.value) return
    sequence += 1
    controller?.abort()
    isOpen.value = false
  }

  /**
   * =====================================================================
   * Input: after-leave của dialog đúng loại. Output: dọn state sau hiệu ứng đóng.
   * Guard ngăn event cũ xóa form của dialog khác hoặc dialog đang mở.
   * =====================================================================
   */
  function finishClose(kind) {
    if (isOpen.value || action.value?.kind !== kind) return
    sequence += 1
    controller?.abort()
    action.value = null
    profile.value = null
    form.value = emptyProfileForm()
    baseline = JSON.stringify(form.value)
    loading.value = false
    loaded.value = false
    saving.value = false
    error.value = ''
    errors.value = {}
    conflict.value = false
    uncertain.value = false
    defaultError.value = ''
  }

  // =====================================================================
  // Input: page unmount. Output: dừng GET và bỏ response muộn.
  // =====================================================================
  onScopeDispose(() => { sequence += 1; controller?.abort() })

  return { action, isOpen, profile, form, loading, loaded, saving, error, errors, conflict, uncertain, defaultId, defaultError, notice, dirty, canSave, open, load, save, confirm, close, finishClose }
}
