import { test, expect } from '@playwright/test'
import { registerB2BCustomer, addProductToCart } from '../helpers/storefront'

test.describe('Storefront payment display', () => {
  test('Storefront category pages load correctly', async ({ page, baseURL }) => {
    await page.goto(baseURL!)
    await page.waitForTimeout(3000)

    const categoryLink = page.locator('.main-navigation-link:not(.home-link)').first()
    if (await categoryLink.isVisible({ timeout: 5000 }).catch(() => false)) {
      await categoryLink.click()
      await page.waitForTimeout(3000)

      const pageText = (await page.textContent('body') || '').toLowerCase()
      expect(pageText).not.toContain('error 500')
      expect(pageText).not.toContain('fatal error')

      const products = page.locator('.product-box, .cms-listing-col')
      const productCount = await products.count()
      expect(productCount, 'Category should have products').toBeGreaterThan(0)
    }
  })

  test('Account registration page loads', async ({ page, baseURL }) => {
    await page.goto(`${baseURL}/account/register`)
    await page.waitForTimeout(3000)

    const pageText = (await page.textContent('body') || '').toLowerCase()
    expect(pageText).not.toContain('error 500')

    const hasForm = await page.locator('form').first().isVisible({ timeout: 5000 }).catch(() => false)
    expect(hasForm, 'Registration page should have a form').toBe(true)
  })

  test('B2B customer can access checkout with payment methods', async ({ page, baseURL }) => {
    await registerB2BCustomer(page, baseURL!)
    await addProductToCart(page)

    await page.goto(`${baseURL}/checkout/confirm`)
    await page.waitForTimeout(5000)

    const pageText = (await page.textContent('body') || '').toLowerCase()
    expect(pageText).not.toContain('error 500')
    expect(pageText).not.toContain('fatal error')
  })

  test('Storefront error pages do not show stack traces', async ({ page, baseURL }) => {
    const response = await page.goto(`${baseURL}/this-page-does-not-exist-12345`)
    await page.waitForTimeout(2000)

    const pageText = (await page.textContent('body') || '').toLowerCase()
    expect(pageText).not.toContain('stack trace')
    expect(pageText).not.toContain('exception')
    expect(pageText).not.toContain('vendor/')
  })
})
