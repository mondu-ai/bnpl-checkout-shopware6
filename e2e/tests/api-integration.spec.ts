import { test, expect } from '@playwright/test'
import { SW_ADMIN_USER, SW_ADMIN_PASS } from '../helpers/config'

async function getToken(page: any, baseURL: string): Promise<string> {
  const response = await page.request.post(`${baseURL}/api/oauth/token`, {
    data: {
      grant_type: 'password',
      client_id: 'administration',
      username: SW_ADMIN_USER,
      password: SW_ADMIN_PASS,
    },
  })
  const body = await response.json()
  return body.access_token
}

test.describe('API integration', () => {
  test('Admin API is accessible', async ({ page, baseURL }) => {
    const token = await getToken(page, baseURL!)

    const response = await page.request.get(`${baseURL}/api/_info/config`, {
      headers: { Authorization: `Bearer ${token}` },
    })

    expect(response.ok()).toBe(true)
    const body = await response.json()
    expect(body.version).toBeTruthy()
  })

  test('Payment methods have correct handler identifiers', async ({ page, baseURL }) => {
    const token = await getToken(page, baseURL!)

    const response = await page.request.post(`${baseURL}/api/search/payment-method`, {
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
      data: {
        filter: [{ type: 'contains', field: 'handlerIdentifier', value: 'Mondu' }],
        includes: { payment_method: ['id', 'name', 'handlerIdentifier', 'active'] },
      },
    })

    expect(response.ok()).toBe(true)
    const body = await response.json()
    const methods = body.data || []

    const expectedHandlers = [
      'MonduHandler',
      'MonduInstallmentHandler',
      'MonduInstallmentByInvoiceHandler',
      'MonduSepaHandler',
      'MonduPayNowHandler',
    ]

    for (const handler of expectedHandlers) {
      const found = methods.some((m: any) => {
        const h = m.handlerIdentifier || m.attributes?.handlerIdentifier || ''
        return h.includes(handler)
      })
      expect(found, `Handler ${handler} should be registered`).toBe(true)
    }
  })

  test('Plugin config is accessible via API', async ({ page, baseURL }) => {
    const token = await getToken(page, baseURL!)

    const response = await page.request.post(`${baseURL}/api/search/system-config`, {
      headers: {
        Authorization: `Bearer ${token}`,
        'Content-Type': 'application/json',
      },
      data: {
        filter: [{ type: 'contains', field: 'configurationKey', value: 'Mond1SW6' }],
        limit: 10,
      },
    })

    expect(response.ok()).toBe(true)
  })

  test('Shopware version endpoint works', async ({ page, baseURL }) => {
    const token = await getToken(page, baseURL!)

    const response = await page.request.get(`${baseURL}/api/_info/version`, {
      headers: { Authorization: `Bearer ${token}` },
    })

    expect(response.ok()).toBe(true)
    const body = await response.json()
    expect(body.version).toMatch(/^6\.\d+/)
  })
})
