/* eslint-disable camelcase -- fixtures theo API Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Regression options provider/model và vòng đời polling Post AI.
 * CÁC HÀM/METHOD: render(), button(), select(), beforeEach(), afterEach(), test cases.
 * INPUT/OUTPUT: thao tác UI + status mock -> request đúng model/run và progress terminal.
 * SIDE EFFECT: Vue mount, fake timers; không gọi provider thật.
 * =====================================================================
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { reactive } from 'vue'
import { flushPromises, mount } from '@vue/test-utils'
import CreateWithAiDialog from '@/views/apps/blog/post/dialog/CreateWithAiDialog.vue'
import { passthroughStubs } from './testStubs'

const state = vi.hoisted(() => ({ store: null }))

vi.mock('@/stores/aiAgent', () => ({ useAiAgentStore: () => state.store }))

const SelectStub = {
  props: ['modelValue', 'items', 'label', 'itemTitle', 'itemValue', 'disabled'],
  emits: ['update:modelValue'],
  template: `<label>{{ label }}<select :aria-label="label" :value="modelValue" :disabled="disabled" @change="$emit('update:modelValue', $event.target.value)"><option value="">Default</option><option v-for="item in items" :key="item[itemValue] ?? item" :value="item[itemValue] ?? item">{{ item[itemTitle] ?? item }}</option></select></label>`,
}

const TextStub = {
  props: ['modelValue', 'label'], emits: ['update:modelValue'],
  template: '<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
}

const ProgressStub = { props: ['steps', 'elapsedTime'], template: '<div>{{ elapsedTime }}</div>' }
const PreviewStub = { props: ['candidate'], template: '<div>Candidate</div>' }
let wrapper

/** Input: không có. Output: dialog mở ngay lúc mount, đã tải options. */
async function render() {
  wrapper = mount(CreateWithAiDialog, {
    props: { modelValue: true },
    global: {
      mocks: { $vuetify: { display: { smAndDown: false } } },
      stubs: {
        ...passthroughStubs(['VDialog', 'DialogCloseBtn', 'VCard', 'VCardItem', 'VCardTitle', 'VCardSubtitle', 'VCardText', 'VCardActions', 'VAvatar', 'VIcon', 'VSheet', 'VRow', 'VCol', 'VAlert', 'VDivider', 'VProgressLinear', 'AppStepper', 'ArticleSourcePreviewCard']),
        VBtn: { props: ['disabled'], template: '<button :disabled="disabled"><slot /></button>' },
        AppSelect: SelectStub, AppTextField: TextStub, AppTextarea: TextStub,
        AiImportProgressCard: ProgressStub, AiAgentCandidatePreview: PreviewStub,
      },
    },
  })
  await flushPromises()

  return wrapper
}

/** Input: tên button. Output: wrapper nút hiển thị. */
const button = name => wrapper.findAll('button').find(item => item.text() === name)

/** Input: nhãn select. Output: control để thao tác black box. */
const select = name => wrapper.find(`select[aria-label="${name}"]`)

describe('Post AI options and polling', () => {
  beforeEach(() => {
    vi.useFakeTimers()

    const capabilities = {
      input_types: ['text', 'url'], outputs: ['title', 'content'],
      providers: [
        { key: 'gateway', label: 'Gateway', model_options: [{ value: 'text-a', label: 'Text A', capabilities: ['text_generation'] }] },
        { key: 'other', label: 'Other', models: ['text-b'] },
      ],
    }

    state.store = reactive({
      capabilities: null, candidates: [], session: null, isLoading: false,
      reset: vi.fn(() => { state.store.session = null; state.store.candidates = [] }),
      loadCapabilities: vi.fn(async () => { state.store.capabilities = capabilities

        return capabilities }),
      start: vi.fn(async () => {
        state.store.session = { job_id: 'run', session_id: 'run', status: 'queued' }

        return state.store.session
      }),
      poll: vi.fn(async () => state.store.session),
      regenerate: vi.fn(async () => ({ job_id: 'child', session_id: 'run', status: 'queued' })),
      cancel: vi.fn(),
    })
  })
  afterEach(() => { wrapper?.unmount(); vi.useRealTimers() })

  it('loads on mount and clears a stale model when changing provider', async () => {
    await render()
    expect(state.store.loadCapabilities).toHaveBeenCalledWith('post')
    expect(select('Provider / model').text()).toContain('Gateway')
    await select('Provider / model').setValue('gateway')
    expect(select('Model').text()).toContain('Text A')
    await select('Model').setValue('text-a')
    await select('Provider / model').setValue('other')
    expect(select('Model').element.value).toBe('')
    await wrapper.find('input[aria-label="Nội dung nguồn"]').setValue('Nội dung')
    await button('Bắt đầu tạo').trigger('click')
    expect(state.store.start).toHaveBeenCalledWith(expect.objectContaining({ provider: 'other' }))
    expect(state.store.start.mock.calls[0][0]).not.toHaveProperty('model')
  })

  it('pauses a queue with no worker and resumes the same run without creating another', async () => {
    await render()
    await wrapper.find('input[aria-label="Nội dung nguồn"]').setValue('Nội dung')
    await button('Bắt đầu tạo').trigger('click')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(62000)
    await flushPromises()
    expect(wrapper.text()).toContain('chưa được worker nhận xử lý')

    const calls = state.store.poll.mock.calls.length

    await vi.advanceTimersByTimeAsync(60000)
    expect(state.store.poll).toHaveBeenCalledTimes(calls)
    await button('Kiểm tra tiến trình').trigger('click')
    await vi.advanceTimersByTimeAsync(800)
    expect(state.store.poll).toHaveBeenLastCalledWith('run', 'post')
    expect(state.store.start).toHaveBeenCalledTimes(1)
  })

  it('polls the child run UUID and marks every step done on ready', async () => {
    await render()
    state.store.session = { job_id: 'run', session_id: 'run', status: 'ready' }
    state.store.candidates = [{ id: 'run', outputs: { title: 'Old' } }]
    await flushPromises()
    state.store.poll.mockImplementation(async id => {
      state.store.session = { job_id: id, status: 'ready', current_step: 'ready' }

      return state.store.session
    })
    await button('Tạo lại').trigger('click')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(800)
    await flushPromises()
    expect(state.store.poll).toHaveBeenCalledWith('child', 'post')
    expect(state.store.regenerate).toHaveBeenCalledWith('run', { fields: ['title'] })
    expect(wrapper.findComponent(ProgressStub).props('steps').every(step => step.status === 'done')).toBe(true)
    await vi.advanceTimersByTimeAsync(6000)
    expect(state.store.poll).toHaveBeenCalledTimes(1)
  })

  it('pauses prolonged processing and displays terminal errors returned by polling', async () => {
    await render()
    state.store.start.mockResolvedValue({ job_id: 'run', status: 'rewriting' })
    state.store.poll.mockResolvedValue({ job_id: 'run', status: 'rewriting' })
    await wrapper.find('input[aria-label="Nội dung nguồn"]').setValue('Nội dung')
    await button('Bắt đầu tạo').trigger('click')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(302000)
    expect(wrapper.text()).toContain('Đã tạm dừng kiểm tra tự động')

    const calls = state.store.poll.mock.calls.length

    await vi.advanceTimersByTimeAsync(60000)
    expect(state.store.poll).toHaveBeenCalledTimes(calls)
    state.store.session = { job_id: 'run', status: 'rewriting' }
    state.store.poll.mockResolvedValue({ job_id: 'run', status: 'failed', error: 'Provider không phản hồi.' })
    await button('Kiểm tra tiến trình').trigger('click')
    await vi.advanceTimersByTimeAsync(800)
    await flushPromises()
    expect(wrapper.text()).toContain('Provider không phản hồi.')
    expect(button('Kiểm tra tiến trình')).toBeUndefined()
  })

  it('shows terminal provider errors and stops when closed during an in-flight poll', async () => {
    await render()
    state.store.start.mockResolvedValue({ job_id: 'run', status: 'failed', error: 'API key chưa có quyền dùng model.' })
    await wrapper.find('input[aria-label="Nội dung nguồn"]').setValue('Nội dung')
    await button('Bắt đầu tạo').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('API key chưa có quyền dùng model.')
    await vi.advanceTimersByTimeAsync(10000)
    expect(state.store.poll).not.toHaveBeenCalled()
    state.store.start.mockResolvedValue({ job_id: 'run', status: 'queued' })
    let resolvePoll
    state.store.poll.mockImplementation(() => new Promise(resolve => { resolvePoll = resolve }))
    await button('Bắt đầu tạo').trigger('click')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(800)
    await wrapper.setProps({ modelValue: false })
    resolvePoll({ job_id: 'run', status: 'queued' })
    await flushPromises()
    await vi.advanceTimersByTimeAsync(10000)
    expect(state.store.poll).toHaveBeenCalledTimes(1)
  })

  it('does not start polling an old creation response after reopening the dialog', async () => {
    await render()
    let resolveStart
    state.store.start.mockImplementation(() => new Promise(resolve => { resolveStart = resolve }))
    await wrapper.find('input[aria-label="Nội dung nguồn"]').setValue('Nội dung')
    await button('Bắt đầu tạo').trigger('click')
    await wrapper.setProps({ modelValue: false })
    await wrapper.setProps({ modelValue: true })
    await flushPromises()
    resolveStart({ job_id: 'old', status: 'queued' })
    await flushPromises()
    await vi.advanceTimersByTimeAsync(10000)
    expect(state.store.poll).not.toHaveBeenCalled()
  })
})
