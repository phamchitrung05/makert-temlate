/**
 * =====================================================================
 * CHỨC NĂNG FILE: Pinia store cho Social Post (marketing).
 * =====================================================================
 *
 * Store giữ list/detail social post, statistics và các option form (products,
 * campaigns, accounts). Mọi lifecycle action (generate/approve/publish...) và
 * CRUD đi qua socialPostService; composable `useSocialPosts` giờ chỉ giữ
 * filter + điều phối UI, còn state chia sẻ nằm ở đây.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - CRUD chuẩn: fetchPosts/fetchPost/createPost/updatePost/removePost.
 * - fetchStats/fetchOptions: tải statistics + products/campaigns/accounts.
 * - generate/regenerateCaption/regenerateImage/generateFacebookContent/
 *   generateFacebookImages/regenerateImageSlot/replaceFacebookImages/
 *   reorderFacebookImages/updateFacebookContent: component generation.
 * - submitReview/approve/reject/requestRevision/publish: lifecycle.
 * - versions/variants: tải lịch sử và biến thể platform.
 * - connectFacebook: kết nối tài khoản MXH.
 * - reset(): dọn state.
 *
 * INPUT/OUTPUT CỦA FILE (tổng thể):
 * - INPUT : query/id/payload từ social-post pages và dialogs.
 * - OUTPUT: state readonly (items/stats/options/current) + action Promise.
 * - SIDE EFFECT: gọi socialPostService; generation/lifecycle thay đổi current.
 * =====================================================================
 */
/* eslint-disable camelcase -- Laravel Social Post API fields keep snake_case. */
import { readonly, shallowRef } from 'vue'
import { socialPostService } from '@/services/socialPost'
import { createCrudStore } from '@/stores/createCrudStore'

export const useSocialPostStore = createCrudStore('socialPost', socialPostService, {
  listDefaults: { per_page: 12 },
  readItems: response => response?.items ?? [],
  readTotal: response => response?.pagination?.total ?? response?.items?.length ?? 0,
  readPagination: response => response?.pagination ?? null,

  extraActions(service, core) {
    const stats = shallowRef({})
    const products = shallowRef([])
    const campaigns = shallowRef([])
    const accounts = shallowRef([])
    const optionsLoading = shallowRef(false)

    /** Output: statistics của list (đếm theo trạng thái). */
    const fetchStats = async () => {
      const data = await service.list({ per_page: 1 })

      stats.value = data?.stats ?? {}
    }

    /** Output: options cho form tạo (products/campaigns/accounts). */
    const fetchOptions = async () => {
      optionsLoading.value = true
      try {
        const [productsData, campaignsData, accountsData] = await Promise.all([
          service.products({ per_page: 100 }),
          service.campaigns({ per_page: 100 }),
          service.accounts(),
        ])

        products.value = productsData?.items ?? productsData ?? []
        campaigns.value = campaignsData?.items ?? campaignsData ?? []
        accounts.value = Array.isArray(accountsData) ? accountsData : accountsData?.items ?? []
      }
      finally {
        optionsLoading.value = false
      }
    }

    const lifecycle = method => (id, payload = {}) =>
      core.actions.runMutation(() => service[method](id, payload))

    return {
      // State riêng
      stats: readonly(stats),
      products: readonly(products),
      campaigns: readonly(campaigns),
      accounts: readonly(accounts),
      optionsLoading: readonly(optionsLoading),

      // CRUD alias
      fetchPosts: core.actions.fetchItems,
      fetchPost: core.actions.fetchItem,
      createPost: core.actions.createItem,
      updatePost: core.actions.updateItem,
      removePost: core.actions.removeItem,

      // Options + stats
      fetchStats,
      fetchOptions,

      // Chi tiết phụ
      fetchVersions: id => service.versions(id),
      fetchVariants: id => service.variants(id),

      // Generation
      generate: lifecycle('generate'),
      regenerateCaption: lifecycle('regenerateCaption'),
      regenerateImage: lifecycle('regenerateImage'),
      updateFacebookContent: lifecycle('updateFacebookContent'),
      generateFacebookContent: lifecycle('generateFacebookContent'),
      generateFacebookImages: lifecycle('generateFacebookImages'),
      regenerateImageSlot: (id, slot, payload = {}) =>
        core.actions.runMutation(() => service.regenerateImageSlot(id, slot, payload)),
      replaceFacebookImages: (id, assetIds) =>
        core.actions.runMutation(() => service.replaceFacebookImages(id, assetIds)),
      reorderFacebookImages: (id, usageIds) =>
        core.actions.runMutation(() => service.reorderFacebookImages(id, usageIds)),

      // Lifecycle
      submitReview: id => core.actions.runMutation(() => service.submitReview(id)),
      approve: id => core.actions.runMutation(() => service.approve(id)),
      reject: (id, reason = '') => core.actions.runMutation(() => service.reject(id, reason)),
      requestRevision: (id, reason = '') => core.actions.runMutation(() => service.requestRevision(id, reason)),
      publish: id => core.actions.runMutation(() => service.publish(id)),

      // MXH
      connectFacebook: () => service.connectFacebook(),
    }
  },
})
