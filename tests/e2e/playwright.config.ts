import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './specs',
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: { baseURL: 'http://127.0.0.1:5173', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: [
    { command: 'node serve-backend.mjs', url: 'http://127.0.0.1:8080/api/health', reuseExistingServer: false, timeout: 30000 },
    { command: 'pnpm --dir ../../frontend run dev', url: 'http://127.0.0.1:5173', reuseExistingServer: false, timeout: 30000 },
  ],
})
