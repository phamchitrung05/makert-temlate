/* eslint-disable camelcase -- DTO giữ contract Laravel. */
/* eslint-disable vue/one-component-per-file -- UI primitive giả cho kiểm public interaction. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Regression loading/exit và bốn dialog riêng của Ai Prompt List.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: deferred(), renderList(), button(), finishTransitions(),
 * beforeEach()/afterEach() và các ca tải chậm/lỗi/response muộn/dirty/confirmation.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): thao tác List thật + HTTP giả -> DOM/public
 * payload; mô phỏng afterLeave của Vuetify, không đọc state private component.
 * SIDE EFFECT: mount Vue; không gọi model hoặc mutation database thật.
 * =====================================================================
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, ref, watch } from 'vue'
import AiPromptList from '@/views/ai/prompt/AiPromptList.vue'

const api = vi.hoisted(() => vi.fn())

vi.mock('@/utils/api', () => ({ $api: api }))

const profile = { id: 4, name: 'Mẫu đã lưu', version: 3, is_enabled: true, description: 'Mô tả mẫu', style_instructions: 'Giải thích bằng ví dụ.', rules_json: { tone: 'Tự nhiên' }, evidence_json: [] }
const options = { success: true, data: { items: [profile], default_writing_profile_id: 4 } }
const Slots = { props: ['title', 'subtitle'], template: '<section><header v-if="title">{{ title }}</header><p v-if="subtitle">{{ subtitle }}</p><slot /></section>' }
const Button = { props: ['disabled', 'loading'], emits: ['click'], template: '<button :disabled="disabled || loading" @click="$emit(\'click\')"><slot /></button>' }
const Input = { props: ['modelValue', 'label', 'disabled'], emits: ['update:modelValue'], template: '<label>{{ label }}<input :aria-label="label" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)"></label>' }
const Textarea = { ...Input, template: '<label>{{ label }}<textarea :aria-label="label" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" /></label>' }
const Checkbox = { ...Input, template: '<label><input type="checkbox" :aria-label="label" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)">{{ label }}</label>' }
const Table = { inheritAttrs: false, props: ['items', 'disabled'], emits: ['edit', 'toggle', 'remove'], template: '<div v-for="item in items" :key="item.id"><span>{{ item.name }}</span><button :disabled="disabled" @click="$emit(\'edit\', item)">Sửa mẫu</button><button :disabled="disabled" @click="$emit(\'toggle\', item)">Đổi trạng thái</button><button :disabled="disabled" @click="$emit(\'remove\', item)">Xóa mẫu</button></div>' }

const Dialog = defineComponent({
  props: { modelValue: Boolean, maxWidth: String },
  emits: ['afterLeave'],
  setup(props, { emit }) {
    const rendered = ref(false)

    watch(() => props.modelValue, value => { if (value) rendered.value = true }, { immediate: true })

    function finishLeave() {
      if (props.modelValue) return
      rendered.value = false
      emit('afterLeave')
    }

    return { rendered, finishLeave }
  },
  template: '<section v-if="rendered" role="dialog" :data-open="modelValue" :data-width="maxWidth"><slot /><button v-if="!modelValue" @click="finishLeave">Kết thúc hiệu ứng đóng</button></section>',
})

let wrapper

// =====================================================================
// Input: không có. Output: promise điều khiển được để kiểm tải chậm/response muộn.
// =====================================================================
function deferred() {
  let resolve
  let reject

  const promise = new Promise((_resolve, _reject) => { resolve = _resolve; reject = _reject })

  return { promise, resolve, reject }
}

// =====================================================================
// Input: HTTP mock. Output: List và các dialog thật, chỉ stub primitive UI.
// =====================================================================
async function renderList() {
  wrapper = mount(AiPromptList, { global: { stubs: {
    VDialog: Dialog, VCard: Slots, VCardItem: Slots, VCardText: Slots, VCardActions: Slots,
    VRow: Slots, VCol: Slots, VChip: Slots, VAlert: Slots, VExpansionPanels: Slots,
    VExpansionPanel: Slots, VExpansionPanelTitle: Slots, VExpansionPanelText: Slots,
    VForm: { template: '<form><slot /></form>' }, VBtn: Button, VCheckbox: Checkbox, VSwitch: Checkbox,
    AppTextField: Input, AppTextarea: Textarea, AiPromptListTable: Table,
    AiPromptListFilters: true, VProgressCircular: true, VSpacer: true, VDivider: true,
    VIcon: true, VAvatar: true,
    DialogCloseBtn: Button,
  } } })
  await flushPromises()

  return wrapper
}

// =====================================================================
// Input: tên nút UI. Output: DOM wrapper của nút public.
// =====================================================================
const button = label => wrapper.findAll('button').find(item => item.text() === label)

// =====================================================================
// Input: các dialog đang đóng. Output: afterLeave như khi Vuetify hoàn tất animation.
// =====================================================================
async function finishTransitions() {
  for (const dialog of wrapper.findAll('[role="dialog"][data-open="false"]'))
    await dialog.findAll('button').find(item => item.text() === 'Kết thúc hiệu ứng đóng').trigger('click')
}

beforeEach(() => {
  api.mockReset()
  api.mockImplementation(async path => {
    if (path.endsWith('/options')) return options
    if (path.endsWith('/4')) return { success: true, data: profile }

    return { success: true, data: [profile], meta: { pagination: { total: 1, last_page: 1 } } }
  })
})

afterEach(() => { wrapper?.unmount() })

describe('Ai Prompt dedicated management dialogs', () => {
  it('renders the complete manual form disabled while loading, then enables it without replacing its fields', async () => {
    const pending = deferred()

    await renderList()
    api.mockReturnValueOnce(pending.promise)
    await button('Nhập thủ công').trigger('click')

    const name = wrapper.get('input[aria-label="Tên văn phong"]')
    const instructions = wrapper.get('textarea[aria-label="Hướng dẫn văn phong"]')

    expect(wrapper.text()).toContain('Nhập văn phong thủ công')
    expect(wrapper.text()).toContain('Quy tắc văn phong')
    expect(wrapper.text()).toContain('Đặt làm văn phong mặc định')
    expect(wrapper.get('footer').text()).toContain('Tạo văn phong')
    expect(wrapper.get('.app-dialog-layout__body').text()).not.toContain('Tạo văn phong')
    expect(name.element.disabled).toBe(true)
    expect(instructions.element.disabled).toBe(true)
    expect(wrapper.get('[role="status"]').text()).toContain('Đang tải dữ liệu')
    expect(button('Tạo văn phong').element.disabled).toBe(true)
    pending.resolve(options)
    await flushPromises()
    expect(wrapper.get('input[aria-label="Tên văn phong"]').element).toBe(name.element)
    expect(name.element.disabled).toBe(false)
    expect(instructions.element.disabled).toBe(false)
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    expect(api.mock.calls.every(([, request]) => !request.method || request.method === 'GET')).toBe(true)
  })

  it('keeps the manual form on a loading error and only enables it after a successful retry', async () => {
    await renderList()
    api.mockRejectedValueOnce(new Error('Options offline'))
    await button('Nhập thủ công').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('offline')
    expect(wrapper.get('input[aria-label="Tên văn phong"]').element.disabled).toBe(true)
    expect(button('Tạo văn phong').element.disabled).toBe(true)
    await button('Tải phiên bản mới').trigger('click')
    await flushPromises()
    expect(wrapper.get('input[aria-label="Tên văn phong"]').element.disabled).toBe(false)
    expect(wrapper.text()).not.toContain('offline')
  })

  it('keeps fields and spinner during close, aborts the pending read and ignores its response after reopening', async () => {
    const pending = deferred()

    await renderList()
    api.mockReturnValueOnce(pending.promise)
    await button('Nhập thủ công').trigger('click')

    const signal = api.mock.lastCall[1].signal

    await wrapper.get('button[aria-label="Đóng nhập văn phong thủ công"]').trigger('click')
    expect(signal.aborted).toBe(true)
    expect(wrapper.get('[role="dialog"]').attributes('data-open')).toBe('false')
    expect(wrapper.find('input[aria-label="Tên văn phong"]').exists()).toBe(true)
    expect(wrapper.find('[role="status"]').exists()).toBe(true)
    expect(button('Nhập thủ công').element.disabled).toBe(true)
    await finishTransitions()
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(button('Nhập thủ công').element.disabled).toBe(false)
    await button('Nhập thủ công').trigger('click')
    await flushPromises()
    await wrapper.get('input[aria-label="Tên văn phong"]').setValue('Lần mở mới')
    pending.resolve(options)
    await flushPromises()
    expect(wrapper.get('input[aria-label="Tên văn phong"]').element.value).toBe('Lần mở mới')
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
  })

  it('preserves the saved form during exit and opens an empty form only after the transition finishes', async () => {
    await renderList()
    await button('Nhập thủ công').trigger('click')
    await flushPromises()
    await wrapper.get('input[aria-label="Tên văn phong"]').setValue('Mẫu mới')
    await wrapper.get('textarea[aria-label="Hướng dẫn văn phong"]').setValue('Viết tự nhiên.')
    await wrapper.get('textarea[aria-label="Giọng văn"]').setValue('Tự nhiên')
    expect(button('Tạo văn phong').element.disabled).toBe(false)
    api.mockResolvedValueOnce({ success: true, data: { ...profile, id: 12, name: 'Mẫu mới', version: 1 } })
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[role="dialog"]').attributes('data-open')).toBe('false')
    expect(wrapper.get('input[aria-label="Tên văn phong"]').element.value).toBe('Mẫu mới')
    expect(wrapper.text()).not.toContain('Đã lưu · #12')
    expect(api).toHaveBeenCalledWith('/admin/ai/writing-profiles', expect.objectContaining({ method: 'POST', body: expect.objectContaining({ name: 'Mẫu mới', analysis_id: null }) }))
    await finishTransitions()
    await button('Nhập thủ công').trigger('click')
    await flushPromises()
    expect(wrapper.get('input[aria-label="Tên văn phong"]').element.value).toBe('')
    expect(wrapper.get('textarea[aria-label="Hướng dẫn văn phong"]').element.value).toBe('')
  })

  it('opens a separate edit form, preserves unsaved input on cancel and retains it throughout confirmed close', async () => {
    const pending = deferred()

    await renderList()
    api.mockResolvedValueOnce(options).mockReturnValueOnce(pending.promise)
    await button('Sửa mẫu').trigger('click')
    expect(wrapper.text()).toContain('Chỉnh sửa văn phong')
    expect(wrapper.text()).not.toContain('Nhập văn phong thủ công')
    expect(wrapper.get('input[aria-label="Tên văn phong"]').element.value).toBe(profile.name)
    expect(wrapper.get('input[aria-label="Tên văn phong"]').element.disabled).toBe(true)
    pending.resolve({ success: true, data: { ...profile, name: 'Bản mới nhất' } })
    await flushPromises()
    expect(wrapper.get('input[aria-label="Tên văn phong"]').element.value).toBe('Bản mới nhất')
    await wrapper.get('input[aria-label="Tên văn phong"]').setValue('Tên chưa lưu')
    await wrapper.get('button[aria-label="Đóng chỉnh sửa văn phong"]').trigger('click')
    expect(wrapper.text()).toContain('Bỏ bản đang sửa?')
    await button('Hủy').trigger('click')
    await finishTransitions()
    expect(wrapper.get('[role="dialog"]').attributes('data-open')).toBe('true')
    expect(wrapper.get('input[aria-label="Tên văn phong"]').element.value).toBe('Tên chưa lưu')
    await wrapper.get('button[aria-label="Đóng chỉnh sửa văn phong"]').trigger('click')
    await button('Đóng').trigger('click')
    expect(wrapper.get('[role="dialog"]').attributes('data-open')).toBe('false')
    expect(wrapper.get('input[aria-label="Tên văn phong"]').element.value).toBe('Tên chưa lưu')
    await finishTransitions()
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(api.mock.calls.every(([, request]) => !request.method || request.method === 'GET')).toBe(true)
  })

  it.each([
    ['Đổi trạng thái', 'Tắt văn phong', '520'],
    ['Xóa mẫu', 'Xóa văn phong', '520'],
  ])('uses a compact %s confirmation with no edit fields and waits for current data', async (trigger, label, width) => {
    const pending = deferred()

    await renderList()
    api.mockResolvedValueOnce(options).mockReturnValueOnce(pending.promise)
    await button(trigger).trigger('click')
    expect(wrapper.get('[role="dialog"]').attributes('data-width')).toBe(width)
    expect(wrapper.text()).toContain(profile.name)
    expect(wrapper.find('input[aria-label="Tên văn phong"]').exists()).toBe(false)
    expect(button(label).element.disabled).toBe(true)
    pending.resolve({ success: true, data: profile })
    await flushPromises()
    expect(button(label).element.disabled).toBe(false)
    await button('Hủy').trigger('click')
    expect(wrapper.text()).toContain(profile.name)
    await finishTransitions()
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(api.mock.calls.every(([, request]) => !request.method || request.method === 'GET')).toBe(true)
  })
})
