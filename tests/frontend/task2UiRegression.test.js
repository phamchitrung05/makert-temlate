/* eslint-disable camelcase -- DTO public của Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm UI Task 2 với dữ liệu public và các lỗi tìm bằng browser.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: render(), button(), test dialog transition/report/taxonomy/editor.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): props/HTTP giả/tương tác -> nội dung/events.
 * SIDE EFFECT: mount Vue; không gọi AI/database thật hoặc đọc state riêng component.
 * =====================================================================
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AiPromptManageDialog from '@/views/ai/prompt/AiPromptManageDialog.vue'
import AiPipelineReport from '@/views/ai/shared/AiPipelineReport.vue'
import AiManualTaxonomyFields from '@/views/ai/shared/AiManualTaxonomyFields.vue'
import AiContentEditorDialog from '@/views/ai/content/AiContentEditorDialog.vue'

const { all } = vi.hoisted(() => ({ all: vi.fn() }))

vi.mock('@/services/aiArticleTaxonomy', () => ({ aiArticleTaxonomyService: { all } }))

const Slots = { props: ['title'], template: '<section><header>{{ title }}</header><slot /></section>' }
const Button = { props: ['disabled', 'loading'], emits: ['click'], template: '<button :disabled="disabled || loading" @click="$emit(\'click\')"><slot /></button>' }
const Input = { props: ['modelValue', 'label', 'disabled'], emits: ['update:modelValue'], template: '<label>{{ label }}<input :aria-label="label" :value="modelValue" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)"></label>' }
const Autocomplete = { props: ['modelValue', 'label', 'items'], emits: ['update:modelValue'], template: '<section><p>{{ label }}: {{ items.map(item => item.name).join(\', \') }}</p><button @click="$emit(\'update:modelValue\', null)">Xóa {{ label }}</button></section>' }
const ProfileForm = { props: ['modelValue'], emits: ['save'], template: '<section>{{ modelValue.name }}<button @click="$emit(\'save\')">Lưu mẫu</button></section>' }
const PostEditor = { props: ['modelValue', 'disabled'], emits: ['mediaBusy'], template: '<section><textarea aria-label="Nội dung" :value="modelValue" :disabled="disabled" /><button @click="$emit(\'mediaBusy\', true)">Upload ảnh</button><button @click="$emit(\'mediaBusy\', false)">Upload xong</button></section>' }
const wrappers = []

/**
 * =====================================================================
 * Input: component/props.
 * Output: wrapper với dialog vẫn render trong exit transition.
 * =====================================================================
 */
function render(component, props) {
  const wrapper = mount(component, { props, global: { stubs: {
    VDialog: Slots, VCard: Slots, VCardText: Slots, VCardActions: Slots, VAlert: Slots,
    VExpansionPanels: Slots, VExpansionPanel: Slots, VExpansionPanelText: Slots,
    VExpansionPanelTitle: Slots, VChip: Slots, VRow: Slots, VCol: Slots, VBtn: Button,
    VSpacer: true, VIcon: true, VProgressLinear: true, DialogCloseBtn: true,
    AppTextField: Input, AppTextarea: Input, AppAutocomplete: Autocomplete,
    AiPromptProfileForm: ProfileForm, PostEditor,
  } } })

  wrappers.push(wrapper)

  return wrapper
}

/**
 * =====================================================================
 * Input: wrapper/label.
 * Output: nút public nhìn thấy trong UI.
 * =====================================================================
 */
const button = (wrapper, label) => wrapper.findAll('button').find(item => item.text() === label)

afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); all.mockReset() })

describe('Task 2 public UI regression', () => {
  it('does not read a closed action while Vuetify is still rendering a saved profile', async () => {
    const state = { action: { kind: 'edit', id: 4 }, profile: { id: 4, name: 'Mẫu', version: 2 }, form: { name: 'Mẫu' }, loading: false, saving: false, dirty: false }
    const wrapper = render(AiPromptManageDialog, { state })

    await button(wrapper, 'Lưu mẫu').trigger('click')
    expect(wrapper.emitted('save')).toHaveLength(1)
    await wrapper.setProps({ state: { ...state, action: null } })
    expect(wrapper.text()).not.toContain('Tắt mẫu để')
    expect(button(wrapper, 'Tắt văn phong')).toBeUndefined()
  })
  it('requires confirmation before discarding edits to load a new profile version', async () => {
    const state = { action: { kind: 'edit', id: 4 }, profile: { id: 4, name: 'Mẫu' }, form: { name: 'Đang sửa' }, dirty: true, error: 'Version conflict' }
    const wrapper = render(AiPromptManageDialog, { state })

    await button(wrapper, 'Tải phiên bản mới').trigger('click')
    expect(wrapper.emitted('load')).toBeUndefined()
    expect(wrapper.text()).toContain('Các thay đổi chưa lưu sẽ bị bỏ')
    await button(wrapper, 'Tải bản mới').trigger('click')
    expect(wrapper.emitted('load')).toHaveLength(1)
  })
  it('shows real stage usage, saved profile and bounded checks without intermediate content or raw HTML', () => {
    const wrapper = render(AiPipelineReport, { session: {
      status: 'ready', writing_profile: { name: '<img src=x onerror=bad()>', version: 4 }, source_format: 'html',
      steps: [{ key: 'article.analysis-plan', status: 'completed', attempt: 2, diagnostics: { usage: { prompt_tokens: 17, completion_tokens: 23, total_tokens: 40 }, latency_ms: 1250, prompt_version: '2.0', schema_version: 'v1', raw: 'SECRET', output_json: 'DRAFT SECRET' } }, { key: 'article.writer', status: 'failed', diagnostics: { validation_errors: [{ reason: 'evidence_not_in_source' }] } }],
      quality_checks: [{ check: 'semantic_grounding', status: 'undetermined', reason: 'source_anchors_are_not_external_fact_verification' }],
      image_warnings: ['restored_unplaced_images'], editor_warning_count: 2,
    } })

    expect(wrapper.text()).toContain('Phân tích & lập dàn ý')
    expect(wrapper.text()).toContain('Đầu vào: 17 token · Đầu ra: 23 token · Tổng: 40 token')
    expect(wrapper.text()).toContain('1.3 giây')
    expect(wrapper.text()).toContain('Model không trả usage')
    expect(wrapper.text()).toContain('Đối chiếu ngữ nghĩa: Chưa xác định')
    expect(wrapper.text()).toContain('Hệ thống đã giữ ảnh ở cuối bài')
    expect(wrapper.text()).toContain('Dẫn chứng không khớp văn bản của vùng nguồn đã chỉ định.')
    expect(wrapper.text()).not.toContain('SECRET')
    expect(wrapper.find('img').exists()).toBe(false)
  })
  it('does not show missing usage as zero or invent three stages for a short task', () => {
    const wrapper = render(AiPipelineReport, { session: { status: 'ready', steps: [] } })

    expect(wrapper.text()).toContain('chưa có checkpoint ba bước')
    expect(wrapper.text()).not.toContain('0 token')
  })
  it.each([
    { model: 'short-model' },
    { reported_model: 'short-model' },
  ])('shows reported usage for the short branch without inventing checkpoints: %j', modelMetadata => {
    const wrapper = render(AiPipelineReport, { session: { status: 'ready', steps: [], response_diagnostics: { ...modelMetadata, usage: { total_tokens: 31 }, raw_response: 'SECRET' } } })

    expect(wrapper.text()).toContain('Một lượt AI · Tổng: 31 token')
    expect(wrapper.text()).toContain('short-model')
    expect(wrapper.text()).not.toContain('SECRET')
    expect(wrapper.text()).not.toContain('Đã xong · lần')
  })
  it('retains an unavailable manual ID and converts clear events to empty ID arrays', async () => {
    all.mockImplementation(async type => type === 'categories' ? [{ id: 7, name: 'Blog' }] : [{ id: 9, name: 'Tag đang bật' }])

    const wrapper = render(AiManualTaxonomyFields, { categories: [77], tags: [9] })

    await flushPromises()
    expect(wrapper.text()).toContain('#77 · không còn trong danh sách active')
    expect(wrapper.emitted('update:categories')).toBeUndefined()
    await button(wrapper, 'Xóa Danh mục').trigger('click')
    await button(wrapper, 'Xóa Tag').trigger('click')
    expect(wrapper.emitted('update:categories')).toEqual([[[]]])
    expect(wrapper.emitted('update:tags')).toEqual([[[]]])
  })
  it('blocks candidate Save during an upload and allows Save after a stable asset is inserted', async () => {
    all.mockResolvedValue([])

    const wrapper = render(AiContentEditorDialog, { session: { status: 'ready', job_id: 'run', target_type: 'post', draft_version: 'v1', draft: { title: 'Tiêu đề', content_html: '<p>Bài viết</p>', taxonomy_origin: 'manual' } } })

    await flushPromises()
    expect(button(wrapper, 'Lưu nội dung').element.disabled).toBe(false)
    await button(wrapper, 'Upload ảnh').trigger('click')
    expect(button(wrapper, 'Lưu nội dung').element.disabled).toBe(true)
    await button(wrapper, 'Upload xong').trigger('click')
    await button(wrapper, 'Lưu nội dung').trigger('click')
    expect(wrapper.emitted('save')[0][0]).toMatchObject({ title: 'Tiêu đề', category_ids: [], tag_ids: [] })
  })
  it('makes an applied candidate read-only rather than offering edits that cannot be saved', async () => {
    all.mockResolvedValue([])

    const wrapper = render(AiContentEditorDialog, { session: { status: 'ready', job_id: 'run', target_type: 'post', applied_target_id: 12, draft_version: 'v1', draft: { title: 'Đã Apply', content_html: '<p>Bài</p>' } } })

    await flushPromises()
    expect(wrapper.get('input[aria-label="Tiêu đề"]').element.disabled).toBe(true)
    expect(wrapper.get('textarea[aria-label="Nội dung"]').element.disabled).toBe(true)
    expect(button(wrapper, 'Lưu nội dung').element.disabled).toBe(true)
  })
})
