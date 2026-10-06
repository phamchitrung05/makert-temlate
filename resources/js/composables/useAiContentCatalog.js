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
 * - reconcileOutputs()/resetContentDefaults(): đồng bộ nhóm đầu ra từ config và defaults đã lưu.
 * - imageModelOptions/watcher ảnh: capability/driver/default ảnh riêng với model text.
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
import { computed, onScopeDispose, shallowRef, watch } from 'vue'
import { useAiProviderSettings } from './useAiProviderSettings'
import { aiAgentService } from '@/services/aiAgent'

/** Input: provider DTO. Output: model đã bật/khả dụng; không đoán capability từ tên. */
const availableModels = provider => (provider?.models ?? []).filter(model => model.is_enabled && model.is_available)

/** Input: source ref của page. Output: catalog và hàm tải lại; watcher cập nhật lựa chọn cục bộ. */
export function useAiContentCatalog(source) {
  const { providers, presets, settings, loading, error, load } = useAiProviderSettings()
  const targets = shallowRef([])
  const targetsLoading = shallowRef(false)
  const targetsError = shallowRef('')
  let targetVersion = 0
  const activeProviders = computed(() => providers.value.filter(provider => provider.is_active && provider.has_api_key))
  const selectedProvider = computed(() => activeProviders.value.find(provider => provider.key === source.value.provider))
  const selectedTarget = computed(() => targets.value.find(target => target.key === source.value.targetType))

  const outputDefinitions = computed(() => (selectedTarget.value?.output_options ?? [])
    .filter(option => selectedTarget.value.outputs?.includes(option.value)))

  const supportsSource = option => option.value === 'thumbnail'
    ? source.value.thumbnailMode === 'generate' || source.value.type === 'url'
    : !option.source_types?.length || option.source_types.includes(source.value.type)

  const imageModelOptions = computed(() => activeProviders.value
    .filter(provider => presets.value.some(preset => preset.key === provider.driver && preset.image_supported))
    .flatMap(provider => availableModels(provider).filter(model => model.capabilities?.includes('image_generation'))
      .map(model => ({ title: `${provider.name} · ${model.label || model.remote_model_id}`, value: model.id }))))

  const outputOptions = computed(() => outputDefinitions.value.map(option => ({
    ...option, props: { disabled: !supportsSource(option) },
  })))

  const contentDefaults = computed(() => ({ outputs: outputDefinitions.value
    .filter(option => supportsSource(option)
      && (option.value !== 'thumbnail' || settings.value.auto_thumbnail !== false)
      && (option.value !== 'seo' || settings.value.auto_seo === true))
    .map(option => option.value) }))

  let outputsEdited = Array.isArray(source.value.outputs)
  let applyingOutputs = false
  let restrictedPreferences = new Set()

  const sameOutputs = (left, right) => Array.isArray(left) && left.length === right.length
    && left.every((value, index) => value === right[index])

  watch(source, (value, previous) => {
    if (applyingOutputs || sameOutputs(value.outputs, previous.outputs ?? [])) return
    if (value.outputs === previous.outputs) return
    outputsEdited = Array.isArray(value.outputs)
    restrictedPreferences = new Set(outputDefinitions.value
      .filter(option => (option.source_types?.length || option.value === 'thumbnail') && value.outputs?.includes(option.value))
      .map(option => option.value))
  }, { flush: 'sync' })

  /** Input: target/source/settings mới. Output: chỉ giữ nhóm được config cho phép; không ghi đè lựa chọn người dùng. */
  function reconcileOutputs() {
    if (!selectedTarget.value || loading.value || targetsLoading.value) return
    const current = source.value.outputs ?? []

    for (const option of outputDefinitions.value) {
      if ((option.source_types?.length || option.value === 'thumbnail') && current.includes(option.value)) restrictedPreferences.add(option.value)
    }

    const outputs = !outputsEdited || source.value.outputs === null ? contentDefaults.value.outputs
      : outputDefinitions.value.filter(option => supportsSource(option)
        && (current.includes(option.value) || restrictedPreferences.has(option.value)))
        .map(option => option.value)

    restrictedPreferences = new Set([...restrictedPreferences].filter(value => outputDefinitions.value.some(option => option.value === value)))
    if (sameOutputs(source.value.outputs, outputs)) return
    applyingOutputs = true
    source.value = { ...source.value, outputs }
    applyingOutputs = false
  }

  /** Input: thao tác tạo form mới. Output: áp dụng lại lựa chọn mặc định mới nhất từ config/settings. */
  function resetContentDefaults() {
    outputsEdited = false
    restrictedPreferences.clear()
    reconcileOutputs()
  }

  watch([selectedTarget, () => source.value.type, () => source.value.thumbnailMode, settings, loading, targetsLoading], reconcileOutputs, { flush: 'sync' })

  const catalog = computed(() => ({
    loading: loading.value || targetsLoading.value,
    error: error.value || targetsError.value,
    contentDefaults: contentDefaults.value,
    outputOptions: outputOptions.value,
    targetOptions: targets.value.map(target => ({ title: target.label, value: target.key, icon: target.icon, color: target.color })),
    providerOptions: activeProviders.value.map(provider => ({ title: provider.name, value: provider.key })),
    modelOptions: availableModels(selectedProvider.value).map(model => ({
      title: model.label || model.remote_model_id,
      value: model.remote_model_id,
    })),
    selectedModel: availableModels(selectedProvider.value).find(model => model.remote_model_id === source.value.model) ?? null,
    imageModelOptions: imageModelOptions.value,
    defaultImageModelId: imageModelOptions.value.find(option => option.value === settings.value.default_image_model_id)?.value
      ?? imageModelOptions.value.find(option => option.value === settings.value.fallback_image_model_id)?.value ?? null,
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

  /** INPUT: catalog ảnh/default mới. OUTPUT: giữ model ảnh hợp lệ; mặc định ảnh độc lập model viết bài. */
  watch([imageModelOptions, settings, loading, () => source.value.imageModelId], () => {
    if (loading.value || imageModelOptions.value.some(option => option.value === source.value.imageModelId)) return
    const imageModelId = catalog.value.defaultImageModelId

    if (source.value.imageModelId !== imageModelId) source.value = { ...source.value, imageModelId }
  })

  /** Input: mở page/tải lại. Output: catalog mới hoặc error để retry; không reject event handler. */
  async function loadCatalog() {
    const version = ++targetVersion

    targetsLoading.value = true
    targetsError.value = ''
    try {
      const results = await Promise.allSettled([load(), aiAgentService.targets()])
      if (version !== targetVersion) return
      if (results[1].status === 'fulfilled') {
        targets.value = results[1].value
        if (!targets.value.some(target => target.key === source.value.targetType))
          source.value = { ...source.value, targetType: targets.value[0]?.key ?? '' }
      }
      else targetsError.value = 'Không tải được danh sách tài nguyên AI. Hãy tải lại.'
    }
    catch {
      // useAiProviderSettings đã lưu error; form hiển thị và cho phép tải lại.
    }
    finally { if (version === targetVersion) targetsLoading.value = false }
  }

  onScopeDispose(() => { targetVersion += 1 })

  return { catalog, loadCatalog, resetContentDefaults }
}
