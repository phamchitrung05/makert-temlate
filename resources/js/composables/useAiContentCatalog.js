/**
 * =====================================================================
 * CHỨC NĂNG FILE: Tải catalog AI Settings và đồng bộ lựa chọn trong Ai Content.
 * =====================================================================
 * Dùng useAiProviderSettings để đọc catalog backend; không gọi provider thật.
 *
 * CÁC HÀM/METHOD TRONG FILE:
 * - availableModels(): lọc model đã bật và khả dụng của một provider.
 * - useAiContentCatalog(): quản lý catalog và lựa chọn theo source ref của page.
 * - activeProviders/selectedProvider/catalog: options, capability và trạng thái tải.
 * - reconcileSelection(): giữ lựa chọn hợp lệ hoặc dùng default/option đầu tiên.
 * - loadCatalog(): tải lại qua service chung và giữ lỗi để form hiển thị/retry.
 * - watcher catalog/source: loại lựa chọn không còn thuộc provider/catalog.
 *
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : source ref có provider key và remote model ID.
 * - OUTPUT: catalog reactive, loadCatalog và source đã đồng bộ với catalog.
 * - SIDE EFFECT: GET Admin API và cập nhật lựa chọn cục bộ; không ghi settings.
 * =====================================================================
 */
/* eslint-disable camelcase -- Catalog fields follow the Laravel API contract. */
import { computed, watch } from 'vue'
import { useAiProviderSettings } from './useAiProviderSettings'

/** Input: provider DTO. Output: model đã bật/khả dụng; không đoán capability từ tên. */
const availableModels = provider => (provider?.models ?? []).filter(model => model.is_enabled && model.is_available)

/** Input: source ref của page. Output: catalog và hàm tải lại; watcher cập nhật lựa chọn cục bộ. */
export function useAiContentCatalog(source) {
  const { providers, settings, loading, error, load } = useAiProviderSettings()
  const activeProviders = computed(() => providers.value.filter(provider => provider.is_active && provider.has_api_key))
  const selectedProvider = computed(() => activeProviders.value.find(provider => provider.key === source.value.provider))

  const catalog = computed(() => ({
    loading: loading.value,
    error: error.value,
    providerOptions: activeProviders.value.map(provider => ({ title: provider.name, value: provider.key })),
    modelOptions: availableModels(selectedProvider.value).map(model => ({
      title: model.label || model.remote_model_id,
      value: model.remote_model_id,
    })),
    selectedModel: availableModels(selectedProvider.value).find(model => model.remote_model_id === source.value.model) ?? null,
  }))

  /**
   * Input: catalog/default mới hoặc lựa chọn source thay đổi.
   * Output: giữ provider/model hợp lệ; fallback theo default text rồi option khả dụng đầu tiên.
   * Side effect: cập nhật source bằng bản sao; không sửa DTO catalog hoặc gọi API.
   */
  function reconcileSelection() {
    const defaultProvider = activeProviders.value.find(provider => availableModels(provider)
      .some(model => model.id === settings.value.default_text_model_id && model.capabilities?.includes('text_generation')))

    const provider = selectedProvider.value
      ?? defaultProvider
      ?? activeProviders.value.find(item => availableModels(item).some(model => model.capabilities?.includes('text_generation')))
      ?? activeProviders.value.find(item => availableModels(item).length)
      ?? activeProviders.value[0]

    const models = availableModels(provider)

    const model = models.find(item => item.remote_model_id === source.value.model)
      ?? models.find(item => item.id === settings.value.default_text_model_id)
      ?? models.find(item => item.capabilities?.includes('text_generation'))
      ?? models[0]

    const providerKey = provider?.key ?? ''
    const modelId = model?.remote_model_id ?? ''

    if (source.value.provider !== providerKey || source.value.model !== modelId)
      source.value = { ...source.value, provider: providerKey, model: modelId }
  }

  watch([activeProviders, settings, () => source.value.provider, () => source.value.model], reconcileSelection)

  /** Input: mở page/tải lại. Output: catalog mới hoặc error để retry; không reject event handler. */
  async function loadCatalog() {
    try {
      await load()
    }
    catch {
      // useAiProviderSettings đã lưu error; form hiển thị và cho phép tải lại.
    }
  }

  return { catalog, loadCatalog }
}
