/**
 * CHỨC NĂNG FILE: Kiểm thử đọc/lưu cấu hình AI & Content và lựa chọn catalog.
 * INPUT: DTO/response API giả; OUTPUT: form thực, payload canonical và lỗi.
 * SIDE EFFECT: mock API service, không gọi provider thật.
 */
/* eslint-disable camelcase -- Fixtures mirror the Laravel API contract. */
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAiContentSettings } from '@/composables/useAiContentSettings'

const { service } = vi.hoisted(() => ({ service: { list: vi.fn(), updateSettings: vi.fn() } }))

vi.mock('@/services/aiProviderSettings', () => ({ aiProviderSettingsService: service }))

const model = (id, capabilities = ['text_generation'], overrides = {}) => ({
  id,
  label: `Model ${id}`,
  remote_model_id: `remote-${id}`,
  is_enabled: true,
  is_available: true,
  capabilities,
  ...overrides,
})

const fixture = () => ({
  providers: [
    { id: 1, name: 'First', driver: 'openai', is_active: true, has_api_key: true, models: [
      model(10),
      model(11, ['image_generation']),
      model(12, ['text_generation'], { is_enabled: false }),
      model(13, ['image_generation'], { is_enabled: false }),
      model(14, ['image_generation'], { is_available: false }),
    ] },
    { id: 2, name: 'Second', driver: 'gemini', is_active: true, has_api_key: true, models: [model(20), model(21, ['text_generation'], { is_available: false }), model(22, ['image_generation'])] },
    { id: 3, name: 'Disabled', driver: 'openai', is_active: false, has_api_key: true, models: [model(30), model(31, ['image_generation'])] },
    { id: 4, name: 'No key', driver: 'openai', is_active: true, has_api_key: false, models: [model(40), model(41, ['image_generation'])] },
    { id: 5, name: 'Unknown capability', driver: 'openai', is_active: true, has_api_key: true, models: [model(50, [])] },
    { id: 6, name: 'Unsupported images', driver: 'unsupported-images', is_active: true, has_api_key: true, models: [model(61, ['image_generation'])] },
    { id: 7, name: 'Unknown driver', driver: 'unknown-driver', is_active: true, has_api_key: true, models: [model(71, ['image_generation'])] },
    { id: 8, name: 'Images only', driver: 'openai', is_active: true, has_api_key: true, models: [model(80, ['image_generation'])] },
  ],
  presets: [
    { key: 'openai', image_supported: true },
    { key: 'gemini', image_supported: true },
    { key: 'unsupported-images', image_supported: false },
  ],
  settings: {
    default_text_model_id: 10,
    default_temperature: 1.1,
    default_system_prompt: 'Prompt đã lưu',
    min_word_count: 250,
    auto_thumbnail: false,
    auto_seo: true,
    default_image_model_id: 11,
  },
})

describe('AI & Content persisted settings', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    service.list.mockResolvedValue(fixture())
    service.updateSettings.mockImplementation(async payload => ({ ...fixture().settings, ...payload }))
  })

  it('hydrates all fields from the server and offers only usable text generation models', async () => {
    const state = useAiContentSettings()

    expect(state.canSave.value).toBe(false)
    await state.loadSettings()
    expect(state.form.value).toEqual({
      defaultProviderId: 1,
      defaultTextModelId: 10,
      defaultImageModelId: 11,
      temperature: 1.1,
      minWordCount: 250,
      systemPrompt: 'Prompt đã lưu',
      autoThumbnail: false,
      autoSeo: true,
    })
    expect(state.providerOptions.value).toEqual([{ title: 'First', value: 1 }, { title: 'Second', value: 2 }])
    expect(state.modelOptions.value).toEqual([{ title: 'Model 10', value: 10 }])
    expect(state.imageModelOptions.value).toEqual([
      { title: 'First · Model 11', value: 11 },
      { title: 'Second · Model 22', value: 22 },
      { title: 'Images only · Model 80', value: 80 },
    ])
    expect(state.canSave.value).toBe(true)
  })

  it('resets the model immediately when the provider changes and rejects models from another provider', async () => {
    const state = useAiContentSettings()

    await state.loadSettings()
    state.updateField('defaultProviderId', 2)
    expect(state.form.value).toMatchObject({ defaultProviderId: 2, defaultTextModelId: 20, defaultImageModelId: 11 })
    state.updateField('defaultTextModelId', 10)
    expect(state.form.value.defaultTextModelId).toBe(20)
    state.updateField('defaultProviderId', null)
    expect(state.form.value).toMatchObject({ defaultProviderId: null, defaultTextModelId: null, defaultImageModelId: 11 })
    expect(state.modelOptions.value).toEqual([])
  })

  it('saves text and thumbnail model IDs with edited content defaults and reloads the persisted selection', async () => {
    const state = useAiContentSettings()

    await state.loadSettings()
    state.updateField('defaultProviderId', 2)
    state.updateField('defaultImageModelId', 22)
    state.updateField('temperature', '1.3')
    state.updateField('minWordCount', '550')
    state.updateField('systemPrompt', 'Prompt mới')
    state.updateField('autoThumbnail', true)
    state.updateField('autoSeo', false)
    expect(await state.saveSettings()).toBe(true)
    expect(service.updateSettings).toHaveBeenCalledWith({
      default_text_model_id: 20,
      default_image_model_id: 22,
      default_temperature: 1.3,
      min_word_count: 550,
      default_system_prompt: 'Prompt mới',
      auto_thumbnail: true,
      auto_seo: false,
    })
    expect(state.form.value).toMatchObject({ defaultProviderId: 2, defaultTextModelId: 20, defaultImageModelId: 22, minWordCount: 550, autoSeo: false })
    expect(state.notice.value.type).toBe('success')

    const reloaded = useAiContentSettings()
    const savedSettings = await service.updateSettings.mock.results[0].value

    service.list.mockResolvedValue({ ...fixture(), settings: savedSettings })
    await reloaded.loadSettings()
    expect(reloaded.form.value).toEqual(state.form.value)
  })

  it('disables saves while loading, exposes errors and successfully retries the actual API load', async () => {
    let rejectLoad

    service.list.mockReturnValueOnce(new Promise((_resolve, reject) => { rejectLoad = reject }))

    const state = useAiContentSettings()
    const pending = state.loadSettings()

    expect(state.loading.value).toBe(true)
    expect(await state.saveSettings()).toBe(false)
    expect(service.updateSettings).not.toHaveBeenCalled()
    rejectLoad({ data: { message: 'Catalog tạm thời không khả dụng' } })
    await pending
    expect(state.error.value).toBe('Catalog tạm thời không khả dụng')
    expect(state.canSave.value).toBe(false)
    await state.loadSettings()
    expect(state.error.value).toBe('')
    expect(state.loaded.value).toBe(true)
  })

  it('keeps nullable defaults valid with an empty catalog and visibly clears an unavailable saved model', async () => {
    service.list.mockResolvedValue({ providers: [], settings: fixture().settings })

    const state = useAiContentSettings()

    await state.loadSettings()
    expect(state.form.value).toMatchObject({ defaultProviderId: null, defaultTextModelId: null, defaultImageModelId: null })
    expect(state.catalogNotice.value).toContain('không khả dụng')
    expect(state.imageCatalogNotice.value).toContain('không khả dụng')
    expect(state.providerOptions.value).toEqual([])
    expect(state.imageModelOptions.value).toEqual([])
    expect(state.canSave.value).toBe(true)
    expect(await state.saveSettings()).toBe(true)
    expect(service.updateSettings.mock.calls[0][0].default_text_model_id).toBeNull()
    expect(service.updateSettings.mock.calls[0][0].default_image_model_id).toBeNull()
  })

  it('chooses thumbnail models independently from the text provider and rejects non-image or unsupported selections', async () => {
    const state = useAiContentSettings()

    await state.loadSettings()
    for (const invalidId of [10, 13, 14, 31, 41, 50, 61, 71, 999]) {
      state.updateField('defaultImageModelId', invalidId)
      expect(state.form.value.defaultImageModelId).toBe(11)
    }
    state.updateField('defaultImageModelId', 80)
    expect(state.form.value).toMatchObject({ defaultProviderId: 1, defaultTextModelId: 10, defaultImageModelId: 80 })
    state.updateField('defaultProviderId', 2)
    expect(state.form.value).toMatchObject({ defaultProviderId: 2, defaultTextModelId: 20, defaultImageModelId: 80 })
    expect(state.imageModelOptions.value.map(option => option.value)).toEqual([11, 22, 80])
  })

  it.each([10, 13, 14, 31, 41, 61, 71, 999])('visibly clears unusable saved thumbnail model %s and permits a valid replacement', async imageId => {
    service.list.mockResolvedValue({ ...fixture(), settings: { ...fixture().settings, default_image_model_id: imageId } })

    const state = useAiContentSettings()

    await state.loadSettings()
    expect(state.form.value.defaultImageModelId).toBeNull()
    expect(state.imageCatalogNotice.value).toContain('không khả dụng')
    expect(state.form.value).toMatchObject({ defaultProviderId: 1, defaultTextModelId: 10 })
    state.updateField('defaultImageModelId', 22)
    expect(state.form.value.defaultImageModelId).toBe(22)
    expect(state.imageCatalogNotice.value).toBe('')
  })

  it('persists clearing the thumbnail default without changing the text default', async () => {
    const state = useAiContentSettings()

    await state.loadSettings()
    state.updateField('defaultImageModelId', null)
    expect(await state.saveSettings()).toBe(true)
    expect(service.updateSettings.mock.calls[0][0]).toMatchObject({ default_image_model_id: null, default_text_model_id: 10 })

    const reloaded = useAiContentSettings()
    const savedSettings = await service.updateSettings.mock.results[0].value

    service.list.mockResolvedValue({ ...fixture(), settings: savedSettings })
    await reloaded.loadSettings()
    expect(reloaded.form.value).toMatchObject({ defaultImageModelId: null, defaultProviderId: 1, defaultTextModelId: 10 })
    expect(reloaded.imageCatalogNotice.value).toBe('')
  })

  it('retains a rejected thumbnail selection and clears only its field error when corrected', async () => {
    service.updateSettings.mockRejectedValueOnce({ data: { errors: {
      default_image_model_id: ['Mô hình ảnh đã bị tắt.'],
      min_word_count: ['Số từ không hợp lệ.'],
    } } })

    const state = useAiContentSettings()

    await state.loadSettings()
    state.updateField('defaultImageModelId', 22)
    expect(await state.saveSettings()).toBe(false)
    expect(state.form.value.defaultImageModelId).toBe(22)
    expect(state.fieldErrors.value.default_image_model_id).toEqual(['Mô hình ảnh đã bị tắt.'])
    expect(state.notice.value.type).toBe('error')
    state.updateField('defaultImageModelId', 11)
    expect(state.fieldErrors.value.default_image_model_id).toBeUndefined()
    expect(state.fieldErrors.value.min_word_count).toEqual(['Số từ không hợp lệ.'])
    expect(await state.saveSettings()).toBe(true)
    expect(state.fieldErrors.value).toEqual({})
  })

  it('retains rejected edits and maps 422 errors to individual fields without a success message', async () => {
    service.updateSettings.mockRejectedValueOnce({ data: { errors: { min_word_count: ['Tối đa 10000 từ.'], default_system_prompt: ['Prompt quá dài.'] } } })

    const state = useAiContentSettings()

    await state.loadSettings()
    state.updateField('minWordCount', '15000')
    expect(await state.saveSettings()).toBe(false)
    expect(state.form.value.minWordCount).toBe('15000')
    expect(state.fieldErrors.value.min_word_count).toEqual(['Tối đa 10000 từ.'])
    expect(state.notice.value.type).toBe('error')
    state.updateField('minWordCount', 800)
    expect(state.fieldErrors.value.min_word_count).toBeUndefined()
    expect(state.fieldErrors.value.default_system_prompt).toEqual(['Prompt quá dài.'])
    expect(await state.saveSettings()).toBe(true)
    expect(state.fieldErrors.value).toEqual({})
  })

  it('prevents duplicate writes and freezes field editing until the write completes', async () => {
    let resolveSave

    service.updateSettings.mockImplementationOnce(payload => new Promise(resolve => { resolveSave = () => resolve({ ...fixture().settings, ...payload }) }))

    const state = useAiContentSettings()

    await state.loadSettings()

    const pending = state.saveSettings()

    expect(state.saving.value).toBe(true)
    expect(await state.saveSettings()).toBe(false)
    state.updateField('minWordCount', 900)
    expect(state.form.value.minWordCount).toBe(250)
    expect(service.updateSettings).toHaveBeenCalledOnce()
    resolveSave()
    await pending
    expect(state.saving.value).toBe(false)
    expect(state.canSave.value).toBe(true)
  })
})
