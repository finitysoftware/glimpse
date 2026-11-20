import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5173,
    strictPort: true,
    proxy: { '/api': { target: 'http://127.0.0.1:8080', changeOrigin: false } },
  },
  // The dependency scan does not follow workers. Without the worker as an entry, opening the first file finds new
  // dependencies and reloads the page in the dev server.
  optimizeDeps: { entries: ['index.html', 'src/features/repositories/syntax/highlight-worker.ts'] },
  build: { sourcemap: false },
  test: {
    environment: 'jsdom',
    setupFiles: ['./tests/setup.ts'],
    include: ['tests/unit/**/*.test.{ts,tsx}'],
    restoreMocks: true,
  },
})
