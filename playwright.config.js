/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấu hình E2E trình duyệt với Laravel và SQLite riêng cho mỗi lần chạy.
 * CÁC HÀM/METHOD TRONG FILE: Không có; cấu hình runner, server và artifact lỗi.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): test browser -> backend thật, trace/screenshot khi lỗi.
 * =====================================================================
 */
import { mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { defineConfig } from '@playwright/test'

process.env.MAKERT_E2E_ROOT ||= mkdtempSync(join(tmpdir(), 'makert-e2e-'))

export default defineConfig({
  testDir: './tests/e2e',
  testMatch: '**/*.spec.js',
  fullyParallel: false,
  workers: 1,
  timeout: 30_000,
  retries: 0,
  reporter: 'list',
  globalTeardown: './tests/e2e/teardown.js',
  use: {
    baseURL: 'http://127.0.0.1:8179',
    browserName: 'chromium',
    channel: process.env.PLAYWRIGHT_CHANNEL || undefined,
    viewport: { width: 1440, height: 1000 },
    serviceWorkers: 'block',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command: 'node tests/e2e/server.js',
    url: 'http://127.0.0.1:8179/up',
    reuseExistingServer: false,
    timeout: 120_000,
  },
})
