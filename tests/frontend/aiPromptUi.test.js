/* eslint-disable camelcase -- Fixture và payload giữ contract Laravel. */
/* eslint-disable vue/one-component-per-file -- Các component ở đây chỉ là UI primitive giả của test. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử duyệt văn phong và luồng nối API tại Ai Prompt Add.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - render(component, props): mount component thật với UI primitive giả.
 * - form(overrides): tạo DTO form độc lập cho từng test.
 * - button(view, label): tìm nút theo nội dung người dùng đọc được.
 * - test edits/save/evidence/default: kiểm tra public events, không truy cập state.
 * - test real/static output: kiểm tra kết quả thật tách khỏi dữ liệu minh họa.
 * - test page workflow: nhập nguồn/tên -> POST analysis -> queue popup tiếp nhận;
 *   form Add reset sau queued và không tự mở lại worker.
 * - test new-profile confirmation: không bỏ bản đang duyệt khi chưa xác nhận.
 * - test inaccessible resume: GET 403 không khóa Add hoặc gửi lại analysis.
 * - test history confirmation: giữ bản sửa khi mở UUID khác chưa được xác nhận.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : tương tác input/button, DTO API giả và public component props.
 * - OUTPUT: assert payload/events/nội dung nhìn thấy, không snapshot nội bộ.
 * - SIDE EFFECT: mount Vue và mock HTTP boundary; không gọi AI hay database thật.
 * =====================================================================
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { computed, defineComponent, h, inject, provide } from 'vue'
import AiPromptPage from '@/pages/ai/prompt/add/index.vue'
import AiPromptProfileForm from '@/views/ai/prompt/AiPromptProfileForm.vue'
import AiPromptAnalysisCard from '@/views/ai/prompt/AiPromptAnalysisCard.vue'
import AiPromptInsights from '@/views/ai/prompt/AiPromptInsights.vue'
import { promptPreviewReport } from '@/views/ai/prompt/promptPreview'

const { api } = vi.hoisted(() => ({ api: vi.fn() }))

vi.mock('@/utils/api', () => ({ $api: api }))
vi.mock('@/stores/adminAuth', () => ({ useAdminAuthStore: () => ({ user: { id: 8 }, permissions: ['ai_settings.manage', 'posts.manage'] }) }))
vi.mock('vue-router', () => ({ onBeforeRouteLeave: vi.fn() }))
vi.mock('@/views/ai/prompt/AiPromptSourceCard.vue', () => ({
  default: {
    inheritAttrs: false,
    props: ['modelValue', 'profileName', 'busy'],
    emits: ['update:modelValue', 'update:profileName', 'analyze'],
    template: `<section>
      <input aria-label="Tên văn phong nguồn" :value="profileName" :disabled="busy" @input="$emit('update:profileName', $event.target.value)">
      <textarea aria-label="Bài tham khảo" :value="modelValue" :disabled="busy" @input="$emit('update:modelValue', $event.target.value)" />
      <button :disabled="busy" @click="$emit('analyze')">Phân tích từ nguồn</button>
    </section>`,
  },
}))

const Slots = {
  props: ['title', 'subtitle'],
  template: '<section><header v-if="title">{{ title }}</header><p v-if="subtitle">{{ subtitle }}</p><slot name="prepend" /><slot name="append" /><slot /></section>',
}

const Button = {
  props: ['disabled', 'loading', 'type'], emits: ['click'],
  template: '<button :type="type || \'button\'" :disabled="disabled || loading" @click="$emit(\'click\', $event)"><slot /></button>',
}

const Input = {
  inheritAttrs: false, props: ['modelValue', 'label', 'disabled', 'errorMessages'], emits: ['update:modelValue'],
  template: '<label>{{ label }}<input :aria-label="label" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)"><span v-for="error in errorMessages" :key="error">{{ error }}</span></label>',
}

const Textarea = {
  ...Input,
  template: '<label>{{ label }}<textarea :aria-label="label" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" /><span v-for="error in errorMessages" :key="error">{{ error }}</span></label>',
}

const Checkbox = {
  props: ['modelValue', 'label', 'disabled'], emits: ['update:modelValue'],
  template: '<label><input type="checkbox" :aria-label="label" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)">{{ label }}</label>',
}

const tabContext = Symbol('test-tabs')
const windowContext = Symbol('test-window')

const TabGroup = defineComponent({
  props: { modelValue: { type: String, default: '' } }, emits: ['update:modelValue'],
  setup(props, { emit, slots }) {
    provide(tabContext, { select: value => emit('update:modelValue', value) })

    return () => h('nav', slots.default?.())
  },
})

const Tab = defineComponent({
  props: { value: { type: String, default: '' } },
  setup(props, { slots }) {
    const group = inject(tabContext)

    return () => h('button', { type: 'button', onClick: () => group.select(props.value) }, slots.default?.())
  },
})

const Window = defineComponent({
  props: { modelValue: { type: String, default: '' } },
  setup(props, { slots }) {
    provide(windowContext, computed(() => props.modelValue))

    return () => h('div', slots.default?.())
  },
})

const WindowItem = defineComponent({
  props: { value: { type: String, default: '' } },
  setup(props, { slots }) {
    const active = inject(windowContext)

    return () => active.value === props.value ? h('div', slots.default?.()) : null
  },
})

const stubs = {
  ...Object.fromEntries(['VCard', 'VCardItem', 'VCardText', 'VCardActions', 'VRow', 'VCol', 'VAvatar', 'VChip', 'VAlert', 'VList', 'VListItem', 'VExpansionPanels', 'VExpansionPanel', 'VExpansionPanelTitle', 'VExpansionPanelText', 'VProgressCircular'].map(name => [name, Slots])),
  VIcon: true, VDivider: true, VProgressLinear: true, VSpacer: true, IconBtn: Button,
  VBtn: Button, AppTextField: Input, AppTextarea: Textarea, VSwitch: Checkbox, VCheckbox: Checkbox,
  VForm: { emits: ['submit'], template: '<form @submit.prevent="$emit(\'submit\', $event)"><slot /></form>' },
  VTabs: TabGroup, VTab: Tab, VWindow: Window, VWindowItem: WindowItem,
  VDialog: { props: ['modelValue'], template: '<aside v-if="modelValue"><slot /></aside>' },
  VSnackbar: { props: ['modelValue'], template: '<aside v-if="modelValue"><slot /></aside>' },
}

const result = {
  summary: 'Kết quả thật: giải thích bằng ví dụ gần gũi.',
  rules: { tone: 'Giọng văn rõ ràng từ API', opening: 'Mở đầu bằng tình huống', sentence_rhythm: 'Đan xen câu ngắn và câu vừa', structure_patterns: ['Tình huống', 'Giải thích'], uncertainties: ['Chưa đủ dữ liệu về kết bài'] },
  evidence: [{ feature: 'tone', excerpt: 'Một ví dụ rõ ràng từ bài tham khảo.', explanation: 'Trích đoạn thể hiện cách giải thích trực tiếp.' }],
  style_instructions: 'Hướng dẫn thật từ API: diễn đạt rõ ràng, có ví dụ.',
}

const wrappers = []

/** Input: component thật và public props. Output: wrapper với input/button HTML. */
function render(component, props) {
  const wrapper = mount(component, { props, global: { stubs } })

  wrappers.push(wrapper)

  return wrapper
}

/** Input: field tùy chọn. Output: DTO form mới, các object con không dùng chung. */
function form(overrides = {}) {
  return { name: 'Văn phong gốc', description: '', rules_json: { tone: 'Rõ ràng', structure_patterns: ['Mở bài'] }, evidence_json: structuredClone(result.evidence), style_instructions: 'Hướng dẫn gốc', is_enabled: true, setAsDefault: false, ...overrides }
}

/** Input: view và nhãn nút hiển thị. Output: nút mà người dùng sẽ thao tác. */
const button = (view, label) => view.findAll('button').find(item => item.text() === label)

beforeEach(() => {
  vi.stubGlobal('definePage', vi.fn())
  api.mockReset()
  window.sessionStorage.clear()
})

afterEach(() => {
  wrappers.splice(0).forEach(wrapper => wrapper.unmount())
})

describe('Ai Prompt profile review', () => {
  it('emits immutable edits for name, instructions and multiline rules without losing a trailing newline', async () => {
    const original = form()
    const view = render(AiPromptProfileForm, { modelValue: original, canSave: true })

    await view.get('input[aria-label="Tên văn phong"]').setValue('Tên người dùng chọn')

    const named = view.emitted('update:modelValue').at(-1)[0]

    expect(named).not.toBe(original)
    expect(named.name).toBe('Tên người dùng chọn')
    expect(original.name).toBe('Văn phong gốc')
    await view.setProps({ modelValue: named })
    await view.get('textarea[aria-label="Hướng dẫn văn phong"]').setValue('Hướng dẫn đã duyệt')

    const instructed = view.emitted('update:modelValue').at(-1)[0]

    expect(instructed.style_instructions).toBe('Hướng dẫn đã duyệt')
    await view.setProps({ modelValue: instructed })
    await view.get('textarea[aria-label="Mẫu bố cục"]').setValue('Mở bài\nGiải thích\n')

    const updated = view.emitted('update:modelValue').at(-1)[0]

    expect(updated.rules_json).toEqual({ tone: 'Rõ ràng', structure_patterns: ['Mở bài', 'Giải thích', ''] })
    expect(original.rules_json).toEqual({ tone: 'Rõ ràng', structure_patterns: ['Mở bài'] })
    await view.setProps({ modelValue: updated })
    expect(view.get('textarea[aria-label="Mẫu bố cục"]').element.value).toBe('Mở bài\nGiải thích\n')
  })

  it('blocks submit while permission/lifecycle/conflict prevents saving and emits save only when allowed', async () => {
    const view = render(AiPromptProfileForm, { modelValue: form(), canSave: false })

    await view.get('form').trigger('submit')
    expect(view.emitted('save')).toBeUndefined()
    expect(button(view, 'Lưu văn phong').element.disabled).toBe(true)
    await view.setProps({ canSave: true, busy: true })
    await view.get('form').trigger('submit')
    expect(view.emitted('save')).toBeUndefined()
    await view.setProps({ busy: false, conflict: true })
    await view.get('form').trigger('submit')
    expect(view.emitted('save')).toBeUndefined()
    await button(view, 'Tải phiên bản mới').trigger('click')
    expect(view.emitted('reloadProfile')).toHaveLength(1)
    await view.setProps({ conflict: false })
    await view.get('form').trigger('submit')
    expect(view.emitted('save')).toHaveLength(1)
  })

  it('keeps source excerpts immutable while allowing explanation edits and removal', async () => {
    const original = form()
    const view = render(AiPromptProfileForm, { modelValue: original, canSave: true })

    expect(view.get('blockquote').text()).toBe(result.evidence[0].excerpt)
    await view.get('textarea[aria-label="Diễn giải"]').setValue('Diễn giải đã sửa')

    const changed = view.emitted('update:modelValue').at(-1)[0]

    expect(changed.evidence_json[0]).toEqual({ ...result.evidence[0], explanation: 'Diễn giải đã sửa' })
    expect(original.evidence_json[0]).toEqual(result.evidence[0])
    await view.setProps({ modelValue: changed })
    await view.get('button[aria-label="Bỏ dẫn chứng 1"]').trigger('click')
    expect(view.emitted('update:modelValue').at(-1)[0].evidence_json).toEqual([])
  })

  it('clears the default choice when disabling the profile and retains the saved state after a default API error', async () => {
    const view = render(AiPromptProfileForm, { modelValue: form({ setAsDefault: true }), savedProfile: { id: 9, version: 2 }, defaultProfileId: 9, defaultError: 'Cấu hình chưa cập nhật.', canSave: true })

    expect(view.text()).toContain('Đã lưu · #9 · Phiên bản 2')
    expect(view.text()).toContain('Văn phong mặc định')
    expect(view.text()).toContain('Cấu hình chưa cập nhật.')
    await view.get('input[aria-label="Cho phép chọn văn phong khi tạo bài"]').setValue(false)

    const disabled = view.emitted('update:modelValue').at(-1)[0]

    expect(disabled.is_enabled).toBe(false)
    expect(disabled.setAsDefault).toBe(false)
    await view.setProps({ modelValue: disabled })
    expect(view.get('input[aria-label="Đặt làm văn phong mặc định"]').element.disabled).toBe(true)
  })
})

describe('Ai Prompt real and illustrative output', () => {
  it('renders actual summary, rules and evidence while marking unsupported SEO as illustrative', async () => {
    const view = render(AiPromptAnalysisCard, { report: promptPreviewReport, result, status: 'ready', analysisName: 'Văn phong thật' })

    expect(view.text()).toContain('Tóm tắt văn phong')
    expect(view.text()).toContain(result.summary)
    expect(view.text()).not.toContain(promptPreviewReport.summary)
    await button(view, 'Văn phong').trigger('click')
    expect(view.text()).toContain(result.rules.tone)
    expect(view.text()).not.toContain(promptPreviewReport.details.style.description)
    await button(view, 'Dẫn chứng').trigger('click')
    expect(view.get('blockquote').text()).toBe(result.evidence[0].excerpt)
    await button(view, 'SEO & Từ khóa').trigger('click')
    expect(view.text()).toContain('Dữ liệu minh họa')
    expect(view.text()).toContain('Mục này chưa có API')
    expect(view.text()).toContain(promptPreviewReport.details.seo.description)
  })

  it('marks preview as illustrative and never restores a mock prompt when an actual instruction is cleared', async () => {
    const metrics = { wordCount: 37, paragraphCount: 3, imageCount: 1, linkCount: 2, readingMinutes: 1 }
    const view = render(AiPromptInsights, { report: promptPreviewReport, metrics })

    expect(view.text()).toContain('Dữ liệu minh họa')
    expect(view.text()).toContain(promptPreviewReport.promptText)
    expect(button(view, 'Tải báo cáo').element.disabled).toBe(true)
    await view.setProps({ result, status: 'ready', promptText: 'Hướng dẫn người dùng đã sửa', analysisName: 'Mẫu thật', provider: 'gateway', model: 'model-text' })
    expect(view.text()).toContain(result.rules.tone)
    expect(view.text()).toContain('Hướng dẫn người dùng đã sửa')
    expect(view.text()).not.toContain(promptPreviewReport.promptText)
    expect(view.text()).toContain('Các tỷ lệ chưa có API phân tích.')
    await button(view, 'Tải báo cáo').trigger('click')
    expect(view.emitted('export')).toHaveLength(1)
    await view.setProps({ promptText: '' })
    expect(view.text()).not.toContain(result.style_instructions)
    expect(view.text()).not.toContain(promptPreviewReport.promptText)
    expect(button(view, 'Sao chép').element.disabled).toBe(true)
  })
})

describe('Ai Prompt page API wiring', () => {
  it('allows a fresh form when an old analysis is inaccessible without retrying or mutating the server', async () => {
    const id = '12345678-1234-1234-1234-123456789abc'

    window.sessionStorage.setItem('ai_prompt_analysis:8', JSON.stringify({ id }))
    api.mockImplementation(async url => {
      if (url === '/admin/settings/ai') return { providers: [], settings: {} }
      if (url === `/admin/ai/writing-profiles/analyses/${id}`) throw { status: 403, data: { message: 'Bạn không có quyền thực hiện thao tác này.' } }
      throw new Error(`Unexpected API: ${url}`)
    })

    const view = render(AiPromptPage)

    await flushPromises()
    expect(view.text()).toContain('Không có quyền truy cập')
    expect(view.text()).toContain('Bạn không có quyền thực hiện thao tác này.')
    expect(button(view, 'Hủy phân tích')).toBeUndefined()
    expect(button(view, 'Văn phong mới').element.disabled).toBe(false)
    await button(view, 'Văn phong mới').trigger('click')
    await flushPromises()
    expect(button(view, 'Phân tích với AI').element.disabled).toBe(false)
    expect(view.get('input[aria-label="Tên văn phong nguồn"]').element.value).toBe('')
    expect(view.find('form').exists()).toBe(false)
    expect(window.sessionStorage.getItem('ai_prompt_analysis:8')).toBeNull()
    expect(api.mock.calls.map(([url]) => url)).toEqual(['/admin/settings/ai', `/admin/ai/writing-profiles/analyses/${id}`])
  })

  it('queues analysis, resets Add immediately and leaves review to the task popup', async () => {
    const id = '12345678-1234-1234-1234-123456789abc'
    const sourceText = 'Một ví dụ rõ ràng từ bài tham khảo. Bài mẫu giải thích các khái niệm qua tình huống cụ thể.'

    api.mockImplementation(async (url, options = {}) => {
      if (url === '/admin/settings/ai') return { providers: [], settings: {} }
      if (url === '/admin/ai/writing-profiles/analyses') return { success: true, data: { id, name: options.body.name, status: 'queued', task_run_id: 'task-1' } }
      throw new Error(`Unexpected API: ${url}`)
    })

    const view = render(AiPromptPage)

    await flushPromises()
    await view.get('input[aria-label="Tên văn phong nguồn"]').setValue('Văn phong từ giao diện')
    await view.get('textarea[aria-label="Bài tham khảo"]').setValue(`<p>${sourceText}</p>`)
    await button(view, 'Phân tích từ nguồn').trigger('click')
    await flushPromises()

    expect(api).toHaveBeenCalledWith('/admin/ai/writing-profiles/analyses', { method: 'POST', retry: 0, body: { name: 'Văn phong từ giao diện', reference_text: sourceText } })
    expect(view.text()).toContain('Đã đưa phân tích vào hàng đợi')
    expect(view.get('input[aria-label="Tên văn phong nguồn"]').element.value).toBe('')
    expect(view.get('textarea[aria-label="Bài tham khảo"]').element.value).toBe('')
    expect(view.find('form').exists()).toBe(false)
  })

  it.each([422, 503])('keeps the source and handles analysis submit HTTP %s safely', async status => {
    const reference = 'Một ví dụ rõ ràng từ bài tham khảo. Nội dung này cần được giữ lại nếu thao tác lưu thất bại.'

    api.mockImplementation(async (url, options = {}) => {
      if (url === '/admin/settings/ai') return { providers: [], settings: {} }
      if (url === '/admin/ai/writing-profiles/analyses') throw { status, data: { message: 'Chưa xếp được tác vụ.' } }
      throw new Error(`Unexpected API: ${url}`)
    })

    const view = render(AiPromptPage)

    await flushPromises()
    await view.get('input[aria-label="Tên văn phong nguồn"]').setValue('Bản cần giữ')
    await view.get('textarea[aria-label="Bài tham khảo"]').setValue(reference)
    await button(view, 'Phân tích từ nguồn').trigger('click')
    await flushPromises()
    expect(view.get('input[aria-label="Tên văn phong nguồn"]').element.value).toBe('Bản cần giữ')
    expect(view.get('textarea[aria-label="Bài tham khảo"]').element.value).toBe(reference)
    expect(view.text()).toContain('Chưa xếp được tác vụ.')
    if (status === 503) expect(window.sessionStorage.getItem('ai_prompt_analysis:8:pending')).toBe('true')
    else expect(window.sessionStorage.getItem('ai_prompt_analysis:8:pending')).toBeNull()
    expect(button(view, 'Phân tích với AI').element.disabled).toBe(status === 503)
    expect(api.mock.calls.filter(([url]) => url === '/admin/ai/writing-profiles')).toHaveLength(0)
  })

  it('keeps a confirmed profile and the default error visible, allowing an explicit new profile', async () => {
    const id = '12345678-1234-1234-1234-123456789abc'

    window.sessionStorage.setItem('ai_prompt_analysis:8', JSON.stringify({ id }))
    api.mockImplementation(async (url, options = {}) => {
      if (url === '/admin/settings/ai') return { providers: [], settings: { default_writing_profile_id: 3 } }
      if (url === `/admin/ai/writing-profiles/analyses/${id}`) return { success: true, data: { id, name: 'Mẫu đã lưu', status: 'ready', result } }
      if (url === '/admin/ai/writing-profiles') return { success: true, data: { ...options.body, id: 11, version: 1 } }
      if (url === '/admin/settings/ai/settings') throw { status: 422, data: { message: 'Chưa đặt được mặc định.' } }
      throw new Error(`Unexpected API: ${url}`)
    })

    const view = render(AiPromptPage)

    await flushPromises()
    await view.get('input[aria-label="Đặt làm văn phong mặc định"]').setValue(true)
    await view.get('form').trigger('submit')
    await flushPromises()
    expect(view.text()).toContain('Đã lưu · #11 · Phiên bản 1')
    expect(view.text()).toContain('Mẫu đã lưu, nhưng chưa cập nhật được mặc định')
    expect(view.find('form').exists()).toBe(true)
    expect(button(view, 'Văn phong mới').element.disabled).toBe(false)

    const callsBeforeReset = api.mock.calls.length

    await button(view, 'Văn phong mới').trigger('click')
    await flushPromises()
    expect(view.find('form').exists()).toBe(false)
    expect(view.text()).not.toContain('Mẫu đã lưu, nhưng chưa cập nhật được mặc định')
    expect(button(view, 'Phân tích với AI').element.disabled).toBe(false)
    expect(window.sessionStorage.getItem('ai_prompt_analysis:8')).toBeNull()
    expect(window.sessionStorage.getItem(`ai_prompt_profile:8:${id}`)).toBe('11')
    expect(api.mock.calls.length).toBe(callsBeforeReset)
  })

  it('asks before clearing an unsaved review and resets only after confirmation', async () => {
    const id = '12345678-1234-1234-1234-123456789abc'

    window.sessionStorage.setItem('ai_prompt_analysis:8', JSON.stringify({ id }))
    api.mockImplementation(async url => {
      if (url === '/admin/settings/ai') return { providers: [], settings: {} }
      if (url === `/admin/ai/writing-profiles/analyses/${id}`) return { success: true, data: { id, name: 'Mẫu chưa lưu', status: 'ready', result } }
      throw new Error(`Unexpected API: ${url}`)
    })

    const view = render(AiPromptPage)

    await flushPromises()
    await view.get('textarea[aria-label="Hướng dẫn văn phong"]').setValue('Bản đang sửa chưa lưu.')
    await button(view, 'Văn phong mới').trigger('click')
    expect(view.text()).toContain('Xác nhận thao tác')
    await button(view, 'Giữ bản hiện tại').trigger('click')
    expect(view.get('textarea[aria-label="Hướng dẫn văn phong"]').element.value).toBe('Bản đang sửa chưa lưu.')
    await button(view, 'Văn phong mới').trigger('click')
    await button(view, 'Tiếp tục').trigger('click')
    await flushPromises()
    expect(view.find('form').exists()).toBe(false)
    expect(view.text()).not.toContain(result.summary)
    expect(button(view, 'Phân tích với AI').element.disabled).toBe(false)
    expect(window.sessionStorage.getItem('ai_prompt_analysis:8')).toBeNull()
    expect(api.mock.calls.filter(([url]) => url === '/admin/ai/writing-profiles')).toHaveLength(0)
  })

  it('keeps unsaved review edits until opening another analysis is explicitly confirmed', async () => {
    const firstId = '12345678-1234-1234-1234-123456789abc'
    const nextId = 'abcdefab-abcd-abcd-abcd-abcdefabcdef'
    const nextResult = { ...result, summary: 'Tóm tắt văn phong của lượt mới.', style_instructions: 'Hướng dẫn của lượt mới.' }

    window.sessionStorage.setItem('ai_prompt_analysis:8', JSON.stringify({ id: firstId }))
    api.mockImplementation(async url => {
      if (url === '/admin/settings/ai') return { providers: [], settings: { default_writing_profile_id: null } }
      if (url === `/admin/ai/writing-profiles/analyses/${firstId}`) return { success: true, data: { id: firstId, name: 'Văn phong đang duyệt', status: 'ready', result } }
      if (url === `/admin/ai/writing-profiles/analyses/${nextId}`) return { success: true, data: { id: nextId, name: 'Văn phong tiếp theo', status: 'ready', result: nextResult } }
      throw new Error(`Unexpected API: ${url}`)
    })

    const view = render(AiPromptPage)

    await flushPromises()
    expect(view.text()).toContain(result.summary)
    await view.get('textarea[aria-label="Hướng dẫn văn phong"]').setValue('Bản chỉnh sửa cục bộ chưa lưu.')
    await button(view, 'Lịch sử phân tích').trigger('click')
    await view.get('input[aria-label="ID phân tích của bạn"]').setValue(nextId)
    await button(view, 'Mở kết quả').trigger('click')
    await flushPromises()

    expect(api.mock.calls.filter(([url]) => url === `/admin/ai/writing-profiles/analyses/${nextId}`)).toHaveLength(0)
    expect(view.get('textarea[aria-label="Hướng dẫn văn phong"]').element.value).toBe('Bản chỉnh sửa cục bộ chưa lưu.')
    expect(view.text()).toContain(result.summary)
    expect(view.text()).not.toContain(nextResult.summary)
    await button(view, 'Tiếp tục').trigger('click')
    await flushPromises()

    expect(api).toHaveBeenCalledWith(`/admin/ai/writing-profiles/analyses/${nextId}`, expect.objectContaining({ retry: 0 }))
    expect(view.text()).toContain(nextResult.summary)
    expect(view.text()).not.toContain(result.summary)
    expect(view.get('input[aria-label="Tên văn phong"]').element.value).toBe('Văn phong tiếp theo')
    expect(view.get('textarea[aria-label="Hướng dẫn văn phong"]').element.value).toBe(nextResult.style_instructions)
    expect(api.mock.calls.filter(([url]) => url === '/admin/ai/writing-profiles/analyses')).toHaveLength(0)
  })
})
