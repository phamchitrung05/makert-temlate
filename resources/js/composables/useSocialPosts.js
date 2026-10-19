/* eslint-disable camelcase -- Query keys follow the Laravel Social Post contract. */

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối state và thao tác API của Social Post list.
 * =====================================================================
 * Composable tách network/state khỏi route view; component chỉ nhận state và
 * gọi action rõ ràng. API vẫn giữ canonical caption cho mọi platform.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - useSocialPosts(): tạo state và action của danh sách.
 * - load(): tải list/statistics theo filter và phân trang; chỉ Facebook có API.
 * - loadOptions(): tải campaign/account khi mở form tạo.
 * - run(): gọi mutation, tải lại list và trả kết quả hoặc lỗi.
 * - create(): tạo draft và queue AI.
 * - refresh(): tải lại list giữ filter/page.
 * - generate()/regenerateCaption()/regenerateImage(): queue component.
 * - submitReview()/approve()/reject()/publish(): gọi lifecycle.
 * - updatePost()/remove(): CRUD mutation.
 * - visibleItems: danh sách server hiện tại.
 * - onScopeDispose: bỏ qua response khi rời trang.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : filters và payload từ Social Post UI.
 * - OUTPUT: reactive items/loading/error/stats và actions.
 * - SIDE EFFECT: gọi socialPostService; không tự mutate child props.
 * =====================================================================
 */
import { computed, onScopeDispose, readonly, shallowRef } from 'vue'
import { socialPostService } from '@/services/socialPost'

/**
 * Tạo state Social Post cho route list.
 *
 * Input: không bắt buộc; caller điều khiển lifecycle qua actions.
 * Output: readonly state và các action async.
 */
export function useSocialPosts() {
  const _items = shallowRef([])
  const _stats = shallowRef({})
  const _products = shallowRef([])
  const _campaigns = shallowRef([])
  const _accounts = shallowRef([])
  const loading = shallowRef(false)
  const error = shallowRef('')
  const page = shallowRef(1)
  const itemsPerPage = shallowRef(12)
  const total = shallowRef(0)
  const filters = shallowRef({ search: '', review_status: '', generation_status: '', platform: '' })
  let loadSequence = 0

  const visibleItems = computed(() => _items.value)

  // Input: scope bị hủy. Output: response đang chờ không cập nhật trang đã đóng.
  onScopeDispose(() => { loadSequence++ })

  /**
   * Input: filters/page/itemsPerPage. Output: list, thống kê và loading/error.
   * Side effect: đọc API; bỏ qua request cũ và platform chưa có backend.
   */
  async function load() {
    const sequence = ++loadSequence

    error.value = ''
    if (filters.value.platform && filters.value.platform !== 'facebook') {
      loading.value = false

      return
    }
    loading.value = true
    try {
      const list = await socialPostService.list({ ...filters.value, page: page.value, per_page: itemsPerPage.value })

      if (sequence !== loadSequence) return
      _items.value = list.items
      _stats.value = list.stats
      total.value = list.pagination?.total ?? list.items.length
    } catch (cause) {
      if (sequence === loadSequence)
        error.value = cause?.data?.message ?? cause?.message ?? 'Không tải được Social Post.'
    } finally {
      if (sequence === loadSequence)
        loading.value = false
    }
  }

  /**
   * Input: mở form tạo bài. Output: true khi options tải đủ, false khi có lỗi.
   * Side effect: đọc options legacy/campaign/account; Product không được đưa vào payload.
   */
  async function loadOptions() {
    error.value = ''
    try {
      const [products, campaigns, accounts] = await Promise.all([
        socialPostService.products({ per_page: 100 }),
        socialPostService.campaigns({ per_page: 100 }),
        socialPostService.accounts(),
      ])

      _products.value = products?.items ?? products ?? []
      _campaigns.value = campaigns?.items ?? campaigns ?? []
      _accounts.value = accounts ?? []

      return true
    } catch (cause) {
      error.value = cause?.data?.message ?? cause?.message ?? 'Không tải được dữ liệu tạo Social Post.'

      return false
    }
  }

  /**
   * Input: callback mutation. Output: kết quả API; ném lại lỗi để caller giữ form.
   * Side effect: ghi dữ liệu qua API, tải lại list, lưu thông báo lỗi.
   */
  async function run(action) {
    error.value = ''
    try {
      const result = await action()

      await load()

      return result
    } catch (cause) {
      error.value = cause?.data?.message ?? cause?.message ?? 'Thao tác Social Post thất bại.'
      throw cause
    }
  }

  return {
    items: readonly(visibleItems), stats: readonly(_stats), products: readonly(_products),
    campaigns: readonly(_campaigns), accounts: readonly(_accounts), loading: readonly(loading),
    error: readonly(error), page, itemsPerPage, total, filters,
    load, loadOptions,
    refresh: load,
    create: payload => run(() => socialPostService.create(payload)),
    updatePost: (id, payload) => run(() => socialPostService.update(id, payload)),
    remove: id => run(() => socialPostService.remove(id)),
    generate: id => run(() => socialPostService.generate(id)),
    regenerateCaption: id => run(() => socialPostService.regenerateCaption(id)),
    regenerateImage: id => run(() => socialPostService.regenerateImage(id)),
    submitReview: id => run(() => socialPostService.submitReview(id)),
    approve: id => run(() => socialPostService.approve(id)),
    reject: (id, reason) => run(() => socialPostService.reject(id, reason)),
    publish: id => run(() => socialPostService.publish(id)),
  }
}
