import { test, expect } from '@playwright/test'
import { loginToAdmin, navigateToMonduConfig } from '../helpers/admin'

test.describe('Admin snippets', () => {
  test.beforeEach(async ({ page, baseURL }) => {
    await loginToAdmin(page, baseURL!)
  })

  test('Mondu config page renders translated labels (not raw snippet keys)', async ({ page, baseURL }) => {
    await navigateToMonduConfig(page, baseURL!)

    const pageText = await page.textContent('body') || ''

    const hasSnippetKeys = pageText.includes('mond1SW6.config.') ||
      pageText.includes('mond1-sw6.config.')
    expect(hasSnippetKeys, 'Page should not show raw snippet keys').toBe(false)
  })

  test('Order list page loads without snippet key fallbacks', async ({ page, baseURL }) => {
    await page.goto(`${baseURL}/admin#/sw/order/index`)
    await page.waitForTimeout(5000)

    const pageText = await page.textContent('body') || ''
    const hasMonduSnippetKeys = /mond1SW6\.\w+\.\w+/.test(pageText)
    expect(hasMonduSnippetKeys, 'Order page should not show raw Mondu snippet keys').toBe(false)
  })
})
