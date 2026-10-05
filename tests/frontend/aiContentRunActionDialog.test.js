/* eslint-disable camelcase -- Fixture theo contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm thử lựa chọn tạo lại theo capability đúng target.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE: render(), button(), beforeEach(), afterEach(),
 * test options, phản hồi capability cũ, lỗi và action xóa.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): action/capability mock -> lựa chọn và
 * trạng thái nút; không gọi provider hoặc chỉnh Post.
 * SIDE EFFECT: mount Vue với API mock; component được unmount sau mỗi test.
 * =====================================================================
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AiContentRunActionDialog from '@/views/ai/content/AiContentRunActionDialog.vue'
import { passthroughStubs } from './testStubs'

const { capabilities } = vi.hoisted(() => ({ capabilities: vi.fn() }))

vi.mock('@/services/aiAgent', () => ({ aiAgentService: { capabilities } }))

const SelectStub = { props: ['items', 'disabled'], template: '<div />' }
const ButtonStub = { props: ['disabled'], template: '<button :disabled="disabled"><slot /></button>' }
let wrapper

/**
 * =====================================================================
 * CHỨC NĂNG: Mở dialog với target và action được truyền.
 * =====================================================================
 * INPUT: action regenerate/remove, mặc định target Sound.
 * OUTPUT: Vue wrapper dùng control stub để quan sát options và disabled.
 * SIDE EFFECT: mount component; API đã mock không gọi mạng thật.
 * =====================================================================
 */
function render(action = { kind: 'regenerate', item: { title: 'Bài', targetType: 'sound' } }) {
  wrapper = mount(AiContentRunActionDialog, {
    props: { action },
    global: { stubs: {
      AiWritingPreferences: true, AiManualTaxonomyFields: true, AiPipelineReport: true,
      ...passthroughStubs(['VDialog', 'DialogCloseBtn', 'VCard', 'VCardText', 'VAlert', 'AppTextarea']),
      AppSelect: SelectStub, VBtn: ButtonStub,
    } },
  })

  return wrapper
}

/**
 * =====================================================================
 * CHỨC NĂNG: Tìm nút theo nhãn hiển thị.
 * =====================================================================
 * INPUT: tên nút và wrapper hiện tại.
 * OUTPUT: DOM wrapper của nút.
 * SIDE EFFECT: chỉ đọc DOM giả lập.
 * =====================================================================
 */
const button = name => wrapper.findAll('button').find(item => item.text() === name)

beforeEach(() => { capabilities.mockReset() })
afterEach(() => { wrapper?.unmount() })

describe('AI content regeneration options', () => {
  it('loads the selected target capability, removes legacy taxonomy and blocks confirmation during loading', async () => {
    let resolveRequest

    capabilities.mockReturnValue(new Promise(resolve => { resolveRequest = resolve }))
    render()
    expect(capabilities).toHaveBeenCalledExactlyOnceWith('sound')
    expect(button('Tạo lại').element.disabled).toBe(true)
    expect(button('Hủy').element.disabled).toBe(false)
    resolveRequest({ outputs: ['title', 'content', 'taxonomy', 'suggested_tag_ids'], output_options: [
      { value: 'title', title: 'Tên Sound' }, { value: 'content', title: 'Lời bài hát' },
    ] })
    await flushPromises()
    expect(wrapper.findComponent(SelectStub).props('items')).toEqual([
      { title: 'Tên Sound', value: 'title' }, { title: 'Lời bài hát', value: 'content' },
    ])
    expect(button('Tạo lại').element.disabled).toBe(false)
    await button('Tạo lại').trigger('click')
    expect(wrapper.emitted('confirm')[0]).toEqual([{ fields: [], instructions: '' }])
  })

  it('ignores a capability response from a previous target', async () => {
    let resolveOld

    capabilities.mockReturnValueOnce(new Promise(resolve => { resolveOld = resolve }))
      .mockResolvedValueOnce({ outputs: ['title'], output_options: [{ value: 'title', title: 'Tên Resource' }] })
    render()
    await wrapper.setProps({ action: { kind: 'regenerate', item: { title: 'Resource', targetType: 'resource' } } })
    await flushPromises()
    resolveOld({ outputs: ['seo', 'taxonomy'] })
    await flushPromises()
    expect(wrapper.findComponent(SelectStub).props('items')).toEqual([{ title: 'Tên Resource', value: 'title' }])
    expect(capabilities).toHaveBeenLastCalledWith('resource')
  })

  it('keeps cancellation available after a capability error and does not load capabilities for deletion', async () => {
    capabilities.mockRejectedValue(new Error('network'))
    render()
    await flushPromises()
    expect(button('Tạo lại').element.disabled).toBe(true)
    expect(button('Hủy').element.disabled).toBe(false)
    expect(wrapper.text()).toContain('Không tải được hạng mục AI')
    await wrapper.setProps({ action: { kind: 'remove', item: { title: 'Bài', targetType: 'sound' } } })
    expect(capabilities).toHaveBeenCalledOnce()
    expect(button('Xóa').element.disabled).toBe(false)
  })
})
