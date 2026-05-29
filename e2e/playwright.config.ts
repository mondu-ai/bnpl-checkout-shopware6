import { defineConfig, devices } from '@playwright/test'
import * as dotenv from 'dotenv'

dotenv.config()

export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  workers: 1,
  reporter: 'html',
  timeout: 120_000,
  expect: { timeout: 15_000 },
  use: {
    actionTimeout: 30_000,
    navigationTimeout: 60_000,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    ignoreHTTPSErrors: true,
    ...devices['Desktop Chrome'],
  },
  projects: [
    {
      name: 'sw66',
      use: {
        baseURL: process.env.SW66_BASE_URL || 'http://sw66.localhost',
      },
    },
    {
      name: 'sw67',
      use: {
        baseURL: process.env.SW67_BASE_URL || 'http://sw67.localhost',
      },
    },
  ],
})
