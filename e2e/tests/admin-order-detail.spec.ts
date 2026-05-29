import { test, expect } from '@playwright/test'
import { loginToAdmin, navigateToOrders, getAdminApiToken } from '../helpers/admin'

test.describe('Admin order detail', () => {
  test('Order list page loads', async ({ page, baseURL }) => {
    await loginToAdmin(page, baseURL!)
    await navigateToOrders(page, baseURL!)

    const orderGrid = page.locator('.sw-order-list, .sw-data-grid, .sw-page__main-content')
    const hasGrid = await orderGrid.first().isVisible({ timeout: 10_000 }).catch(() => false)
    expect(hasGrid, 'Order list should render a data grid or main content area').toBe(true)
  })

  test('Mondu payment handler is registered in the system', async ({ page, baseURL }) => {
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
        includes: {
          payment_method: ['id', 'name', 'active', 'handlerIdentifier'],
        },
      },
    })

    expect(response.ok()).toBe(true)
    const body = await response.json()
    expect(body.data.length, 'At least one Mondu payment method should exist').toBeGreaterThan(0)
  })
})
