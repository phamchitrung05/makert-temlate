/* eslint-disable camelcase -- Fixtures/payload theo contract Laravel. */
/**
 * Kiểm state dùng thật trong UI: dirty theo nhóm, secret reset, 409/422, response cũ.
 * Input: API mock. Output: draft và payload; không gọi mạng hoặc gửi mail.
 */
import { describe, expect, it, vi } from 'vitest'
import { useSettings } from '@/composables/useSettings'

const fixture = () => ({
  sections: {
    site: { version: 0, values: { site_name: 'Market', timezone: 'UTC' } },
    mail: { version: 0, values: { mailer: 'log', password_configured: true } },
  },
  options: { locales: [] },
  can_manage: true,
})

const service = () => ({
  list: vi.fn().mockResolvedValue(fixture()),
  update: vi.fn().mockImplementation(async (group, payload) => ({ version: 1, values: { ...fixture().sections[group].values, ...payload } })),
  readOperations: vi.fn(),
  testMail: vi.fn(),
})

describe('Settings persisted groups', () => {
  it('keeps edits per group and does not send redaction fields', async () => {
    const api = service()
    const state = useSettings(api)

    await state.load()
    expect(state.dirtyGroups.value).toEqual([])
    state.updateField('site', 'site_name', 'Market mới')
    state.updateField('mail', 'password', 'new-secret')
    api.update.mockResolvedValueOnce({ version: 1, values: { mailer: 'log', password_configured: true } })
    await state.save('mail')
    expect(api.update).toHaveBeenCalledWith('mail', { version: 0, mailer: 'log', password: 'new-secret' })
    expect(state.drafts.value.mail.password).toBe('')
    state.updateField('mail', 'password', '')
    expect(state.notices.value.mail.type).toBe('success')
    expect(state.dirtyGroups.value).toEqual(['site'])
    state.reset('site')
    expect(state.dirtyGroups.value).toEqual([])
  })

  it('keeps dirty data on validation or conflict, blocks duplicate saves and reloads only conflict group', async () => {
    const api = service()
    const state = useSettings(api)

    await state.load()
    state.updateField('site', 'site_name', 'Chưa lưu')
    api.update.mockRejectedValueOnce({ status: 422, data: { errors: { site_name: ['Tên sai'] } } })
    await state.save('site')
    expect(state.drafts.value.site.site_name).toBe('Chưa lưu')
    expect(state.fieldErrors.value.site.site_name).toEqual(['Tên sai'])
    api.update.mockRejectedValueOnce({ status: 409, data: { message: 'Stale' } })
    await state.save('site')
    expect(state.conflicts.value.site).toBe(true)
    await state.save('site')
    expect(api.update).toHaveBeenCalledTimes(2)
    state.updateField('mail', 'password', 'keep-this')
    await state.reloadGroup('site')
    expect(state.drafts.value.site.site_name).toBe('Market')
    expect(state.drafts.value.mail.password).toBe('keep-this')
    expect(state.conflicts.value.site).toBe(false)
  })

  it('rejects stale load results and keeps a read-only form unchanged', async () => {
    const api = service()
    let oldResponse

    api.list.mockImplementationOnce(() => new Promise(resolve => { oldResponse = resolve }))
    api.list.mockResolvedValueOnce({ ...fixture(), can_manage: false })

    const state = useSettings(api)
    const first = state.load()

    await state.load()
    oldResponse({ ...fixture(), sections: {} })
    await first
    state.updateField('site', 'site_name', 'Denied')
    expect(state.drafts.value.site.site_name).toBe('Market')
    expect(state.canManage.value).toBe(false)
  })

  it('does not test mail with dirty transport or retry failed operation silently', async () => {
    const api = service()
    const state = useSettings(api)

    await state.load()
    state.updateField('mail', 'password', 'unpersisted')
    await state.testMail('receiver@example.test')
    expect(api.testMail).not.toHaveBeenCalled()
    api.readOperations.mockRejectedValueOnce({ data: { message: 'Timeout' } })
    await state.loadOperation('cron')
    expect(state.operations.value.cron.error).toBe('Timeout')
    api.readOperations.mockResolvedValueOnce({ items: [] })
    await state.loadOperation('cron')
    expect(state.operations.value.cron.data.items).toEqual([])
  })
})
