/* eslint-disable camelcase -- Payload kiểm thử dùng contract Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm tra nối API vào layout Social Post và giữ dữ liệu mẫu.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - listResponse(): fixture Facebook variant, KPI và phân trang.
 * - page(): mount trang với service/composable thật, giả lập HTTP và UI con.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): thao tác UI -> request API và nội dung card.
 * =====================================================================
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import SocialPostList from '@/pages/marketing/social-post/list/index.vue'

const { api, route } = vi.hoisted(() => ({ api: vi.fn(), route: { query: {} } }))

vi.mock('vue-router', () => ({ useRoute: () => route }))

vi.mock('@/utils/api', () => ({ $api: api }))
vi.mock('@/views/marketing/social-post/components/SocialPostDetailDialog.vue', () => ({
  default: { name: 'SocialPostDetailDialog', props: ['modelValue', 'postId'], template: '<div />' },
}))
vi.mock('@/views/marketing/social-post/components/SocialPostCreateDialog.vue', () => ({
  default: { name: 'SocialPostCreateDialog', props: ['modelValue', 'products', 'campaigns', 'accounts'], template: '<div />' },
}))

let wrapper

/** Input: tiêu đề. Output: BaseResponse gồm content/gallery đã lưu trong backend. */
function listResponse(title = 'Tiêu đề API') {
  return {
    success: true,
    data: [{
      id: 4, caption: 'Caption cũ', review_status: 'pending_review', publication_status: 'pending',
      generation_status: 'ready', created_at: '2026-10-17T10:30:00+07:00',
      facebook_variant: {
        content: { title, body: 'Nội dung từ API', hashtags: ['#that', 'facebook'] },
        images: [{ asset: { file: { preview_url: '/preview/real-image.jpg' } } }],
      },
    }],
    meta: { pagination: { total: 21 }, stats: { pending_review: 7, approved: 3, published: 10, failed: 1 } },
  }
}

/** Input: không có. Output: mount layout thật; mock HTTP và component framework/dialog. */
function page() {
  wrapper = mount(SocialPostList, {
    global: {
      stubs: {
        ...Object.fromEntries([
          'VRow', 'VCol', 'VCard', 'VAvatar', 'VIcon', 'VChip', 'VDivider', 'VBtnToggle',
        ].map(name => [name, { template: '<div><slot /></div>' }])),
        VBtn: {
          props: ['disabled', 'loading'],
          template: '<button :disabled="disabled || loading"><slot /></button>',
        },
        VImg: { props: ['src'], template: '<img :src="src">' },
        VProgressCircular: { template: '<div role="progressbar" />' },
        VOverlay: { props: ['modelValue'], template: '<div v-if="modelValue"><slot /></div>' },
        VSnackbar: { props: ['modelValue', 'color'], template: '<div v-if="modelValue" role="status" :data-color="color"><slot /></div>' },
        AppDateTimePicker: {
          props: ['modelValue'], emits: ['update:modelValue'],
          template: '<input :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">',
        },
        AppSelect: { name: 'AppSelect', props: ['modelValue', 'items'], template: '<div />' },
        VPagination: { name: 'VPagination', props: ['modelValue', 'length'], template: '<div />' },
      },
    },
  })

  return wrapper
}

describe('Social Post list with the updated layout', () => {
  beforeEach(() => {
    route.query = {}
    vi.stubGlobal('definePage', vi.fn())
    api.mockReset()
    api.mockImplementation(async (url, options = {}) => {
      if (options.method) return { success: true, data: { post: { id: 9 }, generation: null } }
      if (url === '/admin/social-posts') return listResponse()
      if (url === '/admin/marketing/products') return { success: true, data: [{ id: 1, name: 'Sản phẩm thật' }] }
      if (url === '/admin/marketing/campaigns') return { success: true, data: [{ id: 2, name: 'Campaign thật' }] }
      if (url === '/admin/social-accounts') return { success: true, data: [{ id: 3, display_name: 'Page thật' }] }
      throw new Error(`Unexpected request: ${url}`)
    })
  })

  afterEach(() => wrapper?.unmount())

  it('opens the newly queued Social draft from the AI Content link', async () => {
    route.query = { post: '42' }

    const view = page()

    await flushPromises()

    const dialog = view.findComponent({ name: 'SocialPostDetailDialog' })

    expect(dialog.props('postId')).toBe(42)
    expect(dialog.props('modelValue')).toBe(true)
  })

  it('maps Facebook content/gallery and KPI values into the existing cards, keeping static trends', async () => {
    const view = page()

    await flushPromises()
    expect(view.text()).toContain('Tiêu đề API')
    expect(view.get('.social-post-description').text()).toBe('Nội dung từ API')
    expect(view.text()).toContain('#facebook')
    expect(view.get('img[src="/preview/real-image.jpg"]').exists()).toBe(true)
    expect(view.findAll('span.text-h4').map(card => card.text())).toEqual(['7', '3', '10', '1'])
    expect(view.findAll('.sparkline-svg')).toHaveLength(4)
    expect(view.text()).toContain('12%')
    expect(view.findComponent({ name: 'VPagination' }).props('length')).toBe(3)
    expect(api).toHaveBeenCalledTimes(1)
    expect(api).toHaveBeenCalledWith('/admin/social-posts', { query: expect.objectContaining({ page: 1, per_page: 8 }) })
  })

  it('filters Facebook/dates and resets pagination with one list request per change', async () => {
    const view = page()

    await flushPromises()
    view.findComponent({ name: 'VPagination' }).vm.$emit('update:modelValue', 2)
    await flushPromises()
    api.mockClear()
    await view.get('.platform-filter--facebook').trigger('click')
    await flushPromises()
    expect(api.mock.calls).toEqual([
      ['/admin/social-posts', { query: expect.objectContaining({ platform: 'facebook', page: 1 }) }],
    ])
    await view.get('input').setValue('2026-10-01 to 2026-10-17')
    await flushPromises()
    expect(api).toHaveBeenLastCalledWith('/admin/social-posts', {
      query: expect.objectContaining({ created_from: '2026-10-01', created_to: '2026-10-17' }),
    })
    view.findComponent({ name: 'VPagination' }).vm.$emit('update:modelValue', 2)
    await flushPromises()
    api.mockClear()
    view.findComponent({ name: 'AppSelect' }).vm.$emit('update:modelValue', 12)
    await flushPromises()
    expect(api.mock.calls).toEqual([
      ['/admin/social-posts', { query: expect.objectContaining({ page: 1, per_page: 12 }) }],
    ])
    await view.get('input').setValue('')
    await flushPromises()
    expect(api).toHaveBeenLastCalledWith('/admin/social-posts', {
      query: expect.objectContaining({ created_from: '', created_to: '' }),
    })
  })

  it('keeps unsupported platform samples and never sends their IDs to real endpoints', async () => {
    const view = page()

    await flushPromises()
    api.mockClear()
    await view.get('.platform-filter--instagram').trigger('click')
    await flushPromises()
    expect(view.text()).toContain('Khám phá hương vị mới')
    expect(view.text()).toContain('Khuyến mãi tháng 10')
    expect(view.text()).not.toContain('Tiêu đề API')
    await view.findAll('button[title="Xem chi tiết"]')[0].trigger('click')
    await view.findAll('button[title="Duyệt bài"]')[0].trigger('click')
    await view.findAll('button[title="Từ chối"]')[0].trigger('click')
    await flushPromises()
    expect(api).not.toHaveBeenCalled()
    expect(view.findComponent({ name: 'SocialPostDetailDialog' }).props('modelValue')).toBe(false)
  })

  it('opens API detail and reloads after saving without changing the card layout', async () => {
    const view = page()

    await flushPromises()
    await view.get('button[title="Xem chi tiết"]').trigger('click')

    const dialog = view.findComponent({ name: 'SocialPostDetailDialog' })

    expect(dialog.props()).toMatchObject({ modelValue: true, postId: 4 })
    api.mockClear()
    dialog.vm.$emit('saved')
    await flushPromises()
    expect(api).toHaveBeenCalledTimes(1)
    expect(api).toHaveBeenCalledWith('/admin/social-posts', expect.any(Object))
  })

  it.each([['Duyệt bài', 'approve'], ['Từ chối', 'reject']])('connects %s to review API and refreshes', async (label, action) => {
    const view = page()

    await flushPromises()
    await view.get(`button[title="${label}"]`).trigger('click')
    await flushPromises()
    expect(api).toHaveBeenCalledWith(`/admin/social-posts/4/${action}`, expect.objectContaining({ method: 'POST' }))
    expect(view.get('[role="status"]').attributes('data-color')).toBe('success')
    expect(api.mock.calls.filter(([url]) => url === '/admin/social-posts')).toHaveLength(2)
  })

  it('keeps API errors visible and retries an empty failed list without inserting fake Facebook rows', async () => {
    api.mockRejectedValueOnce({ data: { message: 'Không tải được dữ liệu.' } })

    const view = page()

    await flushPromises()
    expect(view.text()).toContain('Không tải được dữ liệu.')
    expect(view.findAll('.social-post-description')).toHaveLength(0)
    expect(view.text()).toContain('0 - 0')
    await view.findAll('button').find(button => button.text() === 'Tải lại').trigger('click')
    await flushPromises()
    expect(view.text()).toContain('Tiêu đề API')
    api.mockRejectedValueOnce({ data: { message: 'Không có quyền duyệt.' } })
    await view.get('button[title="Duyệt bài"]').trigger('click')
    await flushPromises()
    expect(view.get('[role="status"]').text()).toBe('Không có quyền duyệt.')
    expect(view.get('[role="status"]').attributes('data-color')).toBe('error')
  })

  it('loads creation options on demand and keeps the form open on failed create', async () => {
    const view = page()

    await flushPromises()
    await view.findAll('button').find(button => button.text() === 'Tạo bài viết').trigger('click')
    await flushPromises()

    const dialog = view.findComponent({ name: 'SocialPostCreateDialog' })
    const payload = { product_id: 1, caption: 'Bài mới', target_account_ids: [3], generate: false }

    expect(dialog.props()).toMatchObject({ modelValue: true, products: [{ id: 1, name: 'Sản phẩm thật' }] })
    api.mockRejectedValueOnce({ data: { message: 'Sản phẩm không hợp lệ.' } })
    dialog.vm.$emit('submit', payload)
    await flushPromises()
    expect(dialog.props('modelValue')).toBe(true)
    expect(view.get('[role="status"]').text()).toBe('Sản phẩm không hợp lệ.')
    dialog.vm.$emit('submit', payload)
    dialog.vm.$emit('submit', payload)
    await flushPromises()
    expect(dialog.props('modelValue')).toBe(false)
    expect(api.mock.calls.filter(([, options]) => options?.method === 'POST')).toHaveLength(2)
    expect(api).toHaveBeenCalledWith('/admin/social-posts', { method: 'POST', body: payload })
  })

  it('ignores a stale API response after switching to a static platform', async () => {
    let resolveOld

    api.mockReturnValueOnce(new Promise(resolve => { resolveOld = resolve }))

    const view = page()

    await view.get('.platform-filter--tiktok').trigger('click')
    await flushPromises()
    resolveOld(listResponse('Response đã cũ'))
    await flushPromises()
    expect(view.text()).toContain('Hành trình khám phá đảo Kỳ Co')
    expect(view.text()).not.toContain('Response đã cũ')
    expect(view.findAll('span.text-h4').map(card => card.text())).toEqual(['0', '0', '0', '0'])
    await view.get('.platform-filter--all').trigger('click')
    await flushPromises()
    expect(view.text()).toContain('Tiêu đề API')
  })
})
