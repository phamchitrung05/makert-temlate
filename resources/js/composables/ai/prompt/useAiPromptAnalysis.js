/* eslint-disable camelcase -- Analysis DTO giữ field Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Submit, polling, hủy và resume analysis văn phong hiện có.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: useAiPromptAnalysis(), storageKey(), remember(),
 * stop(), accept(), poll(), resumePolling(), analyze(), cancel(), restore(), allowNewSubmission(), reset().
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : nguồn reactive và actor hiện tại.
 * - OUTPUT: lifecycle/result/lỗi; nguồn đã gửi bất biến trong lượt phân tích.
 * - SIDE EFFECT: POST một lần theo click, GET backoff, sessionStorage chỉ UUID.
 * =====================================================================
 */
import { computed, onScopeDispose, shallowRef, toValue } from 'vue'
import { aiWritingProfilesService } from '@/services/aiWritingProfiles'
import { formatAiError } from '@/utils/aiErrors'

const activeStatuses = ['queued', 'analyzing']
const delays = [2000, 3000, 5000, 8000]

/**
 * =====================================================================
 * CHỨC NĂNG: Điều phối một analysis tại trang, chặn response/timer đã cũ.
 * Input: source composable, actor ref/getter. Output: state và actions.
 * SIDE EFFECT: dispose dừng GET; không tự hủy tác vụ đang chạy ở backend.
 * =====================================================================
 */
export function useAiPromptAnalysis(source, actorId) {
  const analysis = shallowRef(null)
  const submittedText = shallowRef(null)
  const submitting = shallowRef(false)
  const cancelling = shallowRef(false)
  const polling = shallowRef(false)
  const paused = shallowRef(false)
  const statusOverride = shallowRef('')
  const errors = shallowRef({})
  const message = shallowRef('')
  const uncertainSubmit = shallowRef(false)
  let timer
  let controller
  let sequence = 0
  let attempt = 0
  let disposed = false

  const status = computed(() => statusOverride.value || analysis.value?.status || 'idle')
  const running = computed(() => submitting.value || cancelling.value || activeStatuses.includes(status.value))
  const sourceChanged = computed(() => Boolean(analysis.value) && (submittedText.value === null ? Boolean(source.text.value) : source.text.value !== submittedText.value))

  /**
   * =====================================================================
   * CHỨC NĂNG: Tách resume UUID theo tài khoản, không dùng token làm key.
   * Input: actor getter. Output: session key hoặc null nếu chưa authenticated.
   * =====================================================================
   */
  const storageKey = () => toValue(actorId) ? `ai_prompt_analysis:${toValue(actorId)}` : null

  /**
   * =====================================================================
   * CHỨC NĂNG: Nhớ UUID/hạn lưu để GET lại sau reload.
   * Input: analysis hiện tại. Output: session metadata; không lưu nguồn/result.
   * SIDE EFFECT: sessionStorage tùy khả dụng, không làm lỗi chức năng chính.
   * =====================================================================
   */
  function remember() {
    try {
      const key = storageKey()
      if (key && analysis.value) {
        window.sessionStorage.setItem(key, JSON.stringify({ id: analysis.value.id, expiresAt: analysis.value.expires_at }))
        window.sessionStorage.removeItem(`${key}:pending`)
      }
    }
    catch { /* Browser có thể chặn sessionStorage; vẫn dùng state của trang. */ }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Dừng timer và GET cũ trước resume/hủy/unmount.
   * Input: không có. Output: GET không còn được ghi state từ sequence cũ.
   * =====================================================================
   */
  function stop() {
    sequence++
    clearTimeout(timer)
    controller?.abort()
    polling.value = false
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Nhận DTO server, không tự đổi lỗi mạng thành model failed.
   * Input: analysis DTO. Output: lifecycle mới và metadata resume.
   * =====================================================================
   */
  function accept(value) {
    analysis.value = value
    statusOverride.value = ''
    remember()
    if (value.status === 'failed') message.value = value.error_message || 'Phân tích thất bại. Bạn có thể sửa nguồn và phân tích lại.'
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: GET tuần tự với backoff đến terminal hoặc lỗi cần người dùng xử lý.
   * Input: UUID/sequence hiện hành. Output: result hoặc trạng thái tạm dừng.
   * GET 403/404/410 dừng resume và mở khóa form; lỗi mạng vẫn giữ tác vụ đang chạy.
   * SIDE EFFECT: một GET mỗi lượt; không tự POST khi GET lỗi.
   * =====================================================================
   */
  async function poll(id, currentSequence) {
    if (disposed || currentSequence !== sequence) return
    controller = new AbortController()
    polling.value = true
    try {
      const value = await aiWritingProfilesService.analysis(id, controller.signal)
      if (disposed || currentSequence !== sequence) return
      accept(value)
      paused.value = false
      if (value.status !== 'failed') message.value = ''
      if (activeStatuses.includes(value.status)) timer = setTimeout(() => poll(id, currentSequence), delays[Math.min(attempt++, delays.length - 1)])
    }
    catch (reason) {
      if (disposed || currentSequence !== sequence) return
      const httpStatus = Number(reason?.status ?? reason?.statusCode ?? reason?.response?.status)

      if ([403, 404, 410].includes(httpStatus)) {
        paused.value = false
        statusOverride.value = httpStatus === 403 ? 'forbidden' : 'expired'
        message.value = httpStatus === 403
          ? formatAiError(reason, 'Bạn không có quyền mở phân tích này. Có thể bắt đầu văn phong mới.')
          : 'Phân tích đã hết hạn hoặc không còn được lưu.'
        try { if (storageKey()) window.sessionStorage.removeItem(storageKey()) }
        catch { /* Không phụ thuộc quyền ghi storage. */ }
      }
      else {
        paused.value = true
        message.value = formatAiError(reason, 'Không đọc được tiến trình. Bấm Kiểm tra lại để tiếp tục tác vụ hiện có.')
      }
    }
    finally {
      if (currentSequence === sequence) polling.value = false
    }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Tiếp tục GET của UUID đang có, không tạo thêm analysis.
   * Input: thao tác người dùng hoặc sau POST/restore. Output: polling sequence mới.
   * =====================================================================
   */
  function resumePolling() {
    if (!analysis.value?.id || disposed) return
    stop()
    attempt = 0
    paused.value = false
    void poll(analysis.value.id, sequence)
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Kiểm nguồn/tên/model rồi POST đúng một lần.
   * Input: state nguồn hiện tại. Output: queued analysis hoặc lỗi field.
   * SIDE EFFECT: chụp text trước POST; timeout không tự replay vì API chưa idempotent.
   * =====================================================================
   */
  async function analyze() {
    if (running.value || source.previewing.value || disposed || uncertainSubmit.value) return
    errors.value = {}
    message.value = ''

    const name = source.profileName.value.trim()
    const text = source.text.value

    if (!name || Array.from(name).length > 160) errors.value = { name: ['Nhập tên văn phong, tối đa 160 ký tự.'] }
    if (Array.from(text).length < 30 || Array.from(text).length > 100000) errors.value = { ...errors.value, reference_text: ['Bài tham khảo cần từ 30 đến 100000 ký tự.'] }
    if (!source.sourcePrepared.value) errors.value = { ...errors.value, reference_text: ['Đọc lại URL/file đã chọn trước khi phân tích.'] }
    if (source.modelId.value !== null && !source.modelOptions.value.some(model => model.value === source.modelId.value)) errors.value = { ...errors.value, model_id: ['Chọn model text đang bật và khả dụng.'] }
    if (Object.keys(errors.value).length) return
    stop()

    const currentSequence = sequence

    submitting.value = true
    analysis.value = null
    statusOverride.value = ''
    submittedText.value = text
    try { if (storageKey()) window.sessionStorage.setItem(`${storageKey()}:pending`, 'true') }
    catch { /* Chỉ lưu cờ chưa rõ kết quả, không lưu bài mẫu hoặc token. */ }
    try {
      const value = await aiWritingProfilesService.createAnalysis({ name, reference_text: text, ...(source.modelId.value ? { model_id: source.modelId.value } : {}) })
      if (disposed || currentSequence !== sequence) return
      accept(value)
      resumePolling()
    }
    catch (reason) {
      if (disposed || currentSequence !== sequence) return
      errors.value = reason?.data?.errors ?? {}
      message.value = formatAiError(reason, 'Không gửi được bài mẫu để phân tích.')

      const httpStatus = Number(reason?.status ?? reason?.statusCode ?? reason?.response?.status)

      if (httpStatus >= 400 && httpStatus < 500) {
        try { if (storageKey()) window.sessionStorage.removeItem(`${storageKey()}:pending`) }
        catch { /* Validation xác định không cần khóa gửi qua reload. */ }
      }
      if (!Number(reason?.status ?? reason?.statusCode ?? reason?.response?.status) || Number(reason?.status ?? reason?.statusCode ?? reason?.response?.status) >= 500) {
        uncertainSubmit.value = true
        message.value += ' Chưa xác định tác vụ đã được tạo hay chưa. Không gửi lại tự động; kiểm tra tác vụ trước khi phân tích mới.'
      }
    }
    finally { if (!disposed) submitting.value = false }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Hủy và nhận trạng thái server, chặn poll cũ ghi đè cancelled.
   * Input: analysis active. Output: cancelled hoặc terminal server đã hoàn thành.
   * =====================================================================
   */
  async function cancel() {
    if (!analysis.value?.id || cancelling.value || !activeStatuses.includes(status.value)) return
    stop()

    const currentSequence = sequence

    cancelling.value = true
    try {
      const value = await aiWritingProfilesService.cancel(analysis.value.id)
      if (disposed || currentSequence !== sequence) return
      accept(value)
      message.value = value.status === 'cancelled' ? 'Đã hủy phân tích. Request model đã gửi có thể vẫn được tính phí.' : ''
      if (activeStatuses.includes(value.status)) resumePolling()
    }
    catch (reason) {
      if (disposed || currentSequence !== sequence) return
      paused.value = true
      message.value = formatAiError(reason, 'Không hủy được tác vụ. Kiểm tra lại trạng thái.')
    }
    finally { if (!disposed) cancelling.value = false }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Đọc UUID đã nhớ hoặc UUID người dùng nhập để resume.
   * Input: UUID tùy chọn. Output: GET detail; không khôi phục bài nguồn từ mock.
   * SIDE EFFECT: chỉ GET; backend kiểm owner và expiry.
   * =====================================================================
   */
  function restore(id = null) {
    if (submitting.value || cancelling.value || disposed) return
    try {
      if (!id && window.sessionStorage.getItem(`${storageKey()}:pending`)) {
        uncertainSubmit.value = true
        message.value = 'Yêu cầu phân tích trước chưa có kết quả xác định. Kiểm tra tác vụ trước khi cho phép một lượt mới.'

        return
      }
      const stored = id ? { id } : JSON.parse(window.sessionStorage.getItem(storageKey()) || 'null')
      if (!stored?.id || !/^[\da-f]{8}(?:-[\da-f]{4}){3}-[\da-f]{12}$/iu.test(stored.id)) return
      stop()
      analysis.value = { id: stored.id, status: 'queued', name: '' }
      submittedText.value = null
      uncertainSubmit.value = false
      resumePolling()
    }
    catch { /* Metadata không hợp lệ không ngăn người dùng tạo tác vụ mới. */ }
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Cho phép một lượt mới sau khi người dùng xác nhận lỗi POST bất định.
   * Input: thao tác xác nhận trong UI. Output: bỏ khóa gửi; chưa tự gọi model.
   * =====================================================================
   */
  function allowNewSubmission() {
    uncertainSubmit.value = false
    try { if (storageKey()) window.sessionStorage.removeItem(`${storageKey()}:pending`) }
    catch { /* Bỏ khóa hiện tại vẫn có hiệu lực khi storage bị chặn. */ }
    message.value = 'Bạn đã cho phép tạo lượt mới. Bấm Phân tích khi đã sẵn sàng.'
  }

  /**
   * =====================================================================
   * CHỨC NĂNG: Bỏ kết quả hiện tại và resume tự động để bắt đầu văn phong mới.
   * Input: thao tác tạo mới; chỉ cho phép khi analysis đã dừng và POST xác định.
   * Output: boolean đã reset; status idle, result/errors/snapshot nguồn trống.
   * SIDE EFFECT: dừng GET/timer và xóa UUID resume của actor trong sessionStorage.
   * Không xóa analysis server hoặc metadata profile đã lưu theo UUID lịch sử.
   * =====================================================================
   */
  function reset() {
    if (running.value || uncertainSubmit.value || disposed) return false
    stop()
    analysis.value = null
    submittedText.value = null
    statusOverride.value = ''
    paused.value = false
    errors.value = {}
    message.value = ''
    attempt = 0
    try {
      const key = storageKey()

      if (key) {
        window.sessionStorage.removeItem(key)
        window.sessionStorage.removeItem(`${key}:pending`)
      }
    }
    catch { /* Form mới vẫn hoạt động khi storage bị chặn. */ }

    return true
  }

  onScopeDispose(() => { disposed = true; stop() })

  return { analysis, submittedText, submitting, cancelling, polling, paused, errors, message, uncertainSubmit,
    status, running, sourceChanged, analyze, cancel, resumePolling, restore, allowNewSubmission, reset }
}
