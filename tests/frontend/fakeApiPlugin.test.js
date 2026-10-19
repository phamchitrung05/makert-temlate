/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm fake API chỉ tải runtime khi được bật.
 * CÁC HÀM/METHOD TRONG FILE: các case Vitest cho cấu hình MSW và URL triển khai.
 * INPUT/OUTPUT CỦA FILE (tổng thể): env giả lập -> số lần import/start worker.
 * =====================================================================
 */
import { afterEach, beforeEach, expect, it, vi } from 'vitest'

const { loaded, start } = vi.hoisted(() => ({ loaded: vi.fn(), start: vi.fn() }))

vi.mock('@/plugins/fake-api/runtime', () => {
  loaded()

  return { startFakeApi: start }
})

beforeEach(() => {
  vi.resetModules()
  loaded.mockClear()
  start.mockReset().mockResolvedValue('worker ready')
})
afterEach(() => vi.unstubAllEnvs())

it('does not load MSW or register a worker when fake API is disabled', async () => {
  vi.stubEnv('VITE_ENABLE_MSW', 'false')

  const { default: plugin } = await import('@/plugins/fake-api/index')

  await plugin()
  expect(loaded).not.toHaveBeenCalled()
  expect(start).not.toHaveBeenCalled()
})

it.each([
  ['/build/', '/mockServiceWorker.js'],
  ['/admin/build/', '/admin/mockServiceWorker.js'],
])('starts fake API with deployment base %s', async (base, expectedUrl) => {
  vi.stubEnv('VITE_ENABLE_MSW', 'true')
  vi.stubEnv('BASE_URL', base)

  const { default: plugin } = await import('@/plugins/fake-api/index')

  await expect(plugin()).resolves.toBe('worker ready')
  expect(start).toHaveBeenCalledOnce()
  expect(start).toHaveBeenCalledWith(expectedUrl)
})
