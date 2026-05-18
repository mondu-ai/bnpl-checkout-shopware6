import { defineConfig } from '@playwright/test'

const SHOP_URL = process.env.SHOP_URL || 'https://sw66-ivan-local.casa-kuhl.de'

export default defineConfig({
  testDir: './tests',
  timeout: 120_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  retries: 0,
  workers: 1,
  reporter: [['html', { open: 'never' }], ['list']],
  use: {
    baseURL: SHOP_URL,
    ignoreHTTPSErrors: true,
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    trace: 'retain-on-failure',
    locale: 'de-DE',
  },
})
