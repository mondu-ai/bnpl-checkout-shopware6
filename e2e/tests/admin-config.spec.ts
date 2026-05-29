import { test, expect } from '@playwright/test'
import { loginToAdmin, navigateToMonduConfig } from '../helpers/admin'

test.describe('Admin config page', () => {
  test.beforeEach(async ({ page, baseURL }) => {
    await loginToAdmin(page, baseURL!)
  })

  test('Mondu plugin configuration page loads', async ({ page, baseURL }) => {
    await navigateToMonduConfig(page, baseURL!)

    const pageContent = await page.content()
    const hasConfigContent =
      pageContent.includes('Mond1SW6') ||
      pageContent.includes('mondu') ||
      pageContent.includes('Mondu') ||
      pageContent.includes('API') ||
      pageContent.includes('sandbox')

    expect(hasConfigContent, 'Config page should load with Mondu-related content').toBe(true)
  })

  test('Plugin config has input fields', async ({ page, baseURL }) => {
    await navigateToMonduConfig(page, baseURL!)

    const inputs = page.locator('input, select, textarea')
    const inputCount = await inputs.count()
    expect(inputCount, 'Config page should have input fields').toBeGreaterThan(0)
  })
})
