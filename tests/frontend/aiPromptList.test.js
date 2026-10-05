/* eslint-disable camelcase -- DTO/query giữ tên field của Laravel. */
/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm danh sách văn phong với HTTP giả và datatable Vuetify thật.
 * =====================================================================
 * CÁC HÀM/METHOD TRONG FILE:
 * - profile(overrides): tạo DTO profile độc lập theo resource backend.
 * - response(items, total, overrides): tạo envelope pagination server.
 * - deferred(): điều khiển response trễ/HTTP lỗi trong kiểm race và cleanup.
 * - createList(): chạy composable trong effectScope được dọn sau mỗi ca.
 * - renderList(): mount giao diện với router/Vuetify thật, input primitive giả.
 * - beforeEach()/afterEach(): reset HTTP, unmount/stop scope và phục hồi timer.
 * - các ca GET/phân trang/debounce/race/lỗi/cleanup và xem prompt/điều hướng.
 * INPUT/OUTPUT CỦA CLASS (tổng thể):
 * - INPUT : tương tác bảng/input, HTTP envelope thật về cấu trúc và response trễ.
 * - OUTPUT: assertions query/tổng dòng/nội dung nhìn thấy; không đọc state private.
 * - SIDE EFFECT: GET giả lập; không gọi model hoặc ghi database thật.
 * =====================================================================
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { effectScope, nextTick } from 'vue'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createVuetify } from 'vuetify'
import { VDataTableServer } from 'vuetify/components/VDataTable'
import { VBtn } from 'vuetify/components/VBtn'
import { VChip } from 'vuetify/components/VChip'
import { useAiPromptList } from '@/composables/ai/prompt/useAiPromptList'
import AiPromptList from '@/views/ai/prompt/AiPromptList.vue'

const api = vi.hoisted(() => vi.fn())

vi.mock('@/utils/api', () => ({ $api: api }))

const scopes = []
const wrappers = []

/**
 * =====================================================================
 * Input: field overrides cho từng ca. Output: profile mới, origin theo backend.
 * =====================================================================
 */
function profile(overrides = {}) {
  return { id: 7, name: 'Rõ ràng', description: 'Giải thích bằng ví dụ', origin: 'reference',
    version: 2, is_enabled: true, style_instructions: 'Viết rõ ràng.\nĐưa ví dụ cụ thể.', updated_at: '2026-10-04T07:04:00Z', ...overrides }
}

/**
 * =====================================================================
 * Input: rows, total và pagination tùy ca. Output: envelope BaseResponse phân trang.
 * =====================================================================
 */
function response(items = [profile()], total = items.length, overrides = {}) {
  return { success: true, data: items, meta: { pagination: { total, current_page: 1, per_page: 15, last_page: Math.max(1, Math.ceil(total / 15)), ...overrides } } }
}

/**
 * =====================================================================
 * Input: không có. Output: promise/resolve/reject để kiểm response cũ tới muộn.
 * =====================================================================
 */
function deferred() {
  let resolve
  let reject

  const promise = new Promise((_resolve, _reject) => {
    resolve = _resolve
    reject = _reject
  })

  return { promise, resolve, reject }
}

/**
 * =====================================================================
 * Input: HTTP mock đã thiết lập. Output: public state/action của composable thật.
 * SIDE EFFECT: tạo scope, đăng ký GET đầu tiên và cleanup cho afterEach.
 * =====================================================================
 */
function createList() {
  const scope = effectScope()

  scopes.push(scope)

  return { list: scope.run(() => useAiPromptList()), scope }
}

/**
 * =====================================================================
 * Input: HTTP mock đã thiết lập. Output: wrapper và router; bảng/nút Vuetify thật.
 * SIDE EFFECT: mount Vue; stub ô nhập chỉ giữ contract v-model và label.
 * =====================================================================
 */
async function renderList() {
  const routeView = { template: '<div />' }

  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/ai/prompt/list', name: 'ai-prompt-list', component: routeView },
    { path: '/ai/prompt/add', name: 'ai-prompt-add', component: routeView },
  ] })

  await router.push({ name: 'ai-prompt-list' })
  await router.isReady()

  const wrapper = mount(AiPromptList, {
    attachTo: document.body,
    global: {
      plugins: [router, createVuetify()],
      components: { VDataTableServer, VBtn, VChip },
      stubs: {
        VCard: { template: '<section><slot /></section>' },
        VCardText: { template: '<div><slot /></div>' },
        VRow: { template: '<div><slot /></div>' },
        VCol: { template: '<div><slot /></div>' },
        VDivider: true,
        VAlert: { template: '<div role="alert"><slot /></div>' },
        AppTextField: {
          props: ['modelValue', 'label'], emits: ['update:modelValue'],
          template: '<label>{{ label }}<input :aria-label="label" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)"></label>',
        },
      },
    },
  })

  wrappers.push(wrapper)
  await flushPromises()

  return { wrapper, router }
}

describe('Ai Prompt saved-profile list', () => {
  // =====================================================================
  // Input: bắt đầu ca test. Output: HTTP mock độc lập; không dùng API thật.
  // =====================================================================
  beforeEach(() => { api.mockReset() })

  // =====================================================================
  // Input: kết thúc ca test. Output: scope/component/timer đều được giải phóng.
  // =====================================================================
  afterEach(() => {
    wrappers.splice(0).forEach(wrapper => wrapper.unmount())
    scopes.splice(0).forEach(scope => scope.stop())
    vi.useRealTimers()
  })

  it('loads once and uses the server total instead of the current row count', async () => {
    const pending = deferred()

    api.mockReturnValue(pending.promise)

    const { list } = createList()

    expect(list.isLoading.value).toBe(true)
    expect(api).toHaveBeenCalledTimes(1)
    expect(api.mock.calls[0]).toMatchObject(['/admin/ai/writing-profiles', { query: { page: 1, per_page: 15 }, retry: 0 }])
    pending.resolve(response([profile()], 80))
    await flushPromises()
    expect(list.items.value).toEqual([profile()])
    expect(list.totalItems.value).toBe(80)
    expect(list.isLoading.value).toBe(false)
    expect(list.error.value).toBe('')
  })

  it('changes page and resets page on page-size changes with one GET per query', async () => {
    api.mockResolvedValue(response([profile()], 80))

    const { list } = createList()

    await flushPromises()
    list.page.value = 3
    await nextTick()
    await flushPromises()
    expect(api.mock.calls[1][1].query).toEqual({ page: 3, per_page: 15, search: undefined })
    list.itemsPerPage.value = 25
    await nextTick()
    await flushPromises()
    expect(list.page.value).toBe(1)
    expect(api).toHaveBeenCalledTimes(3)
    expect(api.mock.calls[2][1].query).toEqual({ page: 1, per_page: 25, search: undefined })
  })

  it('debounces trimmed search and clear, resetting the server page', async () => {
    vi.useFakeTimers()
    api.mockResolvedValue(response([profile()], 80))

    const { list } = createList()

    await flushPromises()
    list.page.value = 3
    await nextTick()
    await flushPromises()
    list.search.value = 'Rõ'
    await nextTick()
    list.search.value = '  Rõ ràng  '
    await nextTick()
    await vi.advanceTimersByTimeAsync(299)
    expect(api).toHaveBeenCalledTimes(2)
    await vi.advanceTimersByTimeAsync(1)
    await flushPromises()
    expect(api).toHaveBeenCalledTimes(3)
    expect(api.mock.calls[2][1].query).toEqual({ page: 1, per_page: 15, search: 'Rõ ràng' })
    list.search.value = null
    await nextTick()
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    expect(api).toHaveBeenCalledTimes(4)
    expect(api.mock.calls[3][1].query).toEqual({ page: 1, per_page: 15, search: undefined })
  })

  it.each(['resolve', 'reject'])('ignores an older request that arrives late via %s', async action => {
    const old = deferred()
    const current = deferred()

    api.mockReturnValueOnce(old.promise).mockReturnValueOnce(current.promise)

    const { list } = createList()
    const oldSignal = api.mock.calls[0][1].signal

    list.page.value = 2
    await nextTick()
    expect(oldSignal.aborted).toBe(true)
    current.resolve(response([profile({ name: 'Trang mới' })], 80))
    await flushPromises()
    old[action](action === 'resolve' ? response([profile({ name: 'Trang cũ' })], 130) : new Error('Lỗi cũ'))
    await flushPromises()
    expect(list.items.value[0].name).toBe('Trang mới')
    expect(list.totalItems.value).toBe(80)
    expect(list.error.value).toBe('')
    expect(list.isLoading.value).toBe(false)
  })

  it('clears stale rows on failure and recovers only when explicitly reloaded', async () => {
    api.mockResolvedValueOnce(response([profile()], 80)).mockRejectedValueOnce({ data: { message: 'Không thể tải văn phong.' } })

    const { list } = createList()

    await flushPromises()
    expect(await list.load()).toBe(false)
    expect(list.items.value).toEqual([])
    expect(list.totalItems.value).toBe(0)
    expect(list.error.value).toBe('Không thể tải văn phong.')
    expect(list.isLoading.value).toBe(false)
    expect(api).toHaveBeenCalledTimes(2)
    api.mockResolvedValue(response())
    expect(await list.load()).toBe(true)
    expect(list.items.value[0].name).toBe('Rõ ràng')
    expect(list.error.value).toBe('')
  })

  it('returns to the last available page when the database list shrinks', async () => {
    api.mockResolvedValueOnce(response([profile()], 130)).mockResolvedValueOnce(response([], 1)).mockResolvedValueOnce(response())

    const { list } = createList()

    await flushPromises()
    list.page.value = 9
    await nextTick()
    await flushPromises()
    expect(list.page.value).toBe(1)
    expect(api.mock.calls.map(([, options]) => options.query.page)).toEqual([1, 9, 1])
    expect(list.items.value[0].name).toBe('Rõ ràng')
  })

  it('aborts the request and pending search on scope disposal', async () => {
    vi.useFakeTimers()

    const pending = deferred()

    api.mockReturnValue(pending.promise)

    const { list, scope } = createList()
    const signal = api.mock.calls[0][1].signal

    list.search.value = 'Chưa gửi'
    await nextTick()
    scope.stop()
    expect(signal.aborted).toBe(true)
    pending.resolve(response())
    await flushPromises()
    await vi.advanceTimersByTimeAsync(1000)
    expect(list.items.value).toEqual([])
    expect(api).toHaveBeenCalledTimes(1)
  })

  it('shows saved profile metadata and expands escaped prompt text with a link to Add', async () => {
    const prompt = '<img src=x onerror="alert(1)">\nViết rõ ràng, đưa ví dụ.'

    api.mockResolvedValue(response([profile({ style_instructions: prompt }), profile({ id: 8, name: 'Kể chuyện', origin: 'manual', is_enabled: false })], 80))

    const { wrapper, router } = await renderList()

    expect(wrapper.text()).toContain('Rõ ràng')
    expect(wrapper.text()).toContain('Kể chuyện')
    expect(wrapper.text()).toContain('Phân tích AI')
    expect(wrapper.text()).toContain('Nhập thủ công')
    expect(wrapper.text()).toContain('Đang bật')
    expect(wrapper.text()).toContain('Đã tắt')
    expect(wrapper.text()).toContain('v2')
    expect(wrapper.findComponent(VDataTableServer).props('itemsLength')).toBe(80)
    expect(wrapper.get('.v-data-table-footer__info').text()).toBe('1-15 of 80')
    expect(wrapper.findAll('thead th').every(cell => !cell.classes().includes('v-data-table__th--sortable'))).toBe(true)
    await wrapper.get('button[aria-label="Xem prompt Rõ ràng"]').trigger('click')
    expect(wrapper.get('.ai-prompt-table__prompt').text()).toBe(prompt)
    expect(wrapper.find('.ai-prompt-table__prompt img').exists()).toBe(false)

    const add = wrapper.findAll('a').find(link => link.text() === 'Thêm văn phong')
    const navigation = vi.spyOn(router, 'push')

    expect(add.attributes('href')).toBe('/ai/prompt/add')
    await add.trigger('click', { button: 0 })
    expect(navigation).toHaveBeenCalledWith({ name: 'ai-prompt-add' })
    await navigation.mock.results[0].value
    expect(router.currentRoute.value.name).toBe('ai-prompt-add')
    expect(api.mock.calls.every(([, options]) => !options.method || options.method === 'GET')).toBe(true)
  })

  it('loads another page through the actual datatable footer', async () => {
    api.mockResolvedValueOnce(response([profile()], 80)).mockResolvedValueOnce(response([profile({ name: 'Trang hai' })], 80))

    const { wrapper } = await renderList()

    await wrapper.get('button[aria-label="Next page"]').trigger('click')
    await flushPromises()
    expect(api.mock.calls.at(-1)[1].query.page).toBe(2)
    expect(api).toHaveBeenCalledTimes(2)
    expect(wrapper.text()).toContain('Trang hai')
  })

  it('shows an API failure separately from the empty state and can retry', async () => {
    api.mockRejectedValueOnce({ data: { message: 'Danh sách tạm thời không tải được.' } }).mockResolvedValueOnce(response([]))

    const { wrapper } = await renderList()

    expect(wrapper.get('[role="alert"]').text()).toBe('Danh sách tạm thời không tải được.')
    expect(wrapper.text()).not.toContain('Chưa có văn phong đã lưu.')
    await wrapper.findAll('button').find(button => button.text() === 'Tải lại').trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Chưa có văn phong đã lưu.')
    expect(api).toHaveBeenCalledTimes(2)
  })
})
