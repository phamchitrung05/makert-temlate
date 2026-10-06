/* eslint-disable camelcase -- Fixtures theo DTO Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm form quyết định và so sánh nguồn không thực thi HTML.
 * CÁC HÀM/METHOD TRONG FILE: render(), afterEach(), button(), test cases.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): props/DOM interaction -> event options/locks.
 * SIDE EFFECT: component DOM test, service không được gọi.
 * =====================================================================
 */
import { afterEach, describe, expect, it } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createVuetify } from 'vuetify'
import { VBtn } from 'vuetify/components/VBtn'
import { VCard, VCardActions, VCardItem, VCardSubtitle, VCardText, VCardTitle } from 'vuetify/components/VCard'
import { VForm } from 'vuetify/components/VForm'
import { VCheckbox } from 'vuetify/components/VCheckbox'
import { VCol, VRow, VSpacer } from 'vuetify/components/VGrid'
import { VDivider } from 'vuetify/components/VDivider'
import { VIcon } from 'vuetify/components/VIcon'
import AiContentReviewDecisionDialog from '@/views/ai/content/dialog/AiContentReviewDecisionDialog.vue'
import AiContentComparison from '@/views/ai/content/AiContentComparison.vue'
import AiContentReviewDialog from '@/views/ai/content/dialog/AiContentReviewDialog.vue'

let wrapper
const candidate = { job_id: 'run-1', draft: { title: 'Bài AI', taxonomy_origin: 'manual', category_ids: [3], tag_ids: [4] } }

/** INPUT: component/props. OUTPUT: Vuetify controls thật, wrapper cô lập. */
function render(component, props) {
  wrapper = mount(component, {
    attachTo: document.body, props,
    global: {
      plugins: [createVuetify({ aliases: { IconBtn: VBtn }, components: { VBtn, VCard, VCardActions, VCardItem, VCardSubtitle, VCardText, VCardTitle, VForm, VCheckbox, VCol, VRow, VSpacer, VDivider, VIcon } })],
      stubs: {
        VDialog: { template: '<section><slot /></section>' },
        VOverlay: true,
        VChip: { template: '<span><slot /></span>' },
        VAlert: { template: '<div><slot /></div>' },
        VProgressCircular: true,
        VProgressLinear: true,
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

afterEach(() => { wrapper?.unmount() })

describe('AI content review decision dialog', () => {
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
})

describe('AI content source comparison', () => {
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

  it('explains a missing saved source without fetching it', () => {
    render(AiContentComparison, { source: { available: false }, draft: { title: 'Bài AI' } })
    expect(wrapper.text()).toContain('không tự đọc lại URL')
    expect(wrapper.text()).toContain('Bài AI')
  })
})
