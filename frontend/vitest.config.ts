import { resolve } from 'node:path'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  test: {
    // 'nuxt' environment throws in this setup; happy-dom + #app shim is enough for unit tests.
    environment: 'happy-dom',
    globals: true,
    include: ['**/*.spec.ts'],
    exclude: ['**/node_modules/**', '.output/**', '.nuxt/**'],
  },
  resolve: {
    alias: {
      '~': resolve(__dirname),
      '@': resolve(__dirname),
      '#app': resolve(__dirname, 'tests/mocks/app.ts'),
    },
  },
})
