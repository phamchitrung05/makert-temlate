import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import PostForm from '@/views/apps/blog/post/PostForm.vue'
import { passthroughStubs } from './testStubs'

const mocks = vi.hoisted(() => ({ previewSlug: vi.fn() }))

vi.mock('@/utils/api', () => ({ $api: mocks.previewSlug }))

const Field = {
  props: ['modelValue', 'label', 'errorMessages', 'readonly', 'prefix'],
  emits: ['update:modelValue', 'blur'],
  template: '<label>{{ label }}<span data-testid="field-prefix">{{ prefix }}</span><input :aria-label="label" :readonly="readonly" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" @blur="$emit(\'blur\')"><span>{{ errorMessages }}</span></label>',
}

const Button = { props: ['disabled'], template: '<button type="button" :disabled="disabled"><slot /></button>' }
const Form = { methods: { validate: async () => ({ valid: true }) }, template: '<form><slot /></form>' }

const mountForm = props => mount(PostForm, {
  attachTo: document.body,
  props,
  global: {
    plugins: [createPinia()],
    mocks: { requiredValidator: value => Boolean(value) },
    stubs: {
      ...passthroughStubs(['VTabs', 'VRow', 'VCol', 'VCard', 'VCardText', 'VCardItem', 'VAlert', 'VBtnToggle', 'VProgressCircular', 'VExpansionPanels', 'VExpansionPanel', 'VExpansionPanelText']),
      AppTextField: Field, AppTextarea: Field, VTab: Button, VDivider: true, VBtn: Button, VForm: Form,
      PostEditor: { props: ['modelValue'], emits: ['update:modelValue'], template: '<textarea aria-label="Content" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
      PostMediaPanel: true, PostSettingsSidebar: true, MoreBtn: true, VTooltip: true,
      VIcon: true, VSwitch: true, VImg: true, VChip: true, IconBtn: true, VProgressLinear: true,
    },
  },
})

describe('Post form integration', () => {
  beforeEach(() => mocks.previewSlug.mockReset())

  it.each([
    [401, 'Phiên đăng nhập đã hết hạn'],
    [403, 'không có quyền'],
    [404, 'Không tìm thấy API Slug'],
    [422, 'Dữ liệu tạo slug không hợp lệ'],
    [429, 'quá nhanh'],
    [500, 'Máy chủ gặp lỗi'],
  ])('shows actionable errors for HTTP %s', async (status, message) => {
    const requestError = new Error('API failed')

    requestError.status = status
    mocks.previewSlug.mockRejectedValueOnce(requestError)

    const wrapper = mountForm()

    await wrapper.get('input[aria-label="Post Title"]').setValue('Title')
    await wrapper.get('input[aria-label="Post Title"]').trigger('blur')
    await flushPromises()
    expect(wrapper.text()).toContain(message)
    expect(wrapper.get('input[aria-label="Slug"]').element.value).toBe('')
    wrapper.unmount()
  })

  it('distinguishes an expired admin ability token from a model permission denial', async () => {
    const abilityError = new Error('Invalid ability provided.')

    abilityError.status = 403
    abilityError.data = { message: 'Invalid ability provided.' }
    mocks.previewSlug.mockRejectedValueOnce(abilityError)

    const wrapper = mountForm()

    await wrapper.get('input[aria-label="Post Title"]').setValue('Title')
    await wrapper.get('input[aria-label="Post Title"]').trigger('blur')
    await flushPromises()

    expect(wrapper.text()).toContain('Token quản trị không có ability admin')
    expect(wrapper.text()).not.toContain('Tài khoản không có quyền tạo slug cho bài viết')
    wrapper.unmount()
  })

  it('reports an invalid API response and retries on next blur', async () => {
    mocks.previewSlug.mockResolvedValueOnce('<html>Login</html>').mockResolvedValueOnce({ success: true, data: { slug: 'title' } })

    const wrapper = mountForm()
    const title = wrapper.get('input[aria-label="Post Title"]')

    await title.setValue('Title')
    await title.trigger('blur')
    await flushPromises()
    expect(wrapper.text()).toContain('API Slug trả dữ liệu không hợp lệ')
    await title.trigger('blur')
    await flushPromises()
    expect(wrapper.get('input[aria-label="Slug"]').element.value).toBe('title')
    wrapper.unmount()
  })

  it('requests a server slug on blur only, caches unchanged title and ignores stale responses', async () => {
    let resolveOld

    mocks.previewSlug.mockReturnValueOnce(new Promise(resolve => { resolveOld = resolve }))
    mocks.previewSlug.mockResolvedValueOnce({ slug: 'new-title-1' })

    const wrapper = mountForm()
    const title = wrapper.get('input[aria-label="Post Title"]')

    expect(wrapper.findAll('[data-testid="field-prefix"]').some(prefix => prefix.text() === `${window.location.origin}/`)).toBe(true)
    await title.setValue('Old title')
    expect(mocks.previewSlug).not.toHaveBeenCalled()
    await title.trigger('blur')
    await title.setValue('New title')
    await title.trigger('blur')
    await flushPromises()
    resolveOld({ slug: 'old-title' })
    await flushPromises()
    expect(wrapper.get('input[aria-label="Slug"]').element.value).toBe('new-title-1')
    await title.trigger('blur')
    expect(mocks.previewSlug).toHaveBeenCalledTimes(2)
    await title.setValue('')
    await title.trigger('blur')
    expect(wrapper.get('input[aria-label="Slug"]').element.value).toBe('')
    expect(mocks.previewSlug).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('shows preview failure and retries on the next blur', async () => {
    mocks.previewSlug.mockRejectedValueOnce(new Error('offline')).mockResolvedValueOnce({ slug: 'retry' })

    const wrapper = mountForm()
    const title = wrapper.get('input[aria-label="Post Title"]')

    await title.setValue('Retry')
    await title.trigger('blur')
    await flushPromises()
    expect(wrapper.text()).toContain('Không tạo được slug')
    await title.trigger('blur')
    await flushPromises()
    expect(wrapper.get('input[aria-label="Slug"]').element.value).toBe('retry')
    wrapper.unmount()
  })

  it('keeps meta title/description independent and submits persisted metadata', async () => {
    const wrapper = mountForm({ post: { id: 1, title: 'Original', slug: 'original', excerpt: 'Summary', 'seo_title': 'SEO original', 'seo_description': 'SEO summary' } })

    expect(wrapper.get('[data-testid="post-main-column"]').find('[data-testid="post-seo-tabs"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="post-sidebar-column"]').find('[data-testid="post-seo-tabs"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="post-main-column"]').find('post-media-panel-stub').exists()).toBe(false)
    expect(wrapper.get('[data-testid="post-sidebar-column"]').find('post-media-panel-stub').exists()).toBe(true)
    await wrapper.findAll('button').find(button => button.text() === 'Cài đặt').trigger('click')
    expect(wrapper.get('input[aria-label="Meta Title"]').isVisible()).toBe(true)
    await wrapper.get('input[aria-label="Meta Title"]').setValue('SEO changed')
    await wrapper.get('input[aria-label="Meta Description"]').setValue('Description changed')
    await wrapper.get('[data-testid="post-seo-tabs"]').findAll('button').find(button => button.text() === 'Xem trước').trigger('click')
    expect(wrapper.get('input[aria-label="Meta Title"]').isVisible()).toBe(false)
    await wrapper.findAll('button').find(button => button.text() === 'Cài đặt').trigger('click')
    expect(wrapper.get('input[aria-label="Meta Title"]').element.value).toBe('SEO changed')
    expect(wrapper.get('input[aria-label="Post Title"]').element.value).toBe('Original')
    expect(wrapper.get('input[aria-label="Excerpt"]').element.value).toBe('Summary')
    await wrapper.findAll('button').find(button => button.text().includes('Save as Draft')).trigger('click')
    await flushPromises()
    expect(wrapper.emitted('submit')[0][0]).toMatchObject({ title: 'Original', excerpt: 'Summary', seo: { title: 'SEO changed', description: 'Description changed' } })
    expect(mocks.previewSlug).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('updates progress and green-check state both upwards and downwards', async () => {
    const wrapper = mountForm()
    const content = wrapper.get('textarea[aria-label="Content"]')

    expect(wrapper.get('[data-testid="seo-words"]').text()).toContain('(0/300)')
    await content.setValue(`<p>${'word '.repeat(300)}</p>`)
    expect(wrapper.get('[data-testid="seo-words"]').attributes('data-status')).toBe('passed')
    expect(wrapper.get('[data-testid="seo-words"]').text()).toContain('(300/300)')
    await content.setValue('<p>one</p>')
    expect(wrapper.get('[data-testid="seo-words"]').attributes('data-status')).toBe('pending')
    expect(wrapper.get('[data-testid="seo-words"]').text()).toContain('(1/300)')
    wrapper.unmount()
  })

  it('seeds edit data and discards pending requests when switching posts', async () => {
    let resolveOld

    mocks.previewSlug.mockReturnValueOnce(new Promise(resolve => { resolveOld = resolve }))

    const wrapper = mountForm({ post: { id: 1, title: 'First', slug: 'first' } })

    await wrapper.get('input[aria-label="Post Title"]').setValue('Modified')
    await wrapper.get('input[aria-label="Post Title"]').trigger('blur')
    await wrapper.setProps({ post: { id: 2, title: 'Second', slug: 'second' } })
    resolveOld({ slug: 'modified' })
    await flushPromises()
    expect(wrapper.get('input[aria-label="Slug"]').element.value).toBe('second')
    wrapper.unmount()
  })

  it('submits the selected status rather than the native submit event', async () => {
    const wrapper = mountForm({ post: { id: 1, title: 'Article', slug: 'article', status: 'draft' } })

    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.emitted('submit')[0][0].status).toBe('draft')
    wrapper.unmount()
  })
})
