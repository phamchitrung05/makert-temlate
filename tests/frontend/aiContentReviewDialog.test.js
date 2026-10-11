/* eslint-disable camelcase -- Fixtures theo DTO Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm form quyết định và so sánh nguồn không thực thi HTML.
 * CÁC HÀM/METHOD TRONG FILE: render(), afterEach(), button(), test cases,
 * kiểm metadata SEO, tô khác biệt và đồng bộ cuộn.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): props/DOM interaction -> event options/locks.
 * SIDE EFFECT: component DOM test, service không được gọi.
 * =====================================================================
 */
import { afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { onMounted } from 'vue'
import { createVuetify } from 'vuetify'
import { VBtn } from 'vuetify/components/VBtn'
import { VCard, VCardActions, VCardItem, VCardSubtitle, VCardText, VCardTitle } from 'vuetify/components/VCard'
import { VForm } from 'vuetify/components/VForm'
import { VCheckbox } from 'vuetify/components/VCheckbox'
import { VCol, VRow, VSpacer } from 'vuetify/components/VGrid'
import { VDivider } from 'vuetify/components/VDivider'
import { VDialog } from 'vuetify/components/VDialog'
import { VIcon } from 'vuetify/components/VIcon'
import { VOverlay } from 'vuetify/components/VOverlay'
import { VProgressLinear } from 'vuetify/components/VProgressLinear'
import { VSwitch } from 'vuetify/components/VSwitch'
import { VAvatar } from 'vuetify/components/VAvatar'
import AiContentReviewDecisionDialog from '@/views/ai/content/dialog/AiContentReviewDecisionDialog.vue'
import AiContentComparison from '@/views/ai/content/AiContentComparison.vue'
import AiContentReviewDialog from '@/views/ai/content/dialog/AiContentReviewDialog.vue'

let wrapper
const candidate = { job_id: 'run-1', draft: { title: 'Bài AI', taxonomy_origin: 'manual', category_ids: [3], tag_ids: [4] } }

/** INPUT: component/props và tùy chọn dialog thật. OUTPUT: Vuetify controls/teleport thật khi cần, wrapper cô lập. */
function render(component, props, { realDialog = false } = {}) {
  // Happy DOM thiếu biến viewport mà Vuetify đọc; trình duyệt thật có sẵn biến này.
  if (realDialog) vi.stubGlobal('visualViewport', undefined)
  wrapper = mount(component, {
    attachTo: document.body, props,
    global: {
      plugins: [createVuetify({ aliases: { IconBtn: VBtn }, components: { VBtn, VCard, VCardActions, VCardItem, VCardSubtitle, VCardText, VCardTitle, VForm, VCheckbox, VCol, VRow, VSpacer, VDivider, VDialog, VIcon, VOverlay, VProgressLinear, VSwitch, VAvatar } })],
      stubs: {
        transition: !realDialog,
        VDialog: realDialog ? false : {
          emits: ['afterEnter'],
          setup(_props, { emit }) { onMounted(() => emit('afterEnter')) },
          template: '<section><slot /></section>',
        },
        VOverlay: !realDialog,
        VChip: { template: '<span><slot /></span>' },
        VAlert: { template: '<div><slot /></div>' },
        VProgressCircular: true,
        VProgressLinear: !realDialog,
        VImg: true,
        AiManualTaxonomyFields: true,
        AppSelect: {
          name: 'AppSelect',
          props: ['modelValue', 'label', 'disabled'], emits: ['update:modelValue'],
          template: '<select :aria-label="label" :disabled="disabled"><slot /></select>',
        },
        AppTextarea: {
          props: ['modelValue', 'label', 'disabled'], emits: ['update:modelValue'],
          template: '<textarea :value="modelValue" :aria-label="label" :disabled="disabled" @input="$emit(\'update:modelValue\', $event.target.value)" />',
        },
      },
    },
  })

  return wrapper
}

/** INPUT: nhãn. OUTPUT: nút DOM người dùng có thể thao tác. */
const button = label => wrapper.findAll('button').find(node => node.text() === label)

afterEach(() => {
  wrapper?.unmount()
  vi.unstubAllGlobals()
})

describe('AI content review decision dialog', () => {
  it('blocks approval while thumbnail is pending and unlocks when it finishes', async () => {
    render(AiContentReviewDecisionDialog, { kind: 'approve', detail: { ...candidate, can_approve: false } })
    expect(button('Xác nhận duyệt').element.disabled).toBe(true)
    await wrapper.setProps({ detail: { ...candidate, can_approve: true } })
    expect(button('Xác nhận duyệt').element.disabled).toBe(false)
  })
  it('requires a nonblank rejection reason and submits its trimmed value from the fixed footer', async () => {
    render(AiContentReviewDecisionDialog, { kind: 'reject', detail: candidate })
    expect(button('Xác nhận từ chối').element.disabled).toBe(true)
    await wrapper.get('textarea').setValue('   ')
    expect(button('Xác nhận từ chối').element.disabled).toBe(true)
    await wrapper.get('textarea').setValue('  Sai nguồn  ')
    expect(button('Xác nhận từ chối').element.disabled).toBe(false)
    expect(button('Xác nhận từ chối').element.closest('form')).toBeNull()
    button('Xác nhận từ chối').element.click()
    await flushPromises()
    expect(wrapper.emitted('confirm')).toEqual([[{ reason: 'Sai nguồn' }]])
    expect(wrapper.get('footer').text()).toContain('Hủy')
    expect(wrapper.get('header').text()).toContain('Từ chối content AI')
  })

  it('preserves the reason across errors and fresh detail and locks every control during POST', async () => {
    render(AiContentReviewDecisionDialog, { kind: 'reject', detail: candidate })
    await wrapper.get('textarea').setValue('Lý do đang nhập')
    await wrapper.setProps({ error: 'Nội dung đã thay đổi', blocked: true })
    expect(wrapper.get('textarea').element.value).toBe('Lý do đang nhập')
    expect(button('Xác nhận từ chối').element.disabled).toBe(true)
    await button('Kiểm tra trạng thái').trigger('click')
    expect(wrapper.emitted('reload')).toHaveLength(1)
    await wrapper.setProps({ detail: { ...candidate, draft_version: 'mới' }, blocked: false, error: '', busy: true })
    expect(wrapper.get('textarea').element.value).toBe('Lý do đang nhập')
    expect(wrapper.get('textarea').element.disabled).toBe(true)
    expect(button('Hủy').element.disabled).toBe(true)
    await wrapper.get('form').trigger('submit')
    expect(wrapper.emitted('confirm')).toBeUndefined()
    await wrapper.setProps({ busy: false })
    await wrapper.get('form').trigger('submit')
    expect(wrapper.emitted('confirm')).toEqual([[{ reason: 'Lý do đang nhập' }]])
  })

  it('requires a title and manual confirmation for legacy taxonomy before approving', async () => {
    render(AiContentReviewDecisionDialog, { kind: 'approve', detail: { ...candidate, draft: { title: 'Bản cũ' } } })
    expect(button('Xác nhận duyệt').element.disabled).toBe(true)
    await wrapper.get('input[type="checkbox"]').setValue(true)
    await wrapper.get('form').trigger('submit')
    expect(wrapper.emitted('confirm')[0][0]).toEqual({ reason: null, fields: ['title', 'excerpt', 'content', 'seo', 'taxonomy'], category_ids: [], tag_ids: [] })
    wrapper.findComponent({ name: 'AppSelect' }).vm.$emit('update:modelValue', ['content'])
    await flushPromises()
    expect(button('Xác nhận duyệt').element.disabled).toBe(true)
  })
})

describe('AI content review dialog', () => {
  it('renders ready article text after enter and keeps it until the leave effect finishes', async () => {
    const state = { open: true, loading: false, busy: false,
      detail: { ...candidate, can_review: true, source: { available: true, content_html: '<p>Nguồn trả nhanh</p>' },
        draft: { ...candidate.draft, content_html: '<p>Bản AI trả nhanh</p>' } },
      history: [], historyPagination: { current_page: 1, last_page: 1 }, decisionBlocked: false }

    render(AiContentReviewDialog, { state }, { realDialog: true })
    await flushPromises()

    const dialog = document.querySelector('[role="dialog"]')

    expect(dialog.textContent).not.toContain('Nguồn trả nhanh')
    expect(dialog.textContent).not.toContain('Bản AI trả nhanh')
    await vi.waitFor(() => expect(dialog.textContent).toContain('Bản AI trả nhanh'))
    expect(dialog.textContent).toContain('Nguồn trả nhanh')
    await wrapper.setProps({ state: { ...state, open: false } })
    expect(dialog.textContent).toContain('Bản AI trả nhanh')
    await vi.waitFor(() => expect(wrapper.emitted('afterLeave')).toHaveLength(1))
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })

  it('shows the real dialog shell during a pending read with one backdrop and an available close button', async () => {
    const state = { open: true, loading: true, busy: false, detail: { job_id: candidate.job_id, draft: { title: candidate.draft.title } },
      history: [], historyPagination: { current_page: 0, last_page: 1 }, decisionBlocked: true }

    render(AiContentReviewDialog, { state }, { realDialog: true })
    await flushPromises()

    const dialog = document.querySelector('[role="dialog"]')

    expect(dialog).not.toBeNull()
    expect(dialog.querySelector('header').textContent).toContain('Duyệt content AI')
    expect(dialog.querySelector('[role="status"]').textContent).toContain('Đang tải nguồn và nội dung đã lưu')
    expect(dialog.querySelector('[aria-label="Đang tải nội dung để duyệt"]').getAttribute('role')).toBe('progressbar')
    expect(dialog.querySelectorAll('.v-overlay__scrim')).toHaveLength(1)
    expect(dialog.textContent).not.toContain('Bản này chưa có nguồn để đối chiếu')

    const close = dialog.querySelector('button[aria-label="Đóng duyệt content AI"]')

    expect(close.disabled).toBe(false)
    close.click()
    await flushPromises()
    expect(wrapper.emitted('close')).toHaveLength(1)
    await wrapper.setProps({ state: { ...state, open: false } })
    await vi.waitFor(() => expect(wrapper.emitted('afterLeave')).toHaveLength(1))
    expect(document.querySelector('[role="dialog"]')).toBeNull()
  })

  it('locks decisions during loading and places source/history inside the body with actions in the footer', async () => {
    const state = { open: true, loading: true, busy: false, detail: { ...candidate, can_review: true, review: { status: 'pending_review' }, source: { available: true, content_html: '<p>Nguồn đã lưu</p>' } },
      history: [], historyPagination: { current_page: 1, last_page: 1 }, decisionBlocked: true }

    render(AiContentReviewDialog, { state })
    expect(button('Duyệt tạo Post nháp').element.disabled).toBe(true)
    expect(wrapper.get('header').text()).toContain('Duyệt content AI')
    expect(wrapper.get('.app-dialog-layout__body').text()).toContain('Nguồn đã lưu')
    expect(wrapper.get('.app-dialog-layout__body').text()).toContain('Lịch sử biên tập')
    expect(wrapper.get('footer').text()).toContain('Từ chối')
    await wrapper.setProps({ state: { ...state, loading: false, decisionBlocked: false } })
    await button('Từ chối').trigger('click')
    expect(wrapper.emitted('reject')).toHaveLength(1)
    await button('Duyệt tạo Post nháp').trigger('click')
    expect(wrapper.emitted('approve')).toHaveLength(1)
  })

  it('shows actor, reason and history for a rejected candidate without decision controls', async () => {
    render(AiContentReviewDialog, { state: { open: true, loading: false, busy: false, detail: { ...candidate, can_review: false,
      review: { status: 'rejected', reviewed_by: { name: 'Người duyệt' }, reviewed_at: '2026-10-05T10:00:00Z', reason: 'Sai số liệu' } },
    history: [{ id: 1, event: 'candidate.rejected', actor: { name: 'Người duyệt' }, reason: 'Sai số liệu', at: '2026-10-05T10:00:00Z' }],
    historyPagination: { current_page: 1, last_page: 2 } } })
    expect(wrapper.text()).toContain('Người duyệt')
    expect(wrapper.text()).toContain('Sai số liệu')
    expect(button('Duyệt tạo Post nháp')).toBeUndefined()
    expect(button('Từ chối')).toBeUndefined()
    await button('Xem lịch sử trước').trigger('click')
    expect(wrapper.emitted('historyMore')).toHaveLength(1)
  })

  it('renders quality rubric criteria as Vietnamese score badges', () => {
    render(AiContentReviewDialog, { state: { open: true, loading: false, busy: false,
      detail: { ...candidate, can_review: false, quality_evaluation: {
        status: 'ready', score_total: 4.5, rubric_version: 'v1',
        scores: { accuracy: 5, source_grounding: 5, clarity: 4, structure: 4.5, style: 4 },
        eligibility: { eligible: true, reasons: [] },
      } },
      history: [], historyPagination: { current_page: 1, last_page: 1 } } })

    expect(wrapper.text()).toContain('Độ chính xác: 5.0/5')
    expect(wrapper.text()).toContain('Bám sát nguồn: 5.0/5')
    expect(wrapper.text()).toContain('Độ rõ ràng: 4.0/5')
    expect(wrapper.text()).toContain('Cấu trúc: 4.5/5')
    expect(wrapper.text()).toContain('Văn phong: 4.0/5')
    expect(wrapper.text()).not.toContain('source_grounding:')
  })
})

describe('AI content source comparison', () => {
  it('keeps the comparison headings while loading and shows saved data after the read completes', async () => {
    render(AiContentComparison, { loading: true })
    expect(wrapper.text()).toContain('Nguồn đã lưu')
    expect(wrapper.text()).toContain('Nội dung AI sau biên tập')
    expect(wrapper.text()).toContain('Đang tải nguồn đã lưu')
    expect(wrapper.text()).toContain('Đang tải nội dung AI')
    expect(wrapper.text()).not.toContain('Bản này chưa có nguồn để đối chiếu')
    await wrapper.setProps({ loading: false, source: { available: true, content_html: '<p>Nguồn gốc</p>' },
      draft: { content_html: '<p>Bản đã sửa và lưu</p>' } })
    expect(wrapper.text()).toContain('Nguồn gốc')
    expect(wrapper.text()).toContain('Bản đã sửa và lưu')
    expect(wrapper.text()).not.toContain('Đang tải')
  })

  it('shows code and paragraphs as text without inserting scripts, images, iframes or links into the DOM', () => {
    render(AiContentComparison, {
      source: { available: true, title: 'Nguồn', content_html: '<p>Số liệu 123.</p><script>malicious()</script><iframe src="https://example.test"></iframe><img src="https://example.test/pixel"><pre>&lt;script&gt;code mẫu&lt;/script&gt;</pre>' },
      draft: { title: 'Kết quả AI', content_html: '<p>Bản AI 123.</p><a href="javascript:alert(1)">Nhãn liên kết</a>' },
    })
    expect(wrapper.text()).toContain('Số liệu 123.')
    expect(wrapper.text()).toContain('<script>code mẫu</script>')
    expect(wrapper.text()).toContain('Nhãn liên kết')
    expect(wrapper.text()).not.toContain('malicious()')
    expect(wrapper.find('script,iframe,img,a').exists()).toBe(false)
  })

  it('shows both comparison controls and SEO metadata below the source panels', () => {
    render(AiContentComparison, {
      source: {
        available: true,
        title: 'Nguồn gốc',
        seo_title: 'Tiêu đề nguồn',
        focus_keyword: 'từ khóa nguồn',
        seo_description: 'Mô tả nguồn',
        content_html: '<p>Đoạn nguồn.</p>',
      },
      draft: {
        title: 'Bài AI',
        seo_title: 'Tiêu đề AI',
        focus_keyword: 'từ khóa AI',
        seo_description: 'Mô tả AI',
        content_html: '<p>Đoạn AI.</p>',
      },
    })

    expect(wrapper.text()).toContain('Đồng bộ cuộn')
    expect(wrapper.text()).toContain('Tô khác biệt')
    expect(wrapper.findAll('.ai-content-comparison__panel')).toHaveLength(2)
    expect(wrapper.findAll('.ai-content-comparison__metadata')).toHaveLength(2)
    expect(wrapper.text()).toContain('Tiêu đề nguồn')
    expect(wrapper.text()).toContain('từ khóa nguồn')
    expect(wrapper.text()).toContain('Mô tả nguồn')
    expect(wrapper.text()).toContain('Tiêu đề AI')
    expect(wrapper.text()).toContain('từ khóa AI')
    expect(wrapper.text()).toContain('Mô tả AI')
    expect(wrapper.find('.ai-content-comparison__block--removed').exists()).toBe(true)
    expect(wrapper.find('.ai-content-comparison__block--added').exists()).toBe(true)
  })

  it('synchronizes the other panel scroll position by the current ratio', async () => {
    render(AiContentComparison, {
      source: { available: true, content_html: '<p>Nguồn dài.</p>' },
      draft: { content_html: '<p>Bản AI dài.</p>' },
    })

    const sourceViewer = wrapper.get('[aria-label="Văn bản nguồn"]').element
    const resultViewer = wrapper.get('[aria-label="Văn bản AI"]').element

    Object.defineProperties(sourceViewer, {
      scrollHeight: { configurable: true, value: 200 },
      clientHeight: { configurable: true, value: 100 },
    })
    Object.defineProperties(resultViewer, {
      scrollHeight: { configurable: true, value: 500 },
      clientHeight: { configurable: true, value: 100 },
    })
    sourceViewer.scrollTop = 50
    await wrapper.get('[aria-label="Văn bản nguồn"]').trigger('scroll')

    expect(resultViewer.scrollTop).toBe(200)
  })

  it('explains a missing saved source without fetching it', () => {
    render(AiContentComparison, { source: { available: false }, draft: { title: 'Bài AI' } })
    expect(wrapper.text()).toContain('không tự đọc lại URL')
    expect(wrapper.text()).toContain('Bài AI')
  })
})
