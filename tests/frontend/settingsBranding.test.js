/* eslint-disable camelcase -- Fixtures/payload theo DTO Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm branding Settings qua API client và form Vue/Vuetify thật.
 * CÁC HÀM/METHOD TRONG FILE: fixture(), render(), chooseFile(), beforeEach/afterEach và test cases.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : API mock, file và thao tác DOM.
 * - OUTPUT: multipart payload, preview/dirty/locks/runtime logo và cleanup URL.
 * =====================================================================
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, onMounted } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import { createVuetify } from 'vuetify'
import { VBtn } from 'vuetify/components/VBtn'
import { VFileInput } from 'vuetify/components/VFileInput'
import { VIcon } from 'vuetify/components/VIcon'
import { VCol, VRow } from 'vuetify/components/VGrid'
import { useSettings } from '@/composables/useSettings'
import { settingsService, SETTINGS_REQUEST_TIMEOUT_MS } from '@/services/settings'
import { applySiteBranding } from '@/composables/useSiteBranding'
import SettingsBrandingPanel from '@/views/settings/SettingsBrandingPanel.vue'
import AppBrandLogo from '@/components/AppBrandLogo.vue'

const { api } = vi.hoisted(() => ({ api: vi.fn() }))

vi.mock('@/utils/api', () => ({ $api: api }))
let wrapper
let sequence = 0

/** Input: quyền/version. Output: DTO có site/mail để kiểm draft độc lập. */
function fixture(canManage = true) {
  return {
    sections: {
      site: { version: 4, values: { site_name: 'Market', logo_url: '/old-logo.png', logo_configured: true, favicon_url: '/old-icon.png', favicon_configured: true, favicon_type: 'image/png' } },
      mail: { version: 0, values: { mailer: 'log' } },
    },
    can_manage: canManage,
  }
}

beforeEach(() => {
  api.mockReset()
  sequence = 0
  vi.spyOn(URL, 'createObjectURL').mockImplementation(() => `blob:preview-${++sequence}`)
  vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => {})
  document.head.innerHTML = '<link rel="icon" href="/old-icon.png">'
  applySiteBranding(fixture().sections.site.values)
})

afterEach(() => {
  wrapper?.unmount()
  wrapper = null
  document.head.innerHTML = ''
})

/** Input: DTO. Output: actual branding panel và actions/settings state, không đọc component internals. */
async function render(data = fixture()) {
  const service = {
    list: vi.fn().mockResolvedValue(data), update: api,
  }

  const Harness = defineComponent({
    setup() {
      const state = useSettings(service)

      onMounted(state.load)

      return () => h('div', [
        h(AppBrandLogo, null, { default: () => h('span', 'Default logo') }),
        state.loaded.value && h(SettingsBrandingPanel, {
          form: state.drafts.value.site,
          changes: state.branding.changes.value,
          previews: state.branding.previews.value,
          errors: state.fieldErrors.value.site,
          disabled: !state.canManage.value || Boolean(state.saving.value),
          onSelectFile: state.selectBrandingFile,
          onRemoveFile: state.removeBrandingFile,
        }),
        h('span', { role: 'status' }, state.notices.value.site?.message ?? ''),
        h('button', { 'data-testid': 'save', disabled: !state.dirtyGroups.value.includes('site') || Boolean(state.saving.value) || state.conflicts.value.site, onClick: () => state.save('site') }, 'Lưu thay đổi'),
        h('button', { 'data-testid': 'reset', onClick: () => state.reset('site') }, 'Hoàn tác'),
      ])
    },
  })

  wrapper = mount(Harness, { attachTo: document.body, global: { plugins: [createVuetify({ components: { VBtn, VFileInput, VIcon, VCol, VRow } })] } })
  await flushPromises()

  return service
}

/** Input: vị trí input/file. Output: native change event như browser, chờ Vue cập nhật. */
async function chooseFile(index, file) {
  const input = wrapper.findAll('input[type="file"]')[index]

  Object.defineProperty(input.element, 'files', { configurable: true, value: [file] })
  await input.trigger('change')
}

describe('Settings branding API', () => {
  it('sends files as multipart with method spoof, version and no readonly URLs', async () => {
    api.mockResolvedValue({ data: { version: 5 } })

    const file = new File(['logo'], 'logo.png', { type: 'image/png' })

    await settingsService.update('site', { ...fixture().sections.site.values, version: 4, logo_file: file, remove_favicon: true })

    const [url, request] = api.mock.calls[0]

    expect(url).toBe('/admin/settings/site')
    expect(request.method).toBe('POST')
    expect(request.retry).toBe(0)
    expect(request.body.get('_method')).toBe('PATCH')
    expect(request.body.get('version')).toBe('4')
    expect(request.body.get('remove_favicon')).toBe('1')
    expect(request.body.get('logo_file')).toBe(file)
    expect(request.body.has('logo_url')).toBe(false)
    expect(request.body.has('favicon_type')).toBe(false)
  })

  it('keeps existing JSON PATCH for remove-only and other settings groups', async () => {
    api.mockResolvedValue({ data: {} })
    await settingsService.update('site', { version: 4, remove_logo: true, logo_url: '/readonly.png' })
    expect(api).toHaveBeenLastCalledWith('/admin/settings/site', { method: 'PATCH', retry: 0, timeout: SETTINGS_REQUEST_TIMEOUT_MS, body: { version: 4, remove_logo: true } })
    await settingsService.update('mail', { version: 0, mailer: 'log' })
    expect(api).toHaveBeenLastCalledWith('/admin/settings/mail', { method: 'PATCH', retry: 0, timeout: SETTINGS_REQUEST_TIMEOUT_MS, body: { version: 0, mailer: 'log' } })
  })
})

describe('Settings branding form', () => {
  it('previews without uploading or changing live logo, then reset releases the preview', async () => {
    await render()

    const file = new File(['logo'], 'logo.png', { type: 'image/png' })

    await chooseFile(0, file)
    expect(api).not.toHaveBeenCalled()
    expect(wrapper.get('img[alt="Xem trước Logo website"]').attributes('src')).toBe('blob:preview-1')
    expect(wrapper.get('.app-brand-logo').attributes('src')).toBe('/old-logo.png')
    expect(wrapper.get('[data-testid="save"]').element.disabled).toBe(false)
    await wrapper.get('[data-testid="reset"]').trigger('click')
    expect(wrapper.get('img[alt="Xem trước Logo website"]').attributes('src')).toBe('/old-logo.png')
    expect(wrapper.get('[data-testid="save"]').element.disabled).toBe(true)
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview-1')
  })

  it('retains file and shows validation error; applies saved branding only after success', async () => {
    await render()

    const file = new File(['icon'], 'icon.png', { type: 'image/png' })

    await chooseFile(1, file)
    api.mockRejectedValueOnce({ status: 422, data: { message: 'Ảnh không hợp lệ', errors: { favicon_file: ['Cần ảnh hình vuông'] } } })
    await wrapper.get('[data-testid="save"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Cần ảnh hình vuông')
    expect(wrapper.get('img[alt="Xem trước Favicon"]').attributes('src')).toBe('blob:preview-1')
    expect(document.querySelector('link[rel="icon"]').getAttribute('href')).toBe('/old-icon.png')
    api.mockResolvedValueOnce({ version: 5, values: { ...fixture().sections.site.values, favicon_url: '/saved-icon.png' } })
    await wrapper.get('[data-testid="save"]').trigger('click')
    await flushPromises()
    expect(api.mock.calls[1][1].favicon_file).toBe(file)
    expect(document.querySelector('link[rel="icon"]').getAttribute('href')).toBe('/saved-icon.png')
    expect(wrapper.get('[data-testid="save"]').element.disabled).toBe(true)
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview-1')
  })

  it('blocks readonly file controls and removes existing branding only when saving', async () => {
    await render(fixture(false))
    expect(wrapper.findAll('input[type="file"]').every(input => input.element.disabled)).toBe(true)
    wrapper.unmount()
    await render()

    const remove = wrapper.findAll('button').find(button => button.text() === 'Dùng biểu tượng mặc định')

    await remove.trigger('click')
    expect(wrapper.text()).toContain('Sẽ dùng biểu tượng mặc định')
    expect(wrapper.get('.app-brand-logo').attributes('src')).toBe('/old-logo.png')
    api.mockResolvedValueOnce({ version: 5, values: { ...fixture().sections.site.values, logo_url: null, logo_configured: false } })
    await wrapper.get('[data-testid="save"]').trigger('click')
    await flushPromises()
    expect(api.mock.calls[0][1].remove_logo).toBe(true)
    expect(wrapper.text()).toContain('Default logo')
  })

  it('locks changes during save and preserves the pending image on conflict', async () => {
    await render()

    const file = new File(['logo'], 'logo.png', { type: 'image/png' })

    await chooseFile(0, file)
    let rejectSave

    api.mockImplementationOnce(() => new Promise((_resolve, reject) => { rejectSave = reject }))
    await wrapper.get('[data-testid="save"]').trigger('click')
    expect(wrapper.findAll('input[type="file"]').every(input => input.element.disabled)).toBe(true)
    rejectSave({ status: 409, data: { message: 'Cấu hình đã đổi' } })
    await flushPromises()
    expect(wrapper.text()).toContain('Cấu hình đã đổi')
    expect(wrapper.get('[data-testid="save"]').element.disabled).toBe(true)
    expect(wrapper.get('img[alt="Xem trước Logo website"]').attributes('src')).toBe('blob:preview-1')
    wrapper.unmount()
    wrapper = null
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:preview-1')
  })
})
