import { test, expect } from '@playwright/test'
import { loginToAdmin, getAdminApiToken } from '../helpers/admin'

test.describe('Admin plugin UI', () => {
  test.beforeEach(async ({ page, baseURL }) => {
    await loginToAdmin(page, baseURL!)
  })

  test('Plugin appears in extension list', async ({ page, baseURL }) => {
    await page.goto(`${baseURL}/admin#/sw/extension/my-extensions/listing`)
    await page.waitForTimeout(8000)

    const pageText = (await page.textContent('body') || '').toLowerCase()
    const hasMondu =
      pageText.includes('mondu') ||
      pageText.includes('mond1sw6') ||
      pageText.includes('rechnungskauf')
    if (!hasMondu) {
      await page.goto(`${baseURL}/admin#/sw/plugin/index/list`)
      await page.waitForTimeout(5000)
      const pluginPageText = (await page.textContent('body') || '').toLowerCase()
      const hasInPluginList =
        pluginPageText.includes('mondu') ||
        pluginPageText.includes('mond1sw6') ||
        pluginPageText.includes('rechnungskauf')
      expect(hasInPluginList, 'Mondu should appear in plugins or extensions list').toBe(true)
    }
  })

  test('Dashboard loads without errors after plugin install', async ({ page, baseURL }) => {
    await page.goto(`${baseURL}/admin#/sw/dashboard/index`)
    await page.waitForTimeout(5000)

    const pageText = (await page.textContent('body') || '').toLowerCase()
    expect(pageText).not.toContain('error 500')
    expect(pageText).not.toContain('fatal error')
  })

  test('Settings page loads without errors', async ({ page, baseURL }) => {
    await page.goto(`${baseURL}/admin#/sw/settings/index/system`)
    await page.waitForTimeout(5000)

    const pageText = (await page.textContent('body') || '').toLowerCase()
    expect(pageText).not.toContain('error 500')
    expect(pageText).not.toContain('fatal error')
  })

  test('Mondu payment methods can be toggled via API', async ({ page, baseURL }) => {
    const token = await getAdminApiToken(baseURL!, page)

    const searchResponse = await page.request.post(`${baseURL}/api/search/payment-method`, {
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
      data: {
        filter: [{ type: 'contains', field: 'handlerIdentifier', value: 'Mondu' }],
        includes: { payment_method: ['id', 'name', 'active'] },
      },
    })

    expect(searchResponse.ok()).toBe(true)
    const body = await searchResponse.json()
    expect(body.data.length).toBeGreaterThan(0)

    const method = body.data[0]
    const patchResponse = await page.request.patch(
      `${baseURL}/api/payment-method/${method.id}`,
      {
        headers: {
          Authorization: `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        data: { active: !method.active },
      }
    )
    expect(patchResponse.ok(), 'Should be able to toggle payment method').toBe(true)

    await page.request.patch(`${baseURL}/api/payment-method/${method.id}`, {
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
      data: { active: method.active },
    })
  })
})
