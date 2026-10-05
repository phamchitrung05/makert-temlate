/* eslint-disable camelcase -- Fixture dùng DTO Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử tương tác tạo bài và thông báo trên cột phải.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: renderForm(), test enable/block/progress/error.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): props lifecycle -> nút/sự kiện/thông báo UI.
 * SIDE EFFECT: mount component với UI stubs; không gọi HTTP hoặc provider.
 * =====================================================================
 */
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AiContentCreateForm from '@/views/ai/content/AiContentCreateForm.vue'
import { createAiContentSource } from '@/utils/aiContentInput'

const Passthrough = { template: '<div><slot /></div>' }
const Button = { props: ['disabled', 'loading'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' }
const initial = { busy: false, canGenerate: true, blockedReason: '', session: null, error: '', monitorMessage: '' }

/** Input: lifecycle tùy chọn. Output: wrapper có nút HTML thực, không gọi API. */
function renderForm(generation = initial) {
  return mount(AiContentCreateForm, {
    props: { modelValue: createAiContentSource(), catalog: {}, generation },
    global: { stubs: {
      AiContentSourceForm: true, VCard: Passthrough, VCardItem: Passthrough,
      VCardText: Passthrough, VAlert: Passthrough, VProgressLinear: true, VBtn: Button,
      VIcon: true, VAvatar: true, AppSelect: true, AiPipelineReport: true,
    } },
  })
}

describe('Ai Content create form', () => {
  it('offers manual retry only for a failed run and emits a separate action', async () => {
    const wrapper = renderForm({ ...initial, canRetry: true, session: { status: 'failed' }, error: 'Kết nối bị ngắt' })

    await wrapper.findAll('button').find(button => button.text() === 'Thử lại tác vụ').trigger('click')
    expect(wrapper.emitted('retryRun')).toHaveLength(1)
    expect(wrapper.emitted('generate')).toBeUndefined()
    await wrapper.setProps({ generation: { ...initial, canRetry: false, busy: true, session: { status: 'queued' } } })
    expect(wrapper.text()).not.toContain('Thử lại tác vụ')
    wrapper.unmount()
  })

  it('enables generation, emits submit and then blocks while a queued run is being monitored', async () => {
    const wrapper = renderForm()

    expect(wrapper.get('button').element.disabled).toBe(false)
    await wrapper.get('button').trigger('click')
    expect(wrapper.emitted('generate')).toHaveLength(1)
    await wrapper.setProps({ generation: { ...initial, busy: true, canGenerate: false, session: { status: 'queued', current_step: 'queued', progress: 0 } } })
    expect(wrapper.findAll('button').find(button => button.text() === 'Phân tích & Tạo content').element.disabled).toBe(true)
    expect(wrapper.text()).toContain('Chờ worker')
    expect(wrapper.get('fieldset').element.disabled).toBe(true)
    wrapper.unmount()
  })

  it('explains an incompatible model and displays errors, success and monitoring retry independently', async () => {
    const wrapper = renderForm({ ...initial, canGenerate: false, blockedReason: 'Model này không hỗ trợ viết nội dung.' })

    expect(wrapper.get('button').element.disabled).toBe(true)
    expect(wrapper.text()).toContain('không hỗ trợ viết')
    await wrapper.setProps({ generation: { ...initial, error: 'Provider failed' } })
    expect(wrapper.text()).toContain('Provider failed')
    await wrapper.setProps({ generation: { ...initial, session: { status: 'ready' } } })
    expect(wrapper.text()).toContain('lưu vào danh sách chờ duyệt')
    await wrapper.setProps({ generation: { ...initial, busy: true, canGenerate: false, monitorMessage: 'Mất kết nối' } })
    await wrapper.findAll('button').find(button => button.text() === 'Cập nhật trạng thái').trigger('click')
    expect(wrapper.emitted('resumePolling')).toHaveLength(1)
    wrapper.unmount()
  })
})
