import { test, expect } from '@playwright/test'
import { loginToAdmin, getAdminApiToken, navigateToPaymentMethods } from '../helpers/admin'

test.describe('Payment methods registration', () => {
  test('All 5 Mondu payment methods are registered via API', async ({ page, baseURL }) => {
    await loginToAdmin(page, baseURL!)

    const token = await getAdminApiToken(baseURL!, page)

    const response = await page.request.post(`${baseURL}/api/search/payment-method`, {
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
      data: {
        filter: [
          {
            type: 'contains',
            field: 'handlerIdentifier',
            value: 'Mondu',
          },
        ],
      },
    })

    expect(response.ok(), `API response should be OK, got ${response.status()}`).toBe(true)

    const body = await response.json()
    const methods = body.data || []

    expect(methods.length, 'Should have 5 Mondu payment methods').toBe(5)
  })

  test('Mondu payment methods are visible in admin UI', async ({ page, baseURL }) => {
    await loginToAdmin(page, baseURL!)
    await navigateToPaymentMethods(page, baseURL!)

    const pageText = (await page.textContent('body') || '').toLowerCase()
    const hasMondu = pageText.includes('mondu')
    expect(hasMondu, 'Payment methods page should show Mondu methods').toBe(true)
  })
})
