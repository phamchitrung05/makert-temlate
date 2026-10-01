import { beforeEach, describe, expect, it, vi } from 'vitest'
/* eslint-disable camelcase -- Fixtures mirror the Laravel API contract. */
import { useAiProviderSettings } from '../../resources/js/composables/useAiProviderSettings'
import { findProvider, normalizeProvider, providerModels } from '../../resources/js/utils/aiModelOptions'
import { activeAiLineage, mergeAiLineage } from '../../resources/js/composables/aiCandidate'

const { service } = vi.hoisted(() => ({
  service: { list: vi.fn(), saveProvider: vi.fn(), disableProvider: vi.fn(), testProvider: vi.fn(), syncProvider: vi.fn(), saveModel: vi.fn(), updateSettings: vi.fn() },
}))

vi.mock('@/services/aiProviderSettings', () => ({ aiProviderSettingsService: service }))

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khóa selectors/default filtering, settings lifecycle và multi-run lineage.
 * =====================================================================
 * INPUT: fake service catalog và state thuần Vue.
 * OUTPUT: assertions về capability, loading/error và provenance sau sửa tay.
 * SIDE EFFECT: mock API; không gọi provider thật hoặc ghi database.
 * EXCEPTION/TRANSACTION: lỗi service fake được trả lại caller.
 * =====================================================================
 */
describe('AI provider model options', () => {
  it('keeps legacy options without guessing image capability', () => {
    const provider = normalizeProvider({ key: 'legacy', models: ['text-a'] })

    expect(providerModels(provider)).toHaveLength(1)
    expect(providerModels(provider, 'image_generation')).toEqual([])
    expect(findProvider([provider], '')).toBeNull()
  })

  it('filters capability and unavailable/disabled models without mutating catalog', () => {
    const provider = { key: 'gateway', model_options: [
      { value: 'text', capabilities: ['text_generation'] },
      { value: 'image', capabilities: ['image_generation'] },
      { value: 'unavailable', available: false, capabilities: ['image_generation'] },
      { value: 'disabled', enabled: false, capabilities: ['image_generation'] },
    ] }

    expect(providerModels(provider, 'image_generation').map(model => model.value)).toEqual(['image'])
    expect(provider.model_options).toHaveLength(4)
  })
})

describe('AI settings orchestration', () => {
  beforeEach(() => vi.resetAllMocks())

  it('only exposes active/available models as defaults', async () => {
    service.list.mockResolvedValue({ providers: [
      { id: 1, name: 'Active', is_active: true, has_api_key: true, models: [
        { id: 10, label: 'Text', is_enabled: true, is_available: true },
        { id: 11, is_enabled: false, is_available: true },
        { id: 12, is_enabled: true, is_available: false },
      ] },
      { id: 2, is_active: false, has_api_key: true, models: [{ id: 20, is_enabled: true, is_available: true }] },
    ], settings: { default_text_model_id: 10 } })

    const state = useAiProviderSettings()

    await state.load()
    expect(state.modelOptions.value.map(model => model.id)).toEqual([10])
    expect(state.settings.value.default_text_model_id).toBe(10)
    expect(state.loading.value).toBe(false)
  })

  it('keeps existing catalog and clears loading after sync failure', async () => {
    const state = useAiProviderSettings()

    state.providers.value = [{ id: 1 }]
    service.syncProvider.mockRejectedValue(new Error('Sync failed'))
    await expect(state.syncProvider(1)).rejects.toThrow('Sync failed')
    expect(state.providers.value).toEqual([{ id: 1 }])
    expect(service.list).not.toHaveBeenCalled()
  })

  it('shows load error and resets saving after rejected write', async () => {
    const state = useAiProviderSettings()

    service.list.mockRejectedValue(new Error('Load failed'))
    await expect(state.load()).rejects.toThrow('Load failed')
    expect(state.error.value).toBe('Load failed')
    expect(state.loading.value).toBe(false)
    service.saveProvider.mockRejectedValue(new Error('Write failed'))
    await expect(state.saveProvider({ name: 'Test' })).rejects.toThrow('Write failed')
    expect(state.saving.value).toBe(false)
  })
})

describe('AI field lineage', () => {
  it('retains content and image provenance separately, dropping manually changed fields', () => {
    const form = { title: 'AI title', content: 'AI content', thumbnail: { id: 1 } }
    const text = mergeAiLineage([], { runId: 'text-run', fields: ['title', 'content'] }, form)

    form.thumbnail = { id: 2 }

    const image = mergeAiLineage(text, { runId: 'image-run', fields: ['thumbnail'] }, form)

    expect(activeAiLineage(image, form)).toEqual([
      { run_id: 'text-run', fields: ['title', 'content'] },
      { run_id: 'image-run', fields: ['thumbnail'] },
    ])
    form.title = 'Manual title'
    expect(activeAiLineage(image, form)[0].fields).toEqual(['content'])
    form.thumbnail = { id: 3 }
    expect(activeAiLineage(image, form)).toEqual([{ run_id: 'text-run', fields: ['content'] }])
  })

  it('replaces field ownership without mutating earlier run state', () => {
    const first = mergeAiLineage([], { runId: 'a', fields: ['title', 'content'] }, { title: 'A', content: 'Content' })
    const second = mergeAiLineage(first, { runId: 'b', fields: ['title'] }, { title: 'B' })

    expect(first[0].fields).toEqual(['title', 'content'])
    expect(second.map(run => [run.runId, run.fields])).toEqual([['a', ['content']], ['b', ['title']]])
  })
})
