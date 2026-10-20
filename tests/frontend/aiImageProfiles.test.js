/* eslint-disable camelcase -- API fixtures. */
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import ImagePromptPage from '@/pages/ai/image-prompt/index.vue'

const { service, providerList } = vi.hoisted(() => ({
  service: { list: vi.fn(), create: vi.fn(), show: vi.fn(), save: vi.fn(), remove: vi.fn() },
  providerList: vi.fn(),
}))

vi.mock('@/services/aiImageProfiles', () => ({ aiImageProfilesService: service }))
vi.mock('@/services/aiProviderSettings', () => ({ aiProviderSettingsService: { list: providerList } }))

const Slot = { template: '<div><slot /><slot name="append" /></div>' }
const Input = { props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">' }
const FileInput = { props: ['label'], emits: ['update:modelValue'], template: '<input type="file" :aria-label="label" @change="$emit(\'update:modelValue\', $event.target.files[0])">' }
const Select = { props: ['modelValue', 'items', 'label'], emits: ['update:modelValue'], template: '<select :aria-label="label" @change="$emit(\'update:modelValue\', Number($event.target.value))"><option value="">Chọn model</option><option v-for="item in items" :key="item.value" :value="item.value">{{ item.title }}</option></select>' }
const Switch = { props: ['label', 'modelValue'], emits: ['update:modelValue'], template: '<input type="checkbox" :aria-label="label" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)">' }
const Button = { props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' }
let wrapper
const button = label => wrapper.findAll('button').find(item => item.text() === label)

beforeEach(() => {
  vi.useFakeTimers()
  vi.resetAllMocks()
  vi.stubGlobal('definePage', vi.fn())
  providerList.mockResolvedValue({ providers: [{ name: 'Vision provider', driver: 'openai-compatible', is_active: true, has_api_key: true, models: [
    { id: 4, label: 'Vision model', is_enabled: true, is_available: true, capabilities: ['vision', 'text_generation'] },
    { id: 5, label: 'Unknown model', is_enabled: true, is_available: true, capabilities: [] },
  ] }] })
  service.list.mockResolvedValue({ data: [], last_page: 1 })
  wrapper = mount(ImagePromptPage, { global: { stubs: {
    VRow: Slot, VCol: Slot, VCard: Slot, VCardText: Slot, VCardActions: Slot, VAlert: Slot, VList: Slot, VListItem: Slot,
    VChip: Slot, VDialog: { template: '<div />' }, VProgressLinear: true, VPagination: true, VImg: true,
    AppTextField: Input, AppTextarea: Input, VFileInput: FileInput, AppSelect: Select, VSwitch: Switch, VBtn: Button,
  } } })
})
afterEach(() => { wrapper?.unmount(); vi.useRealTimers(); vi.unstubAllGlobals() })

it('uploads once, polls to ready, edits and enables a reusable image prompt', async () => {
  await flushPromises()
  expect(wrapper.get('select').text()).toContain('Vision model')
  expect(wrapper.get('select').text()).not.toContain('Unknown model')
  await wrapper.get('input[aria-label="Tên prompt ảnh"]').setValue('Soft lighting')

  const image = new File(['image bytes'], 'reference.png', { type: 'image/png' })
  const input = wrapper.get('input[type="file"]')

  Object.defineProperty(input.element, 'files', { value: [image] })
  await input.trigger('change')
  await wrapper.get('select').setValue('4')

  const queued = { id: 1, name: 'Soft lighting', status: 'queued', is_enabled: false }

  service.create.mockResolvedValue(queued)
  service.list.mockResolvedValue({ data: [queued], last_page: 1 })
  await button('Phân tích & tạo prompt').trigger('click')
  await flushPromises()
  expect(service.create).toHaveBeenCalledOnce()

  const body = service.create.mock.calls[0][0]

  expect(body.get('image').name).toBe('reference.png')
  expect(body.get('model_id')).toBe('4')

  const ready = { ...queued, status: 'ready', summary: 'Soft warm light', prompt: 'A softly lit photo of {subject}.', negative_prompt: 'watermark' }

  service.list.mockResolvedValue({ data: [ready], last_page: 1 })
  await vi.advanceTimersByTimeAsync(2500)
  await flushPromises()
  await wrapper.get('input[aria-label="Prompt tạo ảnh"]').setValue('Updated style prompt for {subject}.')
  await wrapper.get('input[type="checkbox"]').setValue(true)
  service.save.mockResolvedValue({ ...ready, is_enabled: true })
  await button('Lưu prompt ảnh').trigger('click')
  await flushPromises()
  expect(service.save).toHaveBeenCalledWith(1, expect.objectContaining({ prompt: 'Updated style prompt for {subject}.', is_enabled: true }))
  expect(service.create).toHaveBeenCalledOnce()
})
