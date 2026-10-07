import { beforeEach, describe, expect, it, vi } from 'vitest'
import { settingsService, SETTINGS_REQUEST_TIMEOUT_MS } from '@/services/settings'
import { useSettings } from '@/composables/useSettings'
import { aiProviderSettingsService } from '@/services/aiProviderSettings'
import { useAiContentSettings } from '@/composables/useAiContentSettings'

const { api } = vi.hoisted(() => ({ api: vi.fn() }))

vi.mock('@/utils/api', () => ({ $api: api }))

beforeEach(() => api.mockReset())

describe('Settings request timeout', () => {
  it('bounds reads and writes and never retries mutations', async () => {
    api.mockResolvedValue({ data: {} })
    await settingsService.list()
    await settingsService.update('site', { version: 0 })
    await settingsService.readOperations('cron')
    await aiProviderSettingsService.list()
    await aiProviderSettingsService.updateSettings({ version: 0 })
    expect(api.mock.calls).toHaveLength(5)
    api.mock.calls.forEach(([, options]) => {
      expect(options).toMatchObject({ timeout: SETTINGS_REQUEST_TIMEOUT_MS, retry: 0 })
    })
  })

  it('releases loading after timeout and allows retry', async () => {
    api.mockRejectedValueOnce(new Error('timeout')).mockResolvedValueOnce({ data: { sections: {}, 'can_manage': true } })

    const state = useSettings()

    expect(await state.load()).toBe(false)
    expect(state.loading.value).toBe(false)
    expect(state.error.value).toContain('thử lại')
    expect(await state.load()).toBe(true)
    expect(state.error.value).toBe('')
  })

  it('releases the AI tab after load timeout and retains edits after save timeout', async () => {
    api.mockRejectedValueOnce(new Error('timeout'))

    const state = useAiContentSettings()

    await state.loadSettings()
    expect(state.loading.value).toBe(false)
    expect(state.loaded.value).toBe(false)
    expect(state.error.value).toBe('Không thể tải cấu hình AI. Hãy thử lại.')
    api.mockResolvedValueOnce({ success: true, data: { settings: { 'version': 0, 'default_temperature': 0.2 } } })
    await state.loadSettings()
    expect(state.loaded.value).toBe(true)
    state.updateField('temperature', 0)
    api.mockRejectedValueOnce(new Error('timeout'))
    expect(await state.saveSettings()).toBe(false)
    expect(state.saving.value).toBe(false)
    expect(state.form.value.temperature).toBe(0)
    expect(state.dirty.value).toBe(true)
    expect(state.canSave.value).toBe(true)
    api.mockResolvedValueOnce({ success: true, data: { 'version': 1, 'default_temperature': 0 } })
    expect(await state.saveSettings()).toBe(true)
    expect(state.form.value.temperature).toBe(0)
    expect(state.dirty.value).toBe(false)
  })
})
