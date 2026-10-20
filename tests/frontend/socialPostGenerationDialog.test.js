/* eslint-disable camelcase -- Fixtures follow the Social API contract. */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import SocialPostDetailDialog from '@/views/marketing/social-post/components/SocialPostDetailDialog.vue'

const { service } = vi.hoisted(() => ({ service: { show: vi.fn(), versions: vi.fn(), regenerateImageSlot: vi.fn() } }))

vi.mock('@/services/socialPost', () => ({ socialPostService: service }))
vi.mock('@/views/apps/media/field/useMediaCapabilities', () => ({ useMediaCapabilities: () => ({ canAttach: { value: false } }) }))
vi.mock('@/views/apps/media/field/MediaAssetField.vue', () => ({ default: { template: '<div />' } }))

let wrapper

const result = status => ({
  id: 12, generationStatus: status === 'queued' ? 'generating' : 'ready', reviewStatus: 'draft',
  generation_jobs: [{ id: 'image-0', component: 'image', slot_no: 0, status }],
  facebook_variant: { content: { body: status === 'queued' ? '' : 'Nội dung đã tạo' }, images: [], image_count: 1 },
})

function dialog() {
  wrapper = mount(SocialPostDetailDialog, {
    props: { modelValue: true, postId: 12 },
    global: { stubs: {
      ...Object.fromEntries(['VDialog', 'VCard', 'VCardTitle', 'VCardText', 'VCardActions', 'VRow', 'VCol', 'VAlert', 'VCardItem', 'VCardSubtitle', 'VList', 'VChip', 'VAvatar'].map(name => [name, { template: '<div><slot /></div>' }])),
      VBtn: { props: ['disabled', 'loading'], template: '<button :disabled="disabled || loading"><slot /></button>' },
      VTextarea: { props: ['modelValue', 'label'], template: '<textarea :aria-label="label" :value="modelValue" />' },
      VTextField: true, VListItem: true, VProgressLinear: true, VIcon: true, VImg: true,
    } },
  })

  return wrapper
}

beforeEach(() => {
  vi.resetAllMocks()
  vi.useFakeTimers()
  service.versions.mockResolvedValue([])
})
afterEach(() => { wrapper?.unmount(); vi.useRealTimers() })

describe('Social generation detail', () => {
  it('polls component jobs until ready and unlocks the generated content', async () => {
    service.show.mockResolvedValueOnce(result('queued')).mockResolvedValue(result('ready'))

    const view = dialog()

    await flushPromises()
    expect(view.get('fieldset').attributes('disabled')).toBeDefined()
    await vi.advanceTimersByTimeAsync(2000)
    await flushPromises()
    expect(view.get('fieldset').attributes('disabled')).toBeUndefined()
    expect(view.get('textarea[aria-label="Nội dung"]').element.value).toBe('Nội dung đã tạo')
    await vi.advanceTimersByTimeAsync(6000)
    expect(service.show).toHaveBeenCalledTimes(2)
  })

  it('stops polling after close and ignores an in-flight response for a different post', async () => {
    let resolveFirst
    service.show.mockImplementationOnce(() => new Promise(resolve => { resolveFirst = resolve }))
      .mockResolvedValue(result('ready'))

    const view = dialog()

    await view.setProps({ postId: 13 })
    await flushPromises()
    resolveFirst(result('queued'))
    await flushPromises()
    expect(view.get('textarea[aria-label="Nội dung"]').element.value).toBe('Nội dung đã tạo')
    await view.setProps({ modelValue: false })
    await vi.advanceTimersByTimeAsync(6000)
    expect(service.show).toHaveBeenCalledTimes(2)
  })

  it('retries a failed image slot without regenerating the complete gallery', async () => {
    service.show.mockResolvedValueOnce(result('failed')).mockResolvedValue(result('queued'))
    service.regenerateImageSlot.mockResolvedValue({ job_id: 'image-0' })

    const view = dialog()

    await flushPromises()
    await view.findAll('button').find(button => button.text().includes('Thử lại ảnh 1')).trigger('click')
    await flushPromises()
    expect(service.regenerateImageSlot).toHaveBeenCalledExactlyOnceWith(12, 0)
    expect(view.get('fieldset').attributes('disabled')).toBeDefined()
    await view.setProps({ modelValue: false })
    await vi.advanceTimersByTimeAsync(6000)
    expect(service.show).toHaveBeenCalledTimes(2)
  })
})
