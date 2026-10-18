import { test, expect, request as playwrightRequest, type Page } from '@playwright/test'
import { SHOP_URL, getAdminToken, adminHeaders, registerAndCheckout } from './helpers'

/**
 * PT-5129: the buyer picks the net term in the storefront checkout.
 *
 * The merchant enables terms per payment method in the plugin settings, and the checkout
 * offers those the Mondu account holds for the buyer's country (GET /payment_terms with
 * source=widget and the payment method). Several terms give a select, a single one is shown
 * as text, none shows nothing. The term goes to Mondu with the order and is checked there.
 * The payment method name carries the terms on offer, and once the order is placed its term.
 * Runs against the sandbox account, which holds 14, 30, 45, 60 and 90 days for invoice in DE
 * and no 7 days.
 */

const MONDU_API = 'https://api.demo.mondu.ai/api/v1'
const CONFIG_KEYS = [
  'Mond1SW6.config.availableNetTermsInvoice',
  'Mond1SW6.config.availableNetTermsDirectDebit',
  'Mond1SW6.config.availableNetTermsPayNow',
]

type Api = { ctx: any; token: string }

async function api(): Promise<Api> {
  const ctx = await playwrightRequest.newContext({ ignoreHTTPSErrors: true })
  const { token } = await getAdminToken(ctx)
  return { ctx, token }
}

async function search(a: Api, entity: string, body: object): Promise<any[]> {
  const res = await a.ctx.post(`${SHOP_URL}/api/search/${entity}`, { headers: adminHeaders(a.token), data: body })
  return (await res.json()).data || []
}

async function systemConfig(a: Api, domain: string): Promise<Record<string, any>> {
  const res = await a.ctx.get(`${SHOP_URL}/api/_action/system-config?domain=${domain}`, { headers: adminHeaders(a.token) })
  return res.json()
}

async function setConfig(a: Api, values: Record<string, unknown>): Promise<void> {
  const res = await a.ctx.post(`${SHOP_URL}/api/_action/system-config/batch`, {
    headers: adminHeaders(a.token),
    data: { null: values },
  })
  expect(res.status()).toBe(204)
  await a.ctx.delete(`${SHOP_URL}/api/_action/cache`, { headers: adminHeaders(a.token) })
}

async function enableInvoiceTerms(a: Api, netTerms: number[]): Promise<void> {
  await setConfig(a, { 'Mond1SW6.config.availableNetTermsInvoice': netTerms })
}

/** The Mondu order behind a storefront order, read from the sandbox. */
async function monduOrderOf(a: Api, email: string): Promise<{ order: any; duration: number }> {
  const [order] = await search(a, 'order', {
    filter: [{ type: 'equals', field: 'orderCustomer.email', value: email }],
    limit: 1,
  })
  expect(order, `the storefront order of ${email} was placed`).toBeTruthy()
  const [data] = await search(a, 'mondu-order-data', { filter: [{ type: 'equals', field: 'orderId', value: order.id }], limit: 1 })
  const apiToken = (await systemConfig(a, 'Mond1SW6.config'))['Mond1SW6.config.apiToken']
  const res = await a.ctx.get(`${MONDU_API}/orders/${data.referenceId}`, { headers: { 'Api-Token': apiToken } })
  return { order: (await res.json()).order, duration: data.duration }
}

const INVOICE = /Rechnungskauf|Business net|Invoice/i

test.describe.serial('Storefront net term', () => {
  let a: Api
  let saved: Record<string, unknown> = {}

  test.beforeAll(async () => {
    a = await api()
    const config = await systemConfig(a, 'Mond1SW6.config')
    saved = Object.fromEntries(CONFIG_KEYS.map(key => [key, config[key] ?? null]))
  })

  test.afterAll(async () => {
    await setConfig(a, saved)
    await a.ctx.dispose()
  })

  test('the buyer picks one of several terms and Mondu gets that term', async ({ page }) => {
    test.setTimeout(240_000)
    // 7 days is enabled but the account does not hold it, so it is not offered.
    await enableInvoiceTerms(a, [7, 30, 60])
    const buyer = `ac.good.${Date.now()}@example.com`

    await registerAndCheckout(page, buyer, INVOICE, 'DE', async (checkout: Page) => {
      const select = checkout.locator('select[name="monduNetTerm"]')
      await expect(select).toBeVisible()
      await expect(select.locator('option')).toHaveText([/30/, /60/])
      await expect(select).toHaveValue('30')
      await expect(checkout.locator('.payment-method-description strong', { hasText: 'Rechnungskauf' })).toHaveText('Rechnungskauf (30/60 Tage)')
      await select.selectOption('60')
    })

    // The finish page and the order history name the term the order was placed with.
    await expect(page.getByText('Rechnungskauf (60 Tage)').first()).toBeVisible()
    await page.goto(`${SHOP_URL}/account/order`)
    await expect(page.getByText('Rechnungskauf (60 Tage)').first()).toBeVisible()

    const { order, duration } = await monduOrderOf(a, buyer)
    expect(order.authorized_net_term).toBe(60)
    expect(duration).toBe(60)
  })

  test('a single term is shown as text and sent', async ({ page }) => {
    test.setTimeout(240_000)
    await enableInvoiceTerms(a, [45])
    const buyer = `ac.good.${Date.now()}@example.com`

    await registerAndCheckout(page, buyer, INVOICE, 'DE', async (checkout: Page) => {
      await expect(checkout.locator('select[name="monduNetTerm"]')).toHaveCount(0)
      await expect(checkout.locator('.mondu-net-term-single')).toHaveText(/Zahlungsziel: 45 Tage|Payment term: 45 days/)
      await expect(checkout.locator('.payment-method-description strong', { hasText: 'Rechnungskauf' })).toHaveText('Rechnungskauf (45 Tage)')
    })

    expect((await monduOrderOf(a, buyer)).order.authorized_net_term).toBe(45)
  })

  test('without enabled terms the checkout shows none and Mondu applies the default', async ({ page }) => {
    test.setTimeout(240_000)
    await enableInvoiceTerms(a, [])
    const buyer = `ac.good.${Date.now()}@example.com`

    await registerAndCheckout(page, buyer, INVOICE, 'DE', async (checkout: Page) => {
      await expect(checkout.locator('.mondu-net-term')).toHaveCount(0)
      await expect(checkout.locator('.payment-method-description strong', { hasText: 'Rechnungskauf' })).toHaveText('Rechnungskauf (30 Tage)')
    })

    expect((await monduOrderOf(a, buyer)).order.authorized_net_term).toBe(30)
  })
})
