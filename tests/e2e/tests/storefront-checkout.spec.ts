import { test, expect, request as playwrightRequest } from '@playwright/test'
import { SHOP_URL, getAdminToken, adminHeaders, adminLogin, getMonduPaymentMethodIds } from './helpers'

test.describe('Storefront — Mondu plugin health', () => {
  test('Storefront homepage loads', async ({ page }) => {
    await page.goto(SHOP_URL)
    await expect(page).toHaveTitle(/.+/)
    await expect(page.locator('body')).toBeVisible()
  })

  test('Admin login works', async ({ page }) => {
    await adminLogin(page)
    await expect(page.locator('.sw-admin-menu')).toBeVisible({ timeout: 15_000 })
  })

  test('All 5 Mondu payment methods registered', async () => {
    const { token, ctx } = await getAdminToken()
    const methods = await getMonduPaymentMethodIds(ctx, token)
    console.log('Mondu payment methods:', JSON.stringify(methods))
    expect(Object.keys(methods).length).toBe(5)
    expect(methods['invoice']).toBeTruthy()
    expect(methods['sepa']).toBeTruthy()
    expect(methods['installment']).toBeTruthy()
    expect(methods['installment_by_invoice']).toBeTruthy()
    expect(methods['pay_now']).toBeTruthy()
    await ctx.dispose()
  })

  test('All Mondu payment methods are active', async () => {
    const { token, ctx } = await getAdminToken()
    const res = await ctx.post(`${SHOP_URL}/api/search/payment-method`, {
      headers: adminHeaders(token),
      data: {
        filter: [{ type: 'contains', field: 'handlerIdentifier', value: 'Mondu' }],
        limit: 10,
      },
    })
    const body = await res.json()
    for (const pm of body.data || []) {
      expect(pm.active).toBe(true)
    }
    await ctx.dispose()
  })

  test('Mondu plugin config page loads with sandbox enabled', async ({ page }) => {
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

    const sandboxToggle = page.locator('[name="Mond1SW6.config.sandbox"]')
    if (await sandboxToggle.isVisible({ timeout: 5_000 }).catch(() => false)) {
      const checked = await sandboxToggle.isChecked()
      console.log(`Sandbox mode: ${checked}`)
      expect(checked).toBe(true)
    }
  })
})
