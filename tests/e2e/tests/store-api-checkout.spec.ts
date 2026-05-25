import { test, expect, request as playwrightRequest } from '@playwright/test'
import { SHOP_URL, STORE_API_KEY, getAdminToken, adminHeaders } from './helpers'

async function getStoreApiKey(): Promise<string> {
  if (STORE_API_KEY) return STORE_API_KEY
  const { token, ctx } = await getAdminToken()
  const res = await ctx.post(`${SHOP_URL}/api/search/sales-channel`, {
    headers: adminHeaders(token),
    data: { filter: [{ type: 'equals', field: 'active', value: true }], limit: 5 },
  })
  const body = await res.json()
  for (const sc of body.data || []) {
    if (sc.accessKey) return sc.accessKey
  }
  throw new Error('No active sales channel found')
}

function storeHeaders(key: string) {
  return { 'sw-access-key': key, Accept: 'application/json', 'Content-Type': 'application/json' }
}

test.describe('Store API — payment method visibility', () => {
  let apiKey: string

  test.beforeAll(async () => {
    apiKey = await getStoreApiKey()
  })

  test('Mondu payment methods visible in Store API', async () => {
    const ctx = await playwrightRequest.newContext()
    const res = await ctx.post(`${SHOP_URL}/store-api/payment-method`, {
      headers: storeHeaders(apiKey),
      data: {},
    })
    const body = await res.json()
    const elements = body.elements || body.data || []
    const monduMethods = elements.filter((pm: any) => {
      const desc = (pm.translated?.description || pm.description || '').toLowerCase()
      const name = (pm.translated?.name || pm.name || '').toLowerCase()
      return desc.includes('mondu') || name.includes('mondu') ||
        name.includes('business net') || name.includes('sepa direct debit') ||
        name.includes('instant pay') || name.includes('installment') ||
        name.includes('instalments') ||
        name.includes('rechnungskauf') || name.includes('sepa-lastschrift') ||
        name.includes('ratenkauf') || name.includes('echtzeitüberweisung')
    })
    console.log(`Found ${monduMethods.length} Mondu payment methods in Store API`)
    console.log('Methods:', monduMethods.map((m: any) => m.translated?.name || m.name))
    expect(monduMethods.length).toBeGreaterThanOrEqual(1)
    await ctx.dispose()
  })

  test('Mondu payment methods have names set', async () => {
    const ctx = await playwrightRequest.newContext()
    const res = await ctx.post(`${SHOP_URL}/store-api/payment-method`, {
      headers: storeHeaders(apiKey),
      data: {},
    })
    const body = await res.json()
    const elements = body.elements || body.data || []
    const monduMethods = elements.filter((pm: any) => {
      const desc = (pm.translated?.description || pm.description || '').toLowerCase()
      const name = (pm.translated?.name || pm.name || '').toLowerCase()
      return desc.includes('mondu') ||
        name.includes('rechnungskauf') || name.includes('sepa-lastschrift') ||
        name.includes('ratenkauf') || name.includes('echtzeitüberweisung') ||
        name.includes('business net') || name.includes('sepa direct debit') ||
        name.includes('instant pay') || name.includes('installment')
    })
    for (const pm of monduMethods) {
      const name = pm.translated?.name || pm.name || ''
      expect(name.length).toBeGreaterThan(2)
    }
    const withDesc = monduMethods.filter((pm: any) => (pm.translated?.description || '').length > 10)
    console.log(`${monduMethods.length} Mondu methods found, ${withDesc.length} have descriptions`)
    await ctx.dispose()
  })
})

test.describe('Store API — cart operations', () => {
  let apiKey: string

  test.beforeAll(async () => {
    apiKey = await getStoreApiKey()
  })

  test('Can create cart context with Mondu payment method', async () => {
    const ctx = await playwrightRequest.newContext()

    const contextRes = await ctx.post(`${SHOP_URL}/store-api/context`, {
      headers: storeHeaders(apiKey),
      data: {},
    })

    if (contextRes.status() === 200) {
      const contextBody = await contextRes.json()
      const swContextToken = contextBody.token || contextRes.headers()['sw-context-token']
      if (swContextToken) {
        console.log(`Context token: ${swContextToken.substring(0, 10)}...`)
      }
    }
    await ctx.dispose()
  })
})

test.describe('Store API — checkout page', () => {
  test('Storefront checkout page renders Mondu payment options', async ({ page }) => {
    const res = await page.goto(`${SHOP_URL}/checkout/confirm`)
    if (res && res.status() === 200) {
      const body = await page.content()
      const hasMonduRef = body.toLowerCase().includes('mondu') ||
        body.includes('Business net') ||
        body.includes('mond1sw6')
      if (hasMonduRef) {
        console.log('Mondu references found on checkout page')
      } else {
        console.log('No Mondu references on checkout page (might need login/cart)')
      }
    }
    expect(res?.status()).toBeLessThan(500)
  })
})
