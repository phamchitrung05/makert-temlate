/**
 * =====================================================================
 * CHỨC NĂNG FILE: Kiểm token bị thu hồi sau đổi mật khẩu bằng backend và trình duyệt thật.
 * CÁC HÀM/METHOD TRONG FILE: test() tạo admin riêng, gán role và kiểm yêu cầu đăng nhập lại.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): API SQLite cô lập và Edge/Chromium -> 401, xóa token và trang login.
 * Không thay credential của fixture dùng chung hoặc gọi OAuth/provider thật.
 * =====================================================================
 */
import { expect, test } from '@playwright/test'

test('revokes an admin token after password change and requires login on reload', async ({ page, request }) => {
  const rootLogin = await request.post('/api/admin/login', {
    data: { email: 'browser-admin@example.test', password: 'e2e-password' },
  })

  expect(rootLogin.ok()).toBe(true)

  const { data: root } = await rootLogin.json()

  const rootHeaders = { Authorization: `Bearer ${root.accessToken}` }

  const created = await request.post('/api/admin/access/users', {
    headers: rootHeaders,
    data: {
      name: 'E2E authentication admin', email: 'auth-browser@example.test', status: 'active',
      password: 'BrowserPassword123!', 'password_confirmation': 'BrowserPassword123!',
    },
  })

  expect(created.status()).toBe(201)

  const { data: user } = await created.json()
  const roleResponse = await request.get('/api/admin/roles', { headers: rootHeaders })

  expect(roleResponse.ok()).toBe(true)

  const adminRole = (await roleResponse.json()).data.items.find(role => role.name === 'admin')

  const assigned = await request.patch(`/api/admin/access/users/${user.id}/roles`, {
    headers: rootHeaders, data: { 'role_ids': [adminRole.id], 'expected_version': user.version },
  })

  expect(assigned.ok()).toBe(true)

  const login = await request.post('/api/admin/login', {
    data: { email: user.email, password: 'BrowserPassword123!' },
  })

  expect(login.ok()).toBe(true)

  const { data: session } = await login.json()

  const headers = { Authorization: `Bearer ${session.accessToken}` }

  await page.addInitScript(token => window.sessionStorage.setItem('admin_access_token', token), session.accessToken)
  await page.goto('/admin/marketing/social-post/list')
  await expect(page.getByText('E2E Facebook 12', { exact: true }).first()).toBeVisible()

  const account = await request.get(`/api/admin/access/users/${user.id}`, { headers })

  expect(account.ok()).toBe(true)

  const changed = await request.patch(`/api/admin/access/users/${user.id}`, {
    headers,
    data: {
      'expected_version': (await account.json()).data.account_version,
      password: 'ChangedBrowserPassword123!', 'password_confirmation': 'ChangedBrowserPassword123!',
    },
  })

  expect(changed.ok()).toBe(true)
  expect((await request.get('/api/admin/me', { headers })).status()).toBe(401)

  await page.reload()
  await expect(page).toHaveURL(/\/admin\/login(?:\?|$)/)
  await expect.poll(() => page.evaluate(() => window.sessionStorage.getItem('admin_access_token'))).toBeNull()
})
