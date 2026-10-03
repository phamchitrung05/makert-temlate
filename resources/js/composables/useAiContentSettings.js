/**
 * =====================================================================
 * CHỨC NĂNG FILE: Đọc và lưu cấu hình Settings > AI & Content qua API chung.
 * METHODS: loadSettings(), updateField(), saveSettings(), hydrateForm().
 * INPUT: catalog/settings đã lưu và thao tác form.
 * OUTPUT: form, options khả dụng, trạng thái tải/lưu và lỗi từng trường.
 * SIDE EFFECT: gọi useAiProviderSettings; không gọi AI provider hoặc lưu API key.
 * =====================================================================
 */
/* eslint-disable camelcase -- Payload keys follow the Laravel API contract. */
import { computed, shallowRef } from 'vue'
import { useAiProviderSettings } from '@/composables/useAiProviderSettings'

const fieldKeys = {
  defaultProviderId: 'default_text_model_id',
  defaultTextModelId: 'default_text_model_id',
  defaultImageModelId: 'default_image_model_id',
  temperature: 'default_temperature',
  minWordCount: 'min_word_count',
  systemPrompt: 'default_system_prompt',
  autoThumbnail: 'auto_thumbnail',
  autoSeo: 'auto_seo',
}

const textModels = provider => (provider?.models ?? []).filter(model => model.is_enabled
  && model.is_available && model.capabilities?.includes('text_generation'))

const numericValue = value => value === '' || value === null || value === undefined ? null : Number(value)

/** Canonical settings stay on the server; provider selection is derived from the model catalog. */
export function useAiContentSettings() {
  const { providers, presets, settings, loading, error, load, updateSettings } = useAiProviderSettings()
  const loaded = shallowRef(false)
  const saving = shallowRef(false)
  const form = shallowRef({})
  const fieldErrors = shallowRef({})
  const notice = shallowRef(null)
  const catalogNotice = shallowRef('')
  const imageCatalogNotice = shallowRef('')

  const availableProviders = computed(() => providers.value.filter(provider => provider.is_active
    && provider.has_api_key && textModels(provider).length))

  const providerOptions = computed(() => availableProviders.value.map(provider => ({ title: provider.name, value: provider.id })))
  const selectedProvider = computed(() => availableProviders.value.find(provider => provider.id === form.value.defaultProviderId))
  const modelOptions = computed(() => textModels(selectedProvider.value).map(model => ({ title: model.label || model.remote_model_id, value: model.id })))

  const imageModelOptions = computed(() => providers.value
    .filter(provider => provider.is_active && provider.has_api_key
      && presets.value.some(preset => preset.key === provider.driver && preset.image_supported))
    .flatMap(provider => (provider.models ?? [])
      .filter(model => model.is_enabled && model.is_available && model.capabilities?.includes('image_generation'))
      .map(model => ({ title: `${provider.name} · ${model.label || model.remote_model_id}`, value: model.id }))))

  const canSave = computed(() => loaded.value && !loading.value && !error.value && !saving.value)

  /** Fill the editable form from persisted settings and clear unavailable model selections visibly. */
  function hydrateForm() {
    const defaultModelId = settings.value.default_text_model_id ?? null
    const provider = availableProviders.value.find(item => textModels(item).some(model => model.id === defaultModelId))
    const defaultImageModelId = settings.value.default_image_model_id ?? null
    const imageModelAvailable = imageModelOptions.value.some(model => model.value === defaultImageModelId)

    catalogNotice.value = defaultModelId !== null && !provider
      ? 'Mô hình mặc định đã lưu hiện không khả dụng. Hãy chọn mô hình khác hoặc để trống.'
      : ''
    imageCatalogNotice.value = defaultImageModelId !== null && !imageModelAvailable
      ? 'Model tạo ảnh đã lưu hiện không khả dụng. Hãy chọn model ảnh khác hoặc để trống.'
      : ''
    form.value = {
      defaultProviderId: provider?.id ?? null,
      defaultTextModelId: provider ? defaultModelId : null,
      defaultImageModelId: imageModelAvailable ? defaultImageModelId : null,
      temperature: settings.value.default_temperature ?? 0.2,
      minWordCount: settings.value.min_word_count ?? 0,
      systemPrompt: settings.value.default_system_prompt ?? '',
      autoThumbnail: settings.value.auto_thumbnail ?? true,
      autoSeo: settings.value.auto_seo ?? true,
    }
  }

  /** Load/retry without rejecting an event handler; editing stays disabled until data is loaded. */
  async function loadSettings() {
    if (loading.value || saving.value) return
    loaded.value = false
    notice.value = null
    fieldErrors.value = {}
    try {
      await load()
      hydrateForm()
      loaded.value = true
    }
    catch {
      // The shared composable keeps the server error for the retry panel.
    }
  }

  /** Form changes are explicit; changing provider always removes a model from another provider. */
  function updateField(field, value) {
    if (!Object.hasOwn(fieldKeys, field) || !canSave.value) return
    const next = { ...form.value, [field]: value }

    if (field === 'defaultProviderId') {
      const provider = availableProviders.value.find(item => item.id === value)

      next.defaultTextModelId = textModels(provider)[0]?.id ?? null
      catalogNotice.value = ''
    }
    if (field === 'defaultTextModelId') {
      if (value !== null && !modelOptions.value.some(model => model.value === value)) return
      catalogNotice.value = ''
    }
    if (field === 'defaultImageModelId') {
      if (value !== null && !imageModelOptions.value.some(model => model.value === value)) return
      imageCatalogNotice.value = ''
    }
    form.value = next
    notice.value = null

    const errors = { ...fieldErrors.value }

    delete errors[fieldKeys[field]]
    fieldErrors.value = errors
  }

  /** Save only this tab's canonical fields; keep edits and field errors when the API rejects them. */
  async function saveSettings() {
    if (!canSave.value) return false
    saving.value = true
    notice.value = null
    fieldErrors.value = {}
    try {
      await updateSettings({
        default_text_model_id: form.value.defaultTextModelId,
        default_image_model_id: form.value.defaultImageModelId,
        default_temperature: numericValue(form.value.temperature),
        min_word_count: numericValue(form.value.minWordCount),
        default_system_prompt: form.value.systemPrompt,
        auto_thumbnail: form.value.autoThumbnail,
        auto_seo: form.value.autoSeo,
      })
      hydrateForm()
      notice.value = { type: 'success', message: 'Đã lưu thiết lập AI & Content.' }

      return true
    }
    catch (reason) {
      fieldErrors.value = reason?.data?.errors ?? {}
      notice.value = {
        type: 'error',
        message: Object.keys(fieldErrors.value).length
          ? 'Kiểm tra lại các trường được đánh dấu rồi lưu lại.'
          : reason?.data?.message ?? 'Không thể lưu thiết lập AI & Content. Hãy thử lại.',
      }

      return false
    }
    finally {
      saving.value = false
    }
  }

  return { form, loading, loaded, error, saving, notice, catalogNotice, imageCatalogNotice, fieldErrors, providerOptions, modelOptions, imageModelOptions, canSave, loadSettings, updateField, saveSettings }
}
