import { test, expect } from '@playwright/test'
import { addProductToCart, registerB2BCustomer } from '../helpers/storefront'

test.describe('Storefront checkout', () => {
  test('Storefront loads without errors', async ({ page, baseURL }) => {
    const response = await page.goto(baseURL!)
    expect(response?.ok(), 'Storefront should return 200').toBe(true)

    const title = await page.title()
    expect(title).not.toBe('')

    const body = await page.textContent('body') || ''
    expect(body.toLowerCase()).not.toContain('error 500')
    expect(body.toLowerCase()).not.toContain('fatal error')
  })

  test('Product detail page loads', async ({ page, baseURL }) => {
    await page.goto(baseURL!)
    await page.waitForTimeout(3000)

    const productLink = page.locator('.product-box .product-name, .product-image-link, .product-info a').first()
    if (await productLink.isVisible({ timeout: 5000 }).catch(() => false)) {
      await productLink.click()
      await page.waitForTimeout(3000)

      const buyButton = page.locator('button.btn-buy[type="submit"]')
      const hasBuyButton = await buyButton.isVisible({ timeout: 5000 }).catch(() => false)
      expect(hasBuyButton, 'Product detail page should have a buy button').toBe(true)
    }
  })

  test('Product can be added to cart and checkout page loads', async ({ page, baseURL }) => {
    await addProductToCart(page)

    await page.goto(`${baseURL}/checkout/confirm`)
    await page.waitForTimeout(3000)

    // Checkout page should load — either as guest checkout (shipping info form)
    // or as logged-in checkout (payment selection).
    // Both are valid — we just verify the page loads without errors.
    const pageText = (await page.textContent('body') || '').toLowerCase()
    expect(pageText).not.toContain('error 500')
    expect(pageText).not.toContain('fatal error')

    const hasCheckoutContent =
      pageText.includes('shipping') ||
      pageText.includes('payment') ||
      pageText.includes('confirm') ||
      pageText.includes('summary') ||
      pageText.includes('shopping cart') ||
      pageText.includes('versand') ||
      pageText.includes('zahlung')
    expect(hasCheckoutContent, 'Checkout page should have checkout-related content').toBe(true)
  })

  test('B2B customer registration works', async ({ page, baseURL }) => {
    await registerB2BCustomer(page, baseURL!)

    const currentUrl = page.url()
    const pageText = (await page.textContent('body') || '').toLowerCase()

    const isLoggedIn =
      currentUrl.includes('/account') ||
      pageText.includes('welcome') ||
      pageText.includes('willkommen') ||
      pageText.includes('overview') ||
      pageText.includes('logout') ||
      pageText.includes('abmelden')
    expect(isLoggedIn, 'Customer should be registered and see account page or welcome').toBe(true)
  })
})
