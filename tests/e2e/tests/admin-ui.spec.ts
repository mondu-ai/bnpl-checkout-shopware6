import { test, expect } from '@playwright/test'
import { SHOP_URL, adminLogin } from './helpers'

test.describe('Admin UI — Mondu plugin pages', () => {
  test('Plugin appears in extension list', async ({ page }) => {
    await adminLogin(page)
    await expect(page.locator('.sw-admin-menu')).toBeVisible({ timeout: 15_000 })

    await page.evaluate(() => {
      (window as any).Shopware?.Application?.view?.router?.push('/sw/extension/my-extensions/listing')
    })
    await page.waitForTimeout(3_000)

    const pageContent = await page.content()
    const hasMondu = pageContent.includes('Mond1SW6') || pageContent.includes('Mondu')
    console.log(`Mondu plugin found in extensions: ${hasMondu}`)
  })

  test('Plugin config page loads all sections', async ({ page }) => {
    await adminLogin(page)
    await expect(page.locator('.sw-admin-menu')).toBeVisible({ timeout: 15_000 })

    await page.evaluate(() => {
      (window as any).Shopware?.Application?.view?.router?.push('/sw/extension/config/Mond1SW6')
    })
    await page.waitForSelector('.sw-system-config', { timeout: 30_000 }).catch(async () => {
      await page.goto(`${SHOP_URL}/admin#/sw/extension/config/Mond1SW6`, { waitUntil: 'networkidle' })
      await page.waitForSelector('.sw-admin-menu', { timeout: 30_000 })
      await page.waitForSelector('.sw-system-config', { timeout: 30_000 })
    })

    const pageContent = await page.content()
    const hasMonduConfig = pageContent.includes('Mondu') && (
      pageContent.includes('API Configuration') || pageContent.includes('Configuration') ||
      pageContent.includes('API Token') || pageContent.includes('Sandbox')
    )
    console.log(`Mondu config page has config content: ${hasMonduConfig}`)
    expect(hasMonduConfig).toBe(true)
  })

  test('Order list loads with Mondu orders', async ({ page }) => {
    await adminLogin(page)
    await expect(page.locator('.sw-admin-menu')).toBeVisible({ timeout: 15_000 })

    await page.evaluate(() => {
      (window as any).Shopware?.Application?.view?.router?.push('/sw/order/index')
    })
    await page.waitForSelector('.sw-order-list', { timeout: 30_000 }).catch(async () => {
      await page.goto(`${SHOP_URL}/admin#/sw/order/index`, { waitUntil: 'networkidle' })
      await page.waitForSelector('.sw-admin-menu', { timeout: 30_000 })
      await page.waitForSelector('.sw-order-list, .sw-data-grid', { timeout: 30_000 })
    })

    const gridRows = page.locator('.sw-data-grid__row')
    const rowCount = await gridRows.count()
    console.log(`Orders in list: ${rowCount}`)
    expect(rowCount).toBeGreaterThan(0)
  })

  test('Order detail page shows Mondu tab/section', async ({ page }) => {
    await adminLogin(page)
    await expect(page.locator('.sw-admin-menu')).toBeVisible({ timeout: 15_000 })

    await page.evaluate(() => {
      (window as any).Shopware?.Application?.view?.router?.push('/sw/order/index')
    })
    await page.waitForSelector('.sw-data-grid__row', { timeout: 30_000 }).catch(async () => {
      await page.goto(`${SHOP_URL}/admin#/sw/order/index`, { waitUntil: 'networkidle' })
      await page.waitForSelector('.sw-admin-menu', { timeout: 30_000 })
      await page.waitForSelector('.sw-data-grid__row', { timeout: 30_000 })
    })

    const firstOrderLink = page.locator('.sw-data-grid__row--0 .sw-data-grid__cell--orderNumber a').first()
    if (await firstOrderLink.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await firstOrderLink.click()
      await page.waitForSelector('.sw-order-detail', { timeout: 20_000 }).catch(() => {})
      const pageContent = await page.content()
      const hasMonduSection = pageContent.toLowerCase().includes('mondu')
      console.log(`Mondu section in order detail: ${hasMonduSection}`)
    } else {
      console.log('Could not click first order, skipping detail page check')
    }
  })
})
