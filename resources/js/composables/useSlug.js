/**
 * =====================================================================
 * CHỨC NĂNG FILE: Preview slug dùng chung cho mọi model được allowlist.
 * =====================================================================
 *
 * Composable nhận title/model type/model ID dạng plain value, ref hoặc getter.
 * API backend chịu trách nhiệm collision suffix và quyền theo model config.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - useSlug(): tạo state slug/loading/error và actions reset/generate
 * - slugErrorMessage(): chuyển lỗi API thành thông báo theo model
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : title, modelType, modelId và nhãn model tùy chọn.
 * - OUTPUT: slug readonly, trạng thái request và actions preview/reset.
 * =====================================================================
 */
import { computed, onScopeDispose, readonly, shallowRef, toRef, toValue, watch } from 'vue'
import { useSlugStore } from '@/stores/slug'

const MODEL_LABELS = { post: 'bài viết', resource: 'tài nguyên', category: 'danh mục', tag: 'tag', technology: 'công nghệ' }

/** Input: lỗi transport/API và nhãn model. Output: thông báo an toàn cho người dùng. */
const slugErrorMessage = (requestError, modelLabel) => {
  const responseData = requestError?.data || requestError?.response?._data || requestError?.response?.data || {}
  const responseMessage = responseData?.message || requestError?.message
  const responseCode = responseData?.meta?.code || responseData?.code
  const status = requestError?.statusCode || requestError?.status || requestError?.response?.status || responseData?.status
  const label = modelLabel || 'model này'

  if (status === 403 && responseMessage === 'Invalid ability provided.')
    return 'Token quản trị không có ability admin. Hãy đăng xuất và đăng nhập lại để cấp token mới.'
  if (status === 403 && responseCode === 'SLUG_PERMISSION_DENIED')
    return `Tài khoản không có quyền tạo slug cho ${label}.`

  const messages = {
    401: 'Phiên đăng nhập đã hết hạn. Hãy đăng nhập lại để tạo slug.',
    403: `Tài khoản không có quyền tạo slug cho ${label}.`,
    404: 'Không tìm thấy API Slug hoặc model đang sửa. Hãy tải lại trang và kiểm tra backend.',
    422: 'Dữ liệu tạo slug không hợp lệ. Kiểm tra tiêu đề (tối đa 255 ký tự) và model đang sửa.',
    429: 'Bạn đang tạo slug quá nhanh. Vui lòng chờ một phút rồi thử lại.',
  }

  if (messages[status])
    return messages[status]
  if (requestError?.message === 'SLUG_RESPONSE_INVALID')
    return 'API Slug trả dữ liệu không hợp lệ. Kiểm tra cấu hình API/backend.'
  if (status >= 500)
    return 'Máy chủ gặp lỗi khi tạo slug. Vui lòng kiểm tra backend và thử lại.'

  return 'Không tạo được slug do lỗi kết nối. Bỏ focus tiêu đề để thử lại; khi lưu backend sẽ kiểm tra lại.'
}
/**
 * Input: options chứa MaybeRef/MaybeRefOrGetter title/modelType/modelId.
 * Output: state readonly và actions preview/reset; request cũ bị vô hiệu khi input đổi.
 */
export function useSlug({ title, modelType, modelId = null, modelLabel = null } = {}) {
  const slugStore = useSlugStore()
  const titleRef = toRef(title ?? '')
  const modelTypeRef = toRef(modelType ?? 'post')
  const modelIdRef = toRef(modelId)
  const modelLabelRef = toRef(modelLabel)
  const slug = shallowRef('')
  const loading = shallowRef(false)
  const error = shallowRef('')
  const checkedTitle = shallowRef(null)
  let sequence = 0
  let controller
  let pending

  /** Input: không có. Output: hủy request và xóa preview cũ. */
  const invalidate = () => {
    sequence++
    controller?.abort()
    pending = null
    loading.value = false
    error.value = ''
    checkedTitle.value = null
    slug.value = ''
  }

  /** Input: model API tùy chọn. Output: seed slug đã lưu, không gọi API. */
  const reset = model => {
    invalidate()
    slug.value = model?.slug || ''
    checkedTitle.value = slug.value ? String(toValue(titleRef) || '').trim() : null
  }

  watch([titleRef, modelTypeRef, modelIdRef], invalidate, { flush: 'sync' })
  onScopeDispose(invalidate)

  const checked = computed(() => Boolean(slug.value) && checkedTitle.value === String(toValue(titleRef) || '').trim())

  /** Input: title hiện tại. Output: Promise preview slug; lỗi hiển thị tại caller. */
  const generate = () => {
    const source = String(toValue(titleRef) || '').trim()
    if (!source) {
      invalidate()

      return Promise.resolve()
    }
    if (checked.value)
      return Promise.resolve()
    if (pending)
      return pending

    const requestId = ++sequence
    const currentModelType = String(toValue(modelTypeRef) || '')
    const currentModelId = toValue(modelIdRef)
    const currentLabel = String(toValue(modelLabelRef) || MODEL_LABELS[currentModelType] || 'model này')

    controller = new AbortController()
    loading.value = true
    error.value = ''
    pending = slugStore.generateSlug({ title: source, modelType: currentModelType, modelId: currentModelId }, { signal: controller.signal })
      .then(result => {
        if (requestId !== sequence || source !== String(toValue(titleRef) || '').trim() || currentModelId !== toValue(modelIdRef) || currentModelType !== String(toValue(modelTypeRef) || ''))
          return
        slug.value = result.slug
        checkedTitle.value = source
      })
      .catch(requestError => {
        if (requestId === sequence)
          error.value = slugErrorMessage(requestError, currentLabel)
      })
      .finally(() => {
        if (requestId === sequence) {
          loading.value = false
          pending = null
        }
      })

    return pending
  }

  return { slug: readonly(slug), loading: readonly(loading), error: readonly(error), checked, reset, generate }
}
