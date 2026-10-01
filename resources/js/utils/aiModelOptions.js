/**
 * =====================================================================
 * CHỨC NĂNG FILE: Chuẩn hóa provider/model options cho các AI selector Vue.
 * =====================================================================
 * CÁC HÀM/METHOD: normalizeProvider(), providerModels(), findProvider().
 * INPUT: catalog API gồm model_options hoặc mảng model ID legacy.
 * OUTPUT: option shape thống nhất cho Vuetify; không tự chọn default server.
 * SIDE EFFECT: không gọi API, không mutate input và không giữ state.
 * EXCEPTION/TRANSACTION: không ném lỗi nghiệp vụ hoặc mở transaction.
 * =====================================================================
 */

/**
 * =====================================================================
 * CHỨC NĂNG: provider với options immutable.
 * =====================================================================
 * INPUT: provider metadata.
 * OUTPUT: provider với options immutable.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
/* eslint-disable camelcase -- Catalog DTOs preserve the Laravel API contract. */
export function normalizeProvider(provider = {}) {
  const modelOptions = Array.isArray(provider.model_options) && provider.model_options.length
    ? provider.model_options
    : (provider.models ?? []).map(value => ({ id: null, value, label: value, capabilities: [] }))

  return { ...provider, model_options: modelOptions.map(option => ({ ...option })) }
}

/**
 * =====================================================================
 * CHỨC NĂNG: only enabled capability models.
 * =====================================================================
 * INPUT: provider/capability.
 * OUTPUT: only enabled capability models.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
export function providerModels(provider, capability = null) {
  if (!provider) return []

  return normalizeProvider(provider).model_options.filter(model => {
    if (model.enabled === false || model.available === false) return false

    return !capability || (model.capabilities ?? []).includes(capability)
  })
}

/**
 * =====================================================================
 * CHỨC NĂNG: normalized selected provider or null.
 * =====================================================================
 * INPUT: provider list/key.
 * OUTPUT: normalized selected provider or null.
 * SIDE EFFECT: Không ghi database hoặc gọi provider.
 * EXCEPTION/TRANSACTION: Không mở transaction.
 * =====================================================================
 */
export function findProvider(providers, key) {
  return (providers ?? []).map(normalizeProvider).find(provider => provider.key === key) ?? null
}
