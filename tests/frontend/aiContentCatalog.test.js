/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử catalog thật và lựa chọn provider/model của Ai Content.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: fixture(), createState(), các test selection/reload/retry.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): DTO API giả -> options và source cục bộ hợp lệ.
 * SIDE EFFECT: mock service và Vue effect scope; không gọi API/provider thật.
 * =====================================================================
 */
/* eslint-disable camelcase -- Fixtures mirror the Laravel API contract. */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, shallowRef } from 'vue'
import { useAiContentCatalog } from '@/composables/useAiContentCatalog'
import { useAiContentWorkspace } from '@/composables/useAiContentWorkspace'
import { createAiContentSource } from '@/utils/aiContentInput'

const { service, targets } = vi.hoisted(() => ({ service: { list: vi.fn() }, targets: vi.fn() }))
const postOutputs = ['title', 'excerpt', 'content', 'seo', 'taxonomy', 'thumbnail']
const outputOptions = postOutputs.map(value => ({ value, title: `Config: ${value}`, ...(value === 'thumbnail' ? { source_types: ['url'] } : {}) }))

const targetFixture = () => [
  { key: 'post', label: 'Post', outputs: postOutputs, output_options: outputOptions },
  { key: 'resource', label: 'Resource', outputs: ['title', 'content', 'taxonomy'], output_options: outputOptions },
  { key: 'sound', label: 'Sound', outputs: ['title', 'content', 'taxonomy'], output_options: outputOptions },
]

vi.mock('@/services/aiProviderSettings', () => ({ aiProviderSettingsService: service }))
vi.mock('@/services/aiAgent', () => ({ aiAgentService: { targets } }))

/** Input: không có. Output: catalog độc lập gồm provider ảnh, text và các option không khả dụng. */
const fixture = () => ({
  providers: [
    { id: 1, key: 'empty', name: 'Chưa sync', is_active: true, has_api_key: true, models: [] },
    { id: 2, key: 'apikey', name: 'APIKEY.FUN', is_active: true, has_api_key: true, models: [
      ...['gpt-image-2', 'gpt-image-2.5', 'gpt-image-2.5-flare', 'gpt-image-2.5-sunburst'].map((id, index) => ({
        id: index + 20, remote_model_id: id, label: id, is_enabled: true, is_available: true, capabilities: [],
      })),
      { id: 24, remote_model_id: 'disabled', is_enabled: false, is_available: true },
      { id: 25, remote_model_id: 'unavailable', is_enabled: true, is_available: false },
    ] },
    { id: 3, key: 'content', name: 'Content', is_active: true, has_api_key: true, models: [
      { id: 30, remote_model_id: 'text-model', label: 'Model viết bài', is_enabled: true, is_available: true, capabilities: ['text_generation'] },
    ] },
    { id: 4, key: 'inactive', name: 'Inactive', is_active: false, has_api_key: true, models: [] },
    { id: 5, key: 'no-key', name: 'No key', is_active: true, has_api_key: false, models: [] },
  ],
  settings: {},
})

let scope

/** Input: lựa chọn ban đầu tùy chọn. Output: source ref và composable thuộc effect scope của test. */
const createState = (selection = {}) => {
  const source = shallowRef({ ...createAiContentSource(), prompt: 'Giữ nguồn của người dùng', ...selection })

  return { source, ...scope.run(() => useAiContentCatalog(source)) }
}

describe('Ai Content live catalog', () => {
  it('selects the saved image default independently and filters capability, driver and availability', async () => {
    const data = fixture()

    data.presets = [{ key: 'openai-compatible', image_supported: true }, { key: 'text-only', image_supported: false }]
    data.providers[1].driver = 'openai-compatible'
    data.providers[1].models[0].capabilities = ['image_generation']
    data.providers[1].models[1].capabilities = ['image_generation']
    data.providers[1].models[2].capabilities = ['image_generation']
    data.providers[1].models[2].is_enabled = false
    data.providers[2].driver = 'text-only'
    data.providers[2].models.push({ id: 99, remote_model_id: 'wrong-driver', capabilities: ['image_generation'], is_enabled: true, is_available: true })
    data.settings = { default_text_model_id: 30, default_image_model_id: 20 }
    service.list.mockResolvedValue(data)

    const state = createState({ type: 'text', thumbnailMode: 'generate' })

    await state.loadCatalog()
    await nextTick()
    expect(state.source.value).toMatchObject({ provider: 'content', model: 'text-model', imageModelId: 20 })
    expect(state.catalog.value.imageModelOptions.map(option => option.value)).toEqual([20, 21])
    expect(state.source.value.outputs).toContain('thumbnail')
    state.source.value = { ...state.source.value, imageModelId: 21, model: 'text-model' }
    await state.loadCatalog()
    await nextTick()
    expect(state.source.value.imageModelId).toBe(21)
    state.source.value = { ...state.source.value, thumbnailMode: 'source' }
    await nextTick()
    expect(state.source.value.outputs).not.toContain('thumbnail')
    state.source.value = { ...state.source.value, thumbnailMode: 'generate' }
    await nextTick()
    expect(state.source.value.outputs).toContain('thumbnail')
  })
  beforeEach(() => {
    vi.resetAllMocks()
    scope = effectScope()
    service.list.mockResolvedValue(fixture())
    targets.mockResolvedValue(targetFixture())
  })
  afterEach(() => scope.stop())

  it('loads synced model IDs including unknown capability, excluding disabled/unavailable options', async () => {
    const state = createState({ provider: 'apikey', model: 'gpt-image-2' })

    await state.loadCatalog()
    await nextTick()
    expect(state.catalog.value.providerOptions.map(option => option.value)).toEqual(['empty', 'apikey', 'content'])
    expect(state.source.value).toMatchObject({ provider: 'apikey', model: 'gpt-image-2', prompt: 'Giữ nguồn của người dùng' })
    expect(state.catalog.value.modelOptions.map(option => option.value)).toEqual([
      'gpt-image-2', 'gpt-image-2.5', 'gpt-image-2.5-flare', 'gpt-image-2.5-sunburst',
    ])
    expect(service.list).toHaveBeenCalledOnce()
  })

  it('switches provider without retaining a model from the previous catalog', async () => {
    const state = createState()

    await state.loadCatalog()
    await nextTick()
    state.source.value = { ...state.source.value, provider: 'content' }
    await nextTick()
    expect(state.source.value.model).toBe('text-model')
    expect(state.catalog.value.modelOptions).toEqual([{ title: 'Model viết bài', value: 'text-model' }])
    state.source.value = { ...state.source.value, provider: 'empty' }
    await nextTick()
    expect(state.source.value).toMatchObject({ provider: 'empty', model: '' })
    expect(state.catalog.value.modelOptions).toEqual([])
  })

  it('uses the saved text default initially and preserves a valid user selection on reload', async () => {
    service.list.mockResolvedValue({ ...fixture(), settings: { default_text_model_id: 30 } })

    const state = createState()

    await state.loadCatalog()
    await nextTick()
    expect(state.source.value).toMatchObject({ provider: 'content', model: 'text-model' })
    state.source.value = { ...state.source.value, provider: 'apikey', model: 'gpt-image-2.5' }
    await nextTick()
    await state.loadCatalog()
    await nextTick()
    expect(state.source.value).toMatchObject({ provider: 'apikey', model: 'gpt-image-2.5' })
  })

  it('hydrates output groups from config and saved defaults, preserving explicit tags on reload', async () => {
    service.list.mockResolvedValue({ ...fixture(), settings: { auto_thumbnail: false, auto_seo: true } })

    const state = createState()

    await state.loadCatalog()
    expect(state.source.value.outputs).toEqual(['title', 'excerpt', 'content', 'seo', 'taxonomy'])
    expect(state.catalog.value.outputOptions.map(option => option.title)).toEqual(outputOptions.map(option => option.title))
    state.source.value = { ...state.source.value, outputs: ['title', 'thumbnail'] }
    await state.loadCatalog()
    expect(state.source.value.outputs).toEqual(['title', 'thumbnail'])
  })

  it('preserves explicit choices made before the catalog finishes, including toggling back', async () => {
    let resolveLoad

    service.list.mockReturnValueOnce(new Promise(resolve => { resolveLoad = resolve }))

    const state = createState()
    const pending = state.loadCatalog()

    state.source.value = { ...state.source.value, outputs: ['title', 'seo'] }
    state.source.value = { ...state.source.value, outputs: ['title', 'thumbnail'] }
    resolveLoad({ ...fixture(), settings: { auto_thumbnail: false, auto_seo: true } })
    await pending
    expect(state.source.value.outputs).toEqual(['title', 'thumbnail'])
    expect(state.catalog.value.contentDefaults).toEqual({ outputs: ['title', 'excerpt', 'content', 'seo', 'taxonomy'] })
  })

  it('resets a new form to saved defaults and allows later defaults to hydrate the untouched form', async () => {
    service.list.mockResolvedValue({ ...fixture(), settings: { auto_thumbnail: false, auto_seo: true } })

    const state = scope.run(() => {
      const workspace = useAiContentWorkspace()

      return { ...workspace, ...useAiContentCatalog(workspace.source) }
    })

    await state.loadCatalog()
    await nextTick()
    state.source.value = { ...state.source.value, prompt: 'Lựa chọn cũ', outputs: ['title', 'thumbnail'] }
    state.resetSource(state.catalog.value.contentDefaults)
    state.resetContentDefaults()
    expect(state.source.value).toMatchObject({ prompt: '', provider: 'content', model: 'text-model', outputs: ['title', 'excerpt', 'content', 'seo', 'taxonomy'] })
    service.list.mockResolvedValue({ ...fixture(), settings: { auto_thumbnail: true, auto_seo: false } })
    await state.loadCatalog()
    expect(state.source.value.outputs).toEqual(['title', 'excerpt', 'content', 'taxonomy', 'thumbnail'])
  })

  it('reconciles tags when switching target and remembers a URL thumbnail choice across source types', async () => {
    const state = createState()

    expect(state.source.value.outputs).toBeNull()
    await state.loadCatalog()
    state.source.value = { ...state.source.value, outputs: ['title', 'content', 'thumbnail'] }
    state.source.value = { ...state.source.value, type: 'text' }
    expect(state.source.value.outputs).toEqual(['title', 'content'])
    expect(state.catalog.value.outputOptions.find(option => option.value === 'thumbnail').props.disabled).toBe(true)
    state.source.value = { ...state.source.value, type: 'url' }
    expect(state.source.value.outputs).toEqual(['title', 'content', 'thumbnail'])
    state.source.value = { ...state.source.value, outputs: ['title', 'content'] }
    state.source.value = { ...state.source.value, type: 'text' }
    state.source.value = { ...state.source.value, type: 'url' }
    expect(state.source.value.outputs).toEqual(['title', 'content'])
    state.source.value = { ...state.source.value, outputs: ['title', 'excerpt', 'thumbnail'], targetType: 'resource' }
    expect(state.source.value.outputs).toEqual(['title'])
    expect(state.catalog.value.outputOptions.map(option => option.value)).toEqual(['title', 'content', 'taxonomy'])
  })

  it('accepts new output groups and labels supplied by config without a frontend allowlist', async () => {
    targets.mockResolvedValue([{ key: 'post', label: 'Post', outputs: ['title', 'summary'], output_options: [
      { value: 'title', title: 'Tiêu đề từ config' }, { value: 'summary', title: 'Tóm tắt từ config' },
    ] }])

    const state = createState()

    await state.loadCatalog()
    expect(state.source.value.outputs).toEqual(['title', 'summary'])
    expect(state.catalog.value.outputOptions[1].title).toBe('Tóm tắt từ config')
  })

  it('prefers a known text model initially and exposes selected capability without changing an explicit image choice', async () => {
    const state = createState()

    await state.loadCatalog()
    await nextTick()
    expect(state.source.value).toMatchObject({ provider: 'content', model: 'text-model' })
    expect(state.catalog.value.selectedModel.capabilities).toEqual(['text_generation'])
    state.source.value = { ...state.source.value, provider: 'apikey', model: 'gpt-image-2' }
    await nextTick()
    expect(state.source.value.model).toBe('gpt-image-2')
    expect(state.catalog.value.selectedModel.capabilities).toEqual([])
  })

  it('repairs a selection when a model is disabled by a catalog refresh', async () => {
    const state = createState({ provider: 'apikey', model: 'gpt-image-2.5' })

    await state.loadCatalog()
    await nextTick()

    const changed = fixture()

    changed.providers[1].models[1].is_enabled = false
    service.list.mockResolvedValue(changed)
    await state.loadCatalog()
    await nextTick()
    expect(state.source.value.model).toBe('gpt-image-2')
    expect(state.catalog.value.modelOptions.some(option => option.value === 'gpt-image-2.5')).toBe(false)
  })

  it('exposes loading/error and allows retry without an unhandled rejection', async () => {
    let rejectLoad

    service.list.mockReturnValueOnce(new Promise((_resolve, reject) => { rejectLoad = reject }))

    const state = createState()
    const pending = state.loadCatalog()

    expect(state.catalog.value.loading).toBe(true)
    rejectLoad({ data: { message: 'Không thể tải catalog' } })
    await pending
    expect(state.catalog.value).toMatchObject({ loading: false, error: 'Không thể tải catalog' })
    await state.loadCatalog()
    await nextTick()
    expect(state.catalog.value).toMatchObject({ loading: false, error: '' })
    expect(state.source.value.provider).toBe('content')
  })

  it('clears removed providers and models when the server catalog becomes empty', async () => {
    const state = createState()

    await state.loadCatalog()
    await nextTick()
    service.list.mockResolvedValue({ providers: [], settings: {} })
    await state.loadCatalog()
    await nextTick()
    expect(state.catalog.value.providerOptions).toEqual([])
    expect(state.source.value).toMatchObject({ provider: '', model: '', prompt: 'Giữ nguồn của người dùng' })
  })
})
