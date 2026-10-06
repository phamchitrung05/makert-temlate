/* eslint-disable camelcase -- DTO thumbnail public Laravel. */
/**
 * CHỨC NĂNG FILE: Kiểm UI thumbnail pending/lỗi/preview với hành động riêng ảnh.
 * HÀM: render(), afterEach(), các test trạng thái.
 * INPUT/OUTPUT: asset/generation props -> nội dung/disabled/events người dùng.
 * SIDE EFFECT: DOM cô lập và emit; không API/provider thật.
 */
import { afterEach, describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import AiThumbnailStatus from '@/views/ai/shared/AiThumbnailStatus.vue'

let wrapper

/** INPUT: props. OUTPUT: UI tương tác được, stub Vuetify không phát API hoặc fake workflow. */
function render(props) {
  wrapper = mount(AiThumbnailStatus, { props, global: { stubs: {
    VImg: { props: ['src', 'alt'], template: '<img :src="src" :alt="alt">' },
    VProgressLinear: { props: ['modelValue'], template: '<progress :value="modelValue" max="100" />' },
    VBtn: { props: ['disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
  } } })

  return wrapper
}

afterEach(() => wrapper?.unmount())

describe('AI thumbnail status', () => {
  it('keeps article review available while exposing image progress, refresh and cancellation', async () => {
    render({ generation: { status: 'generating', progress: 35, job_id: 'image-1' }, interactive: true })
    expect(wrapper.text()).toContain('Đang tạo thumbnail AI')
    expect(wrapper.get('progress').attributes('value')).toBe('35')
    await wrapper.findAll('button')[0].trigger('click')
    await wrapper.findAll('button')[1].trigger('click')
    expect(wrapper.emitted('check')).toHaveLength(1)
    expect(wrapper.emitted('cancel')).toHaveLength(1)
    expect(wrapper.emitted('retry')).toBeUndefined()
  })

  it('explains image failure and prevents duplicate retry while the request is pending', async () => {
    render({ generation: { status: 'failed', job_id: 'image-1', error: 'Ảnh lỗi; bài vẫn được giữ.' }, interactive: true })
    expect(wrapper.text()).toContain('bài vẫn được giữ')
    await wrapper.get('button').trigger('click')
    expect(wrapper.emitted('retry')).toHaveLength(1)
    await wrapper.setProps({ busy: true })
    expect(wrapper.get('button').element.disabled).toBe(true)
    await wrapper.get('button').trigger('click')
    expect(wrapper.emitted('retry')).toHaveLength(1)
  })

  it('previews the saved image and hides mutation controls on decided candidates', () => {
    render({ asset: { file: { url: '/stored.png', preview_url: '/preview.webp' }, alt_text: 'Minh họa bài' }, generation: { status: 'ready' } })
    expect(wrapper.get('img').attributes('src')).toBe('/preview.webp')
    expect(wrapper.get('img').attributes('alt')).toBe('Minh họa bài')
    expect(wrapper.text()).toContain('Đã tạo thumbnail AI')
    expect(wrapper.find('button').exists()).toBe(false)
  })
})
