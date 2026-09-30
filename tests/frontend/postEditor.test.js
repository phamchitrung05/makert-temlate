/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử contract TinyMCE wrapper, cấu hình và fallback HTML.
 * CÁC HÀM/METHOD TRONG FILE: mountEditor(); các test v-model/disabled/loading/error.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): props/env giả lập -> assertions, không chạy editor thật.
 * =====================================================================
 */
/* eslint-disable camelcase -- TinyMCE option keys follow the upstream contract. */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PostEditor from '@/views/apps/blog/post/PostEditor.vue'
import TinyEditor from '@tinymce/tinymce-vue'

vi.mock('@/views/apps/blog/post/tinyMceRuntime', () => ({ bundledStyles: 'body { color: black; }' }))
vi.mock('@tinymce/tinymce-vue', () => ({ default: {
  props: ['modelValue', 'disabled', 'init', 'licenseKey', 'apiKey', 'modelEvents'],
  emits: ['update:modelValue', 'init', 'skin-load-error'],
  template: '<textarea data-testid="tinymce" :disabled="disabled" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
} }))

/** Input: props HTML/disabled. Output: mounted wrapper với TinyMCE stub. */
const mountEditor = props => mount(PostEditor, {
  props: { modelValue: '<p>Hello world</p>', ...props },
  global: { stubs: {
    Editor: TinyEditor,
    VAlert: { template: '<div><slot /></div>' },
    AppTextarea: { props: ['modelValue', 'disabled'], emits: ['update:modelValue'], template: '<textarea data-testid="fallback" :disabled="disabled" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />' },
  } },
})

describe('Post TinyMCE integration contract', () => {
  beforeEach(() => {
    vi.stubEnv('VITE_TINYMCE_LICENSE_KEY', '')
    vi.stubEnv('VITE_TINYMCE_API_KEY', '')
  })
  afterEach(() => {
    vi.unstubAllEnvs()
    vi.useRealTimers()
  })

  it('does not assume a license and preserves HTML in the fallback', async () => {
    const wrapper = mountEditor()

    await flushPromises()
    expect(wrapper.text()).toContain('TinyMCE chưa được cấu hình')
    expect(wrapper.find('[data-testid="tinymce"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="fallback"]').element.value).toBe('<p>Hello world</p>')
    await wrapper.get('[data-testid="fallback"]').setValue('<h2>Draft</h2>')
    expect(wrapper.emitted('update:modelValue').at(-1)[0]).toBe('<h2>Draft</h2>')
    wrapper.unmount()
  })

  it('uses the explicitly configured self-host runtime, updates HTML and disables editing', async () => {
    vi.stubEnv('VITE_TINYMCE_LICENSE_KEY', 'gpl')

    const wrapper = mountEditor()

    await flushPromises()

    const editor = wrapper.findComponent(TinyEditor)

    expect(editor.props('licenseKey')).toBe('gpl')
    expect(editor.props('init')).toMatchObject({ skin: false, content_css: false })
    expect(editor.props('init').plugins).toContain('image')
    expect(editor.props('init').block_formats).toContain('Heading 2=h2')
    expect(editor.props('modelEvents')).toContain('input')
    editor.vm.$emit('init')
    await flushPromises()
    expect(wrapper.find('[role="status"]').exists()).toBe(false)
    await wrapper.get('[data-testid="tinymce"]').setValue('<h2>New heading</h2>')
    expect(wrapper.emitted('update:modelValue').at(-1)[0]).toBe('<h2>New heading</h2>')

    const html = '<p><a href="/blog/other">Link</a></p><img src="/image.png" alt="Description">'

    await wrapper.setProps({ modelValue: html, disabled: true })
    expect(editor.props('modelValue')).toBe(html)
    expect(editor.props('disabled')).toBe(true)
    wrapper.unmount()
  })

  it('passes a Cloud key without opting into GPL and recovers HTML after init timeout', async () => {
    vi.useFakeTimers()
    vi.stubEnv('VITE_TINYMCE_API_KEY', 'test-api-key')

    const wrapper = mountEditor()

    await flushPromises()

    const editor = wrapper.findComponent(TinyEditor)

    expect(editor.props('apiKey')).toBe('test-api-key')
    expect(editor.props('licenseKey')).toBeUndefined()
    expect(editor.props('init')).not.toHaveProperty('skin')
    await vi.advanceTimersByTimeAsync(20001)
    expect(wrapper.text()).toContain('Không tải được TinyMCE')
    expect(wrapper.get('[data-testid="fallback"]').element.value).toBe('<p>Hello world</p>')
    wrapper.unmount()
  })
})
