/**
 * =====================================================================
 * CHỨC NĂNG FILE: Cấu hình test Vue với DOM giả lập và component Vuetify thật.
 * CÁC HÀM/METHOD TRONG FILE: Không có; cấu hình test runner.
 * INPUT/OUTPUT CỦA CLASS (tổng thể): mã nguồn/test -> aliases, compiler Vue và môi trường test.
 * =====================================================================
 */
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
      '@db': fileURLToPath(new URL('./resources/js/plugins/fake-api/handlers', import.meta.url)),
    },
  },
  test: {
    server: { deps: { inline: ['vuetify'] } },
    environment: 'happy-dom',
    setupFiles: ['./tests/frontend/setup.js'],
    include: ['./tests/frontend/**/*.test.js'],
    clearMocks: true,
    restoreMocks: true,
    unstubGlobals: true,
    reporters: ['default'],
  },
})
