/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm Social Post UI với HTTP Laravel và database thật, không mock API admin.
 * CÁC HÀM/METHOD TRONG FILE: beforeEach() đăng nhập; test() kiểm filter/phân trang và tạo draft.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): thao tác Chromium -> phản hồi HTTP, card và dữ liệu lưu sau reload.
 * =====================================================================
 */
import { expect, test } from '@playwright/test'

test.beforeEach(async ({ page, request }) => {
  const login = await request.post('/api/admin/login', {
    data: { email: 'browser-admin@example.test', password: 'e2e-password' },
  })

  expect(login.ok()).toBe(true)

  const { data } = await login.json()

  await page.addInitScript(token => window.sessionStorage.setItem('admin_access_token', token), data.accessToken)
  await page.goto('/admin/marketing/social-post/list')
  await expect(page.getByText('E2E Facebook 12', { exact: true }).first()).toBeVisible()
})

test('filters Facebook and paginates through the actual backend', async ({ page }) => {
  const pageTwo = page.waitForResponse(response => response.url().includes('/api/admin/social-posts?') && new URL(response.url()).searchParams.get('page') === '2')

  await page.locator('.v-pagination').getByRole('button').filter({ hasText: /^2$/ }).click()

  const secondResponse = await pageTwo

  expect(secondResponse.ok()).toBe(true)
  expect((await secondResponse.json()).data).toHaveLength(4)
  await expect(page.getByText('E2E Facebook 01', { exact: true }).first()).toBeVisible()

  const filtered = page.waitForResponse(response => response.url().includes('/api/admin/social-posts?') && new URL(response.url()).searchParams.get('platform') === 'facebook')

  await page.locator('.platform-filter--facebook').click()

  const filteredResponse = await filtered
  const result = await filteredResponse.json()

  expect(filteredResponse.ok()).toBe(true)
  expect(result.meta.pagination.current_page).toBe(1)
  expect(result.meta.pagination.total).toBe(12)
  expect(result.data).toHaveLength(8)
  await expect(page.getByText('E2E Facebook 12', { exact: true }).first()).toBeVisible()
})

test('creates a draft from the real dialog and keeps it after reloading', async ({ page }) => {
  const caption = 'E2E browser-created Facebook draft'

  await page.getByRole('button', { name: 'Tạo bài viết', exact: true }).click()
  await page.getByLabel('Caption (tuỳ chọn)').fill(caption)
  await page.getByRole('checkbox', { name: 'Xếp hàng tạo caption và ảnh bằng AI' }).uncheck()

  const created = page.waitForResponse(response => response.url().endsWith('/api/admin/social-posts') && response.request().method() === 'POST')

  await page.getByRole('button', { name: 'Tạo draft', exact: true }).click()

  const response = await created
  const result = await response.json()

  expect(response.status()).toBe(201)
  expect(result.data.post.caption).toBe(caption)
  expect(result.data.post.review_status).toBe('draft')
  await expect(page.getByText(caption, { exact: true }).first()).toBeVisible()
  await page.reload()
  await expect(page.getByText(caption, { exact: true }).first()).toBeVisible()

  const filtered = page.waitForResponse(response => response.url().includes('/api/admin/social-posts?') && new URL(response.url()).searchParams.get('platform') === 'facebook')

  await page.locator('.platform-filter--facebook').click()

  const listed = await (await filtered).json()

  expect(listed.data.some(post => post.id === result.data.post.id)).toBe(true)
  await expect(page.getByText(caption, { exact: true }).first()).toBeVisible()
})
