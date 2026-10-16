/* eslint-disable camelcase -- Query keys follow the Laravel Social Post contract. */

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Điều phối state và thao tác API của Social Post list.
 * =====================================================================
 * Composable tách network/state khỏi route view; component chỉ nhận state và
 * gọi action rõ ràng. API vẫn giữ canonical caption cho mọi platform.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - load(): tải list/context/accounts.
 * - create(): tạo draft và queue AI.
 * - refresh(): tải lại list giữ filter/page.
 * - generate()/regenerateCaption()/regenerateImage(): queue component.
 * - review()/approve()/reject()/publish(): gọi lifecycle.
 * - updatePost()/remove(): CRUD mutation.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : filters và payload từ Social Post UI.
 * - OUTPUT: reactive items/loading/error/stats và actions.
 * - SIDE EFFECT: gọi socialPostService; không tự mutate child props.
 * =====================================================================
 */
import { computed, readonly, shallowRef } from 'vue'
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

  const visibleItems = computed(() => _items.value)

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const [list, products, campaigns, accounts] = await Promise.all([
        socialPostService.list({ ...filters.value, page: page.value, per_page: itemsPerPage.value }),
        socialPostService.products({ per_page: 100 }),
        socialPostService.campaigns({ per_page: 100 }),
        socialPostService.accounts(),
      ])

      _items.value = list.items
      _stats.value = list.stats
      total.value = list.pagination?.total ?? list.items.length
      _products.value = products?.items ?? products ?? []
      _campaigns.value = campaigns?.items ?? campaigns ?? []
      _accounts.value = accounts ?? []
    } catch (cause) {
      error.value = cause?.data?.message ?? cause?.message ?? 'Không tải được Social Post.'
    } finally {
      loading.value = false
    }
  }

  async function run(action) {
    error.value = ''
    try {
      await action()
      await load()
    } catch (cause) {
      error.value = cause?.data?.message ?? cause?.message ?? 'Thao tác Social Post thất bại.'
      throw cause
    }
  }

  return {
    items: readonly(visibleItems), stats: readonly(_stats), products: readonly(_products),
    campaigns: readonly(_campaigns), accounts: readonly(_accounts), loading: readonly(loading),
    error: readonly(error), page, itemsPerPage, total, filters,
    load,
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
