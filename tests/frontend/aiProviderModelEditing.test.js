/* eslint-disable camelcase -- Payload và fixture giữ field name của Laravel API. */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AiProvidersPage from '@/pages/settings/ai-providers.vue'
import { passthroughStubs } from './testStubs'

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm tra sửa capability và test model tại dòng trong catalog.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - page(): mount trang AI Providers với catalog/API giả.
 * - button(wrapper, text): tìm nút theo nội dung hiển thị.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT: catalog, thao tác sửa/thêm model và phản hồi thành công hoặc lỗi khi test.
 * - OUTPUT: đúng endpoint cập nhật, snackbar/tick và không reload catalog khi test model.
 * SIDE EFFECT: mount Vue và mock API; không gọi provider hoặc ghi database thật.
 * =====================================================================
 */
const { api } = vi.hoisted(() => ({ api: vi.fn() }))

vi.mock('@/utils/api', () => ({ $api: api }))

const catalogModel = {
  id: 10,
  remote_model_id: 'model-a',
  label: 'Model A',
  capabilities: ['text_generation', 'structured_output'],
  is_enabled: true,
  is_available: true,
}

let wrapper

/** Input: API fixture. Output: trang và dialog model thật với các UI primitive giả. */
function page() {
  wrapper = mount(AiProvidersPage, {
    global: {
      stubs: {
        ...passthroughStubs([
          'VRow',
          'VCol',
          'VCard',
          'VCardText',
          'VCardTitle',
          'VCardSubtitle',
          'VCardItem',
          'VCardActions',
          'VAvatar',
          'VIcon',
          'VChip',
          'VAlert',
          'VProgressLinear',
          'VMenu',
          'VList',
          'VListItem',
          'VListItemTitle',
          'VListItemSubtitle',
          'VTabs',
          'VTab',
          'VDivider',
          'VWindow',
          'VWindowItem',
          'VSwitch',
        ]),
        AiProviderConnectionDialog: true,
        VSnackbar: {
          props: ['modelValue'],
          template: '<div v-if="modelValue" role="status"><slot /></div>',
        },
        VDialog: {
          props: ['modelValue'],
          template: '<div v-if="modelValue" role="dialog"><slot /></div>',
        },
        VBtn: {
          props: ['disabled', 'loading'],
          emits: ['click'],
          template: '<button :disabled="disabled || loading" @click="$emit(\'click\')"><slot /></button>',
        },
        VTextField: {
          props: ['modelValue', 'label', 'disabled'],
          emits: ['update:modelValue'],
          template: '<input :value="modelValue" :aria-label="label" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)">',
        },
        VSelect: {
          props: {
            modelValue: [String, Array],
            label: String,
            items: Array,
            multiple: Boolean,
          },
          emits: ['update:modelValue'],
          template: `<select
            :aria-label="label"
            :multiple="multiple"
            @change="$emit('update:modelValue', multiple ? Array.from($event.target.options).filter(option => option.selected).map(option => option.value) : $event.target.value)"
          ><option
            v-for="item in items"
            :key="item.value"
            :value="item.value"
            :selected="multiple ? modelValue?.includes(item.value) : modelValue === item.value"
          >{{ item.title }}</option></select>`,
        },
        VDataTable: {
          props: ['items'],
          template: `<div><div v-for="item in items" :key="item.id">
            {{ item.label }}<slot name="item.actions" :item="item" />
          </div></div>`,
        },
      },
    },
  })

  return wrapper
}

/** Input: wrapper và nhãn nút. Output: nút tương ứng với thao tác người dùng. */
const button = (view, text) => view.findAll('button').find(item => item.text() === text)

describe('AI provider model editing', () => {
  beforeEach(() => {
    vi.stubGlobal('definePage', vi.fn())
    api.mockReset()
    api.mockImplementation(async (url, options) => {
      if (options) return { id: 10, ...options.body }

      return {
        providers: [{
          id: 1, name: 'Gateway', kind: 'gateway', driver: 'openai-compatible',
          is_active: true, has_api_key: true, models: [structuredClone(catalogModel)],
        }],
      }
    })
  })

  afterEach(() => wrapper?.unmount())

  it('loads existing capabilities and updates the selected model without creating a duplicate', async () => {
    const view = page()

    await flushPromises()
    await view.get('button[aria-label="Chỉnh sửa model"]').trigger('click')

    const dialog = view.get('[role="dialog"]')
    const modelId = dialog.get('input[aria-label="Remote model ID"]')
    const capabilities = dialog.get('select[aria-label="Capability đã xác nhận"]')

    expect(dialog.text()).toContain('Chỉnh sửa model')
    expect(modelId.element.value).toBe('model-a')
    expect(modelId.attributes()).toHaveProperty('disabled')
    expect(Array.from(capabilities.element.options).filter(option => option.selected).map(option => option.value)).toEqual(catalogModel.capabilities)
    await capabilities.setValue(['image_generation'])
    await button(dialog, 'Lưu model').trigger('click')
    await flushPromises()

    expect(api).toHaveBeenCalledWith('/admin/settings/ai/providers/1/models/10', {
      method: 'PUT',
      body: expect.objectContaining({ remote_model_id: 'model-a', capabilities: ['image_generation'] }),
    })
    expect(view.find('[role="dialog"]').exists()).toBe(false)
    expect(view.text()).toContain('Đã lưu model.')
  })

  it('resets the edit selection when adding a new model after cancelling', async () => {
    const view = page()

    await flushPromises()
    await view.get('button[aria-label="Chỉnh sửa model"]').trigger('click')
    await button(view.get('[role="dialog"]'), 'Hủy').trigger('click')
    await button(view, 'Thêm model').trigger('click')

    const dialog = view.get('[role="dialog"]')
    const modelId = dialog.get('input[aria-label="Remote model ID"]')

    expect(dialog.text()).toContain('Thêm model thủ công')
    expect(modelId.element.value).toBe('')
    expect(modelId.attributes('disabled')).toBeUndefined()
    await modelId.setValue('new-model')
    await button(dialog, 'Lưu model').trigger('click')
    await flushPromises()

    expect(api).toHaveBeenCalledWith('/admin/settings/ai/providers/1/models', {
      method: 'POST',
      body: expect.objectContaining({ remote_model_id: 'new-model' }),
    })
  })

  it('shows a snackbar and tick while preserving the catalog and model search', async () => {
    const view = page()

    await flushPromises()
    await view.get('input[placeholder="Tìm model..."]').setValue('Model A')
    api.mockResolvedValueOnce({ status: 'success', message: 'Model A đã phản hồi.' })
    await view.get('button[aria-label="Test model"]').trigger('click')
    await flushPromises()

    expect(api).toHaveBeenCalledWith('/admin/settings/ai/providers/1/test', {
      method: 'POST', body: { model_id: 10 },
    })
    expect(api.mock.calls.filter(([url]) => url === '/admin/settings/ai')).toHaveLength(1)
    expect(view.get('[role="status"]').text()).toBe('Model A đã phản hồi.')
    expect(view.find('[aria-label="Test model thành công"]').exists()).toBe(true)
    expect(view.get('input[placeholder="Tìm model..."]').element.value).toBe('Model A')
  })

  it('shows a failed test locally and allows retry without reloading the catalog', async () => {
    const view = page()

    await flushPromises()
    api.mockRejectedValueOnce({ data: { message: 'Model đã hết quota.' } })
    await view.get('button[aria-label="Test model"]').trigger('click')
    await flushPromises()

    expect(view.get('[role="status"]').text()).toBe('Model đã hết quota.')
    expect(view.find('[aria-label="Test model thất bại"]').exists()).toBe(true)
    expect(view.find('[aria-label="Test model thành công"]').exists()).toBe(false)
    expect(view.get('button[aria-label="Test model"]').attributes('disabled')).toBeUndefined()

    api.mockResolvedValueOnce({ status: 'success', message: 'Model đã phản hồi.' })
    await view.get('button[aria-label="Test model"]').trigger('click')
    await flushPromises()

    expect(view.find('[aria-label="Test model thất bại"]').exists()).toBe(false)
    expect(view.find('[aria-label="Test model thành công"]').exists()).toBe(true)
    expect(view.get('[role="status"]').text()).toBe('Model đã phản hồi.')
    expect(api.mock.calls.filter(([url]) => url === '/admin/settings/ai')).toHaveLength(1)
  })
})
