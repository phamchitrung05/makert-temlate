/* eslint-disable camelcase -- Profile payload dùng tên field Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Duyệt/lưu/sửa mẫu hiện tại và đặt mặc định sau khi lưu.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: useAiPromptProfiles(), savedKey(), save(), loadProfile(),
 * reloadProfile(), recoverSave(), reset(); watcher tạo form/khôi phục profile ID/lưu bất định.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : analysis hiện tại, catalog Settings và actor.
 * - OUTPUT: form/version, lỗi field/default và kết quả lưu đúng thao tác người dùng.
 * - SIDE EFFECT: POST/PUT profile, partial Settings; không phân tích lại hoặc tạo list page.
 * =====================================================================
 */
import { computed, onScopeDispose, shallowRef, toValue, watch } from 'vue'
import { aiWritingProfilesService } from '@/services/aiWritingProfiles'
import { emptyProfileForm, profilePayload, profileToForm, resultToForm, validateProfileForm } from '@/utils/aiWritingProfile'
import { formatAiError } from '@/utils/aiErrors'

/**
 * =====================================================================
 * CHỨC NĂNG: Tách bản người dùng sửa khỏi result AI bất biến.
 * Input: analysis/catalog/actor. Output: form và actions save/reload.
 * SIDE EFFECT: nhớ ID profile theo analysis; dispose bỏ response không còn dùng.
 * =====================================================================
 */
export function useAiPromptProfiles(run, catalog, actorId) {
  const form = shallowRef(emptyProfileForm())
  const savedProfile = shallowRef(null)
  const saving = shallowRef(false)
  const errors = shallowRef({})
  const message = shallowRef('')
  const defaultError = shallowRef('')
  const conflict = shallowRef(false)
  const restoring = shallowRef(false)
  const uncertainSave = shallowRef(false)
  let sequence = 0
  let disposed = false
  let preparedAnalysisId = null
  let observedAnalysisId = null
  const profileOverrideId = shallowRef(null)

  const defaultProfileId = computed(() => catalog.settings.value.default_writing_profile_id ?? null)

  const canSave = computed(() => !saving.value && !restoring.value && !uncertainSave.value && !run.running.value
    && Boolean(savedProfile.value || (run.status.value === 'ready' && !run.sourceChanged.value && run.analysis.value?.result)))

  /**
   * =====================================================================
   * CHỨC NĂNG: Key metadata của profile đã được duyệt từ analysis này.
   * Input: actor/UUID hiện hành. Output: session key; không chứa nội dung nguồn.
   * =====================================================================
   */
  const savedKey = () => `ai_prompt_profile:${toValue(actorId)}:${run.analysis.value?.id}`

  // =====================================================================
  // Input: UUID/status/result server. Output: form mới hoặc profile đã lưu.
  // Không thay form đang sửa trong các lần poll của cùng analysis.
  // =====================================================================
  watch([() => run.analysis.value?.id, run.status], async ([id, status]) => {
    if (id !== observedAnalysisId) {
      sequence++
      observedAnalysisId = id
      restoring.value = false
      preparedAnalysisId = null
      savedProfile.value = null
      form.value = emptyProfileForm()
    }
    if (!id) {
      sequence++
      preparedAnalysisId = null
      savedProfile.value = null
      form.value = emptyProfileForm()

      return
    }
    if (preparedAnalysisId && preparedAnalysisId !== id) {
      sequence++
      preparedAnalysisId = null
      savedProfile.value = null
      form.value = emptyProfileForm()
    }
    if (status !== 'ready' || preparedAnalysisId === id || profileOverrideId.value) return
    const currentSequence = ++sequence

    preparedAnalysisId = id
    savedProfile.value = null
    errors.value = {}
    message.value = ''
    defaultError.value = ''
    conflict.value = false
    uncertainSave.value = false
    form.value = resultToForm(run.analysis.value.result, run.analysis.value.name)
    let savedId = Number(run.analysis.value.draft_profile_id) || null
    try { savedId = Number(window.sessionStorage.getItem(savedKey())) || savedId }
    catch { /* Không có storage thì giữ bản analysis ready. */ }
    if (!savedId) {
      try {
        if (window.sessionStorage.getItem(`${savedKey()}:pending`)) {
          uncertainSave.value = true
          message.value = 'Yêu cầu lưu trước chưa có kết quả xác định. Bấm Kiểm tra mẫu đã lưu để đối chiếu trước khi lưu lại.'
        }
      }
      catch { /* Bản đang duyệt vẫn hoạt động khi storage bị chặn. */ }

      return
    }
    restoring.value = true
    try {
      const profile = await aiWritingProfilesService.profile(savedId)
      if (disposed || currentSequence !== sequence) return
      savedProfile.value = profile
      form.value = profileToForm(profile, defaultProfileId.value)
    }
    catch (reason) {
      if (disposed || currentSequence !== sequence) return
      uncertainSave.value = true
      message.value = formatAiError(reason, 'Mẫu này đã được lưu trước đó nhưng chưa tải lại được. Không tự tạo bản trùng.')
    }
    finally { if (currentSequence === sequence) restoring.value = false }
  }, { flush: 'sync' })

  /**
   * =====================================================================
   * CHỨC NĂNG: Tải trực tiếp profile được chọn từ List để mở trang Edit.
   * Input: profile ID. Output: profile/version và form độc lập; không cần analysis còn hạn.
   * Side effect: GET profile, hủy kết quả tải profile cũ và không gọi model.
   * =====================================================================
   */
  async function loadProfile(id) {
    const profileId = Number(id)
    if (!Number.isInteger(profileId) || profileId < 1 || disposed) return false
    if (savedProfile.value?.id === profileId && !restoring.value) return true

    const currentSequence = ++sequence

    profileOverrideId.value = profileId
    preparedAnalysisId = null
    savedProfile.value = null
    form.value = emptyProfileForm()
    errors.value = {}
    message.value = ''
    defaultError.value = ''
    conflict.value = false
    uncertainSave.value = false
    restoring.value = true

    try {
      const profile = await aiWritingProfilesService.profile(profileId)
      if (disposed || currentSequence !== sequence) return false
      savedProfile.value = profile
      form.value = profileToForm(profile, defaultProfileId.value)

      return true
    }
    catch (reason) {
      if (disposed || currentSequence !== sequence) return false
      message.value = formatAiError(reason, 'Không đọc được văn phong để chỉnh sửa.')

      return false
    }
    finally {
      if (currentSequence === sequence) restoring.value = false
    }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Lưu sau duyệt; update cần version và lỗi default báo riêng.
   * Input: form, analysis ID/profile hiện tại. Output: boolean lưu xong cả yêu cầu mặc định.
   * savedProfile/version/errors vẫn phản ánh server; false giữ bản khi lỗi/chưa xác định.
   * SIDE EFFECT: một mutation profile, Settings khi có yêu cầu default rõ ràng.
   * =====================================================================
   */
  async function save() {
    if (!canSave.value || disposed) return false
    errors.value = validateProfileForm(form.value)
    if (Object.keys(errors.value).length) return false
    const payload = profilePayload(form.value, run.analysis.value?.id ?? null, savedProfile.value)
    const wantsDefault = form.value.setAsDefault && form.value.is_enabled
    const currentSequence = sequence

    saving.value = true
    message.value = ''
    defaultError.value = ''
    conflict.value = false
    if (!savedProfile.value) {
      try { window.sessionStorage.setItem(`${savedKey()}:pending`, payload.name) }
      catch { /* Không lưu payload/nguồn vào storage. */ }
    }
    try {
      const profile = await aiWritingProfilesService.save(payload, savedProfile.value?.id)
      if (disposed || currentSequence !== sequence) return false
      savedProfile.value = profile
      try { window.sessionStorage.setItem(savedKey(), String(profile.id)); window.sessionStorage.removeItem(`${savedKey()}:pending`) }
      catch { /* ID lưu thành công không phụ thuộc storage. */ }
      message.value = `Đã lưu văn phong “${profile.name}” (version ${profile.version}).`
      if (wantsDefault || defaultProfileId.value === profile.id) {
        try {
          const settings = await aiWritingProfilesService.updateDefault({ default_writing_profile_id: wantsDefault ? profile.id : null })
          if (disposed || currentSequence !== sequence) return false
          catalog.settings.value = { ...catalog.settings.value, ...settings }
        }
        catch (reason) {
          if (disposed || currentSequence !== sequence) return false
          defaultError.value = `Mẫu đã lưu, nhưng chưa cập nhật được mặc định: ${formatAiError(reason)}`
        }
      }
      form.value = profileToForm(profile, defaultProfileId.value)

      return !defaultError.value
    }
    catch (reason) {
      if (disposed || currentSequence !== sequence) return false
      errors.value = reason?.data?.errors ?? {}
      conflict.value = Number(reason?.status ?? reason?.statusCode ?? reason?.response?.status) === 409
      message.value = formatAiError(reason, 'Không lưu được mẫu. Bản chỉnh sửa vẫn được giữ lại.')

      const httpStatus = Number(reason?.status ?? reason?.statusCode ?? reason?.response?.status)
      if (httpStatus >= 400 && httpStatus < 500) {
        try { window.sessionStorage.removeItem(`${savedKey()}:pending`) }
        catch { /* Lỗi validation xác định không cần khóa lưu sau reload. */ }
      }
      if (!savedProfile.value && (!httpStatus || httpStatus >= 500)) {
        uncertainSave.value = true
        message.value += ' Chưa xác định kết quả lưu; không tự gửi lại để tránh tạo mẫu trùng.'
      }

      return false
    }
    finally { if (!disposed) saving.value = false }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Tải profile mới nhất sau khi người dùng đồng ý bỏ bản sửa cục bộ.
   * Input: savedProfile ID. Output: form/version mới, không tự retry PUT sau 409.
   * =====================================================================
   */
  async function reloadProfile() {
    if (!savedProfile.value || saving.value || disposed) return
    const currentSequence = sequence

    restoring.value = true
    try {
      const profile = await aiWritingProfilesService.profile(savedProfile.value.id)
      if (disposed || currentSequence !== sequence) return
      savedProfile.value = profile
      form.value = profileToForm(profile, defaultProfileId.value)
      conflict.value = false
      errors.value = {}
      message.value = 'Đã tải version mới nhất của mẫu.'
    }
    catch (reason) { if (!disposed && currentSequence === sequence) message.value = formatAiError(reason) }
    finally { if (currentSequence === sequence) restoring.value = false }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Đối chiếu GET hiện có sau POST lưu timeout hoặc GET restore lỗi.
   * Input: tên/analysis ID đã gửi; người dùng bấm kiểm tra.
   * Output: profile ID/version khi chỉ có một mẫu khớp; giữ bản đang sửa.
   * SIDE EFFECT: chỉ GET, không tự lưu lại/default hoặc đoán mẫu khi có nhiều kết quả.
   * =====================================================================
   */
  async function recoverSave() {
    if (saving.value || restoring.value || disposed || !run.analysis.value?.id) return
    const currentSequence = sequence
    let name = form.value.name
    let rememberedId
    try {
      name = window.sessionStorage.getItem(`${savedKey()}:pending`) || name
      rememberedId = Number(window.sessionStorage.getItem(savedKey())) || null
    }
    catch { /* Có thể kiểm tra bằng tên trong form nếu storage bị chặn. */ }
    restoring.value = true
    try {
      let profile
      if (rememberedId) profile = await aiWritingProfilesService.profile(rememberedId)
      else {
        const response = await aiWritingProfilesService.findProfiles(name.trim())
        const candidates = (response.data ?? []).filter(item => item.analysis_metadata?.analysis_id === run.analysis.value.id && item.name === name.trim())

        if (disposed || currentSequence !== sequence) return
        if (candidates.length !== 1 || response.meta?.pagination?.last_page > 1) {
          message.value = 'Chưa xác định được duy nhất mẫu đã lưu. Bản chỉnh sửa vẫn giữ nguyên; không tự tạo mẫu trùng.'

          return
        }
        profile = candidates[0]
      }
      if (disposed || currentSequence !== sequence) return
      savedProfile.value = profile
      uncertainSave.value = false
      try { window.sessionStorage.setItem(savedKey(), String(profile.id)); window.sessionStorage.removeItem(`${savedKey()}:pending`) }
      catch { /* Metadata không ảnh hưởng việc ghi nhận profile server. */ }
      message.value = `Đã xác nhận mẫu #${profile.id} (version ${profile.version}). Bản chỉnh sửa hiện tại vẫn được giữ.`
    }
    catch (reason) { if (!disposed && currentSequence === sequence) message.value = formatAiError(reason) }
    finally { if (currentSequence === sequence) restoring.value = false }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Xóa bản duyệt cục bộ khi chuyển sang văn phong mới.
   * Input: thao tác sau lưu thành công hoặc xác nhận bỏ bản đang sửa.
   * Output: boolean đã reset; form/profile/errors/defaultError trở về trạng thái đầu.
   * SIDE EFFECT: loại response cũ; giữ profile database, Settings/default và ID lịch sử.
   * Không bỏ trạng thái POST lưu chưa xác định hoặc request đang chạy.
   * =====================================================================
   */
  function reset() {
    if (saving.value || restoring.value || uncertainSave.value || run.running.value || disposed) return false
    sequence++
    preparedAnalysisId = null
    observedAnalysisId = null
    profileOverrideId.value = null
    form.value = emptyProfileForm()
    savedProfile.value = null
    errors.value = {}
    message.value = ''
    defaultError.value = ''
    conflict.value = false

    return true
  }

  onScopeDispose(() => { disposed = true; sequence++ })

  return { form, savedProfile, saving, restoring, errors, message, defaultError, conflict, uncertainSave, defaultProfileId, canSave, save, loadProfile, reloadProfile, recoverSave, reset }
}
