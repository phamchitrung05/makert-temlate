/* eslint-disable camelcase -- Fixtures follow the Laravel API contract. */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AiImageGenerationDialog from '@/components/ai/AiImageGenerationDialog.vue'
import { passthroughStubs } from './testStubs'

/**
 * =====================================================================
 * CHỨC NĂNG FILE: Khóa image preview/apply và việc bỏ qua response phiên cũ.
 * =====================================================================
 * INPUT: service mocks, image run response và dialog lifecycle.
 * OUTPUT: asset/lineage chỉ được áp dụng từ phiên hiện tại; polling được dọn.
 * SIDE EFFECT: mount Vue, fake timer/API; không gọi model hoặc ghi Post thật.
 * EXCEPTION/TRANSACTION: các promise chậm mô phỏng đóng/mở lại dialog.
 * =====================================================================
 */
const service = vi.hoisted(() => ({
  capabilities: vi.fn(), create: vi.fn(), status: vi.fn(), asset: vi.fn(),
}))

vi.mock('@/services/aiAgent', () => ({ aiAgentService: { capabilities: service.capabilities } }))
vi.mock('@/services/aiImageGeneration', () => ({ aiImageGenerationService: service }))

const mounted = []

const Button = {
  props: ['disabled', 'loading'],
  emits: ['click'],
  template: '<button :disabled="disabled || loading" @click="$emit(\'click\')"><slot /></button>',
}

function dialog() {
  const wrapper = mount(AiImageGenerationDialog, {
    props: { modelValue: true, title: 'Article title' },
    global: {
      stubs: {
        ...passthroughStubs(['VDialog', 'VCard', 'VCardTitle', 'VCardText', 'VTextarea', 'VRow', 'VCol', 'VSelect', 'VAlert', 'VCardActions']),
        VBtn: Button,
        VProgressLinear: { template: '<div data-testid="loading" />' },
        VImg: { props: ['src'], template: '<img :src="src">' },
      },
    },
  })

  mounted.push(wrapper)

  return wrapper
}

const button = (wrapper, text) => wrapper.findAll('button').find(item => item.text() === text)
const image = { id: 7, file: { preview_url: '/preview/7.png' } }

function deferred() {
  let resolve
  const promise = new Promise(_resolve => { resolve = _resolve })

  return { promise, resolve }
}

describe('AiImageGenerationDialog', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.resetAllMocks()
    service.capabilities.mockResolvedValue({ image_providers: [] })
    service.create.mockResolvedValue({ job_id: 'image-run', status: 'queued' })
    service.status.mockResolvedValue({ job_id: 'image-run', status: 'ready', image: { media_asset_id: 7, asset: image } })
  })

  afterEach(() => {
    mounted.splice(0).forEach(wrapper => wrapper.unmount())
    vi.useRealTimers()
  })

  it('delegates an empty model choice to server defaults and emits image lineage', async () => {
    const wrapper = dialog()

    await flushPromises()
    await button(wrapper, 'Tạo ảnh').trigger('click')
    await flushPromises()
    expect(service.create).toHaveBeenCalledWith(expect.objectContaining({ provider: null, model: null, model_id: null }))
    await vi.advanceTimersByTimeAsync(700)
    await flushPromises()
    expect(wrapper.find('img').attributes('src')).toBe('/preview/7.png')
    expect(service.asset).not.toHaveBeenCalled()
    await button(wrapper, 'Dùng thumbnail này').trigger('click')
    expect(wrapper.emitted('apply')[0]).toEqual([image, { runId: 'image-run', fields: ['thumbnail'] }])
  })

  it('does not start polling a late create response after the dialog is reopened', async () => {
    const request = deferred()

    service.create.mockReturnValue(request.promise)

    const wrapper = dialog()

    await flushPromises()
    await button(wrapper, 'Tạo ảnh').trigger('click')
    await wrapper.setProps({ modelValue: false })
    await wrapper.setProps({ modelValue: true })
    request.resolve({ job_id: 'old-run', status: 'queued' })
    await flushPromises()
    await vi.advanceTimersByTimeAsync(2000)
    expect(service.status).not.toHaveBeenCalled()
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('[data-testid="loading"]').exists()).toBe(false)
  })

  it('ignores a late status response instead of showing an old image in a new session', async () => {
    const request = deferred()

    service.status.mockReturnValue(request.promise)

    const wrapper = dialog()

    await flushPromises()
    await button(wrapper, 'Tạo ảnh').trigger('click')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(700)
    expect(service.status).toHaveBeenCalledTimes(1)
    await wrapper.setProps({ modelValue: false })
    await wrapper.setProps({ modelValue: true })
    request.resolve({ job_id: 'old-run', status: 'ready', image: { media_asset_id: 7, asset: image } })
    await flushPromises()
    expect(wrapper.find('img').exists()).toBe(false)
    expect(button(wrapper, 'Dùng thumbnail này')).toBeUndefined()
    expect(wrapper.find('[data-testid="loading"]').exists()).toBe(false)
  })

  it('stops scheduled polling when unmounted', async () => {
    const wrapper = dialog()

    await flushPromises()
    await button(wrapper, 'Tạo ảnh').trigger('click')
    await flushPromises()
    wrapper.unmount()
    mounted.splice(mounted.indexOf(wrapper), 1)
    await vi.advanceTimersByTimeAsync(2000)
    expect(service.status).not.toHaveBeenCalled()
  })
})
