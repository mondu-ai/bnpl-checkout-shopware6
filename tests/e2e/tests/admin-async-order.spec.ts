import { test, expect, request as playwrightRequest, type Page } from '@playwright/test'
import { createHmac, randomUUID } from 'node:crypto'
import { SHOP_URL, getAdminToken, adminHeaders, adminLogin } from './helpers'

/**
 * PT-5129: orders the merchant creates in the admin go to Mondu through the async API.
 *
 * The admin flow is driven through the UI, and Mondu's sandbox answers with real webhooks:
 * the buyer email picks the outcome (ac.good.* authorized, pending.good.* pending,
 * declined@example.com declined). The buyer's confirmation by email cannot happen in the
 * sandbox, so order/confirmed is sent as a signed webhook, the same way the Magento
 * module's integration tests do it. The webhooks must reach the shop.
 */

const PRODUCT_NUMBER = 'SWDEMO10006'
const MONDU_API = 'https://api.demo.mondu.ai/api/v1'

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

async function setConfig(a: Api, values: Record<string, unknown>): Promise<void> {
  const res = await a.ctx.post(`${SHOP_URL}/api/_action/system-config/batch`, {
    headers: adminHeaders(a.token),
    data: { null: values },
  })
  expect(res.status()).toBe(204)
  // Shopware may invalidate the cached config only later (delayed invalidation); clearing the
  // cache makes the change apply right away, like "Clear caches" in the admin.
  await a.ctx.delete(`${SHOP_URL}/api/_action/cache`, { headers: adminHeaders(a.token) })
}

async function systemConfig(a: Api, domain: string): Promise<Record<string, any>> {
  const res = await a.ctx.get(`${SHOP_URL}/api/_action/system-config?domain=${domain}`, { headers: adminHeaders(a.token) })
  return res.json()
}

/** A business customer bound to the storefront sales channel, so the admin needs no channel choice. */
async function createCustomer(a: Api, email: string): Promise<{ id: string; salesChannelId: string; customerNumber: string }> {
  const [country] = await search(a, 'country', { filter: [{ type: 'equals', field: 'iso', value: 'DE' }], limit: 1 })
  const [salutation] = await search(a, 'salutation', { filter: [{ type: 'equals', field: 'salutationKey', value: 'mr' }], limit: 1 })
  const [group] = await search(a, 'customer-group', { limit: 1 })
  const [salesChannel] = await search(a, 'sales-channel', {
    filter: [{ type: 'equals', field: 'typeId', value: '8a243080f92e4c719546314b577cf82b' }],
    limit: 1,
  })

  const id = randomUUID().replace(/-/g, '')
  const addressId = randomUUID().replace(/-/g, '')
  const customerNumber = `E2E-${Date.now()}`

  // Shopware 6.6 still requires a default payment method on the customer; 6.7 dropped the field.
  const version = (await (await a.ctx.get(`${SHOP_URL}/api/_info/version`, { headers: adminHeaders(a.token) })).json()).version as string
  const defaults: Record<string, string> = {}
  if (version.startsWith('6.6')) {
    const [prepayment] = await search(a, 'payment-method', {
      filter: [{ type: 'contains', field: 'handlerIdentifier', value: 'PrePayment' }],
      limit: 1,
    })
    defaults.defaultPaymentMethodId = prepayment.id
  }
  const res = await a.ctx.post(`${SHOP_URL}/api/customer`, {
    headers: adminHeaders(a.token),
    data: {
      id,
      email,
      firstName: 'Test',
      lastName: 'Mondu',
      company: 'Mondu GmbH',
      accountType: 'business',
      salutationId: salutation.id,
      groupId: group.id,
      salesChannelId: salesChannel.id,
      boundSalesChannelId: salesChannel.id,
      customerNumber,
      ...defaults,
      defaultBillingAddressId: addressId,
      defaultShippingAddressId: addressId,
      addresses: [{
        id: addressId,
        firstName: 'Test',
        lastName: 'Mondu',
        company: 'Mondu GmbH',
        street: 'Charlottenstraße 68',
        zipcode: '10117',
        city: 'Berlin',
        countryId: country.id,
        salutationId: salutation.id,
        phoneNumber: '+4930123456',
      }],
    },
  })
  expect(res.status(), await res.text()).toBe(204)

  return { id, salesChannelId: salesChannel.id, customerNumber }
}

async function findOrder(a: Api, email: string): Promise<any | undefined> {
  const [order] = await search(a, 'order', {
    filter: [{ type: 'equals', field: 'orderCustomer.email', value: email }],
    associations: { transactions: { associations: { stateMachineState: {} } }, stateMachineState: {} },
    limit: 1,
  })
  return order
}

async function monduData(a: Api, orderId: string): Promise<any> {
  const [data] = await search(a, 'mondu-order-data', { filter: [{ type: 'equals', field: 'orderId', value: orderId }], limit: 1 })
  return data
}

async function orderStates(a: Api, orderId: string): Promise<{ order: string; transaction: string; mondu: string }> {
  const [order] = await search(a, 'order', {
    ids: [orderId],
    associations: { transactions: { associations: { stateMachineState: {} } }, stateMachineState: {} },
  })
  const data = await monduData(a, orderId)
  return {
    order: order.stateMachineState.technicalName,
    transaction: order.transactions[0].stateMachineState.technicalName,
    mondu: data.orderState,
  }
}

async function sendWebhook(a: Api, topic: string, orderNumber: string, uuid: string): Promise<any> {
  const secret = (await systemConfig(a, 'Mond1SW6.customConfig'))['Mond1SW6.customConfig.webhooksSecret']
  const body = JSON.stringify({
    topic,
    order_uuid: uuid,
    external_reference_id: orderNumber,
    order_state: topic.split('/')[1],
    bank_account: { iban: 'DE02120300000000202051' },
  })
  const res = await a.ctx.post(`${SHOP_URL}/mondu/webhooks`, {
    headers: { 'Content-Type': 'application/json', 'X-Mondu-Signature': createHmac('sha256', secret).update(body).digest('hex') },
    data: body,
  })
  expect(res.status(), await res.text()).toBe(200)
  return res.json()
}

async function getMonduOrder(a: Api, uuid: string): Promise<any> {
  const apiToken = (await systemConfig(a, 'Mond1SW6.config'))['Mond1SW6.config.apiToken']
  const res = await a.ctx.get(`${MONDU_API}/orders/${uuid}`, { headers: { 'Api-Token': apiToken } })
  return (await res.json()).order
}

/** Opens the order-create page for the customer with the payment method chosen in the start dialog. */
async function startAdminOrder(page: Page, customerNumber: string, paymentMethod: RegExp | null): Promise<string[]> {
  await page.goto(`${SHOP_URL}/admin#/sw/order/create/initial`)
  const modal = page.locator('.sw-order-create-initial-modal')
  await expect(modal).toBeVisible({ timeout: 30_000 })

  await modal.locator('input[type="text"]:visible').first().fill(customerNumber)
  const row = modal.locator('tbody .sw-data-grid__row', { hasText: customerNumber })
  await expect(row).toBeVisible({ timeout: 20_000 })
  // The grid re-renders while it searches, so the first click can land on a row that is replaced.
  await expect(async () => {
    await row.locator('input[type="radio"]').check({ force: true, timeout: 3_000 })
  }).toPass({ timeout: 20_000 })

  const optionsTab = modal.locator('.sw-tabs-item', { hasText: /Optionen|Options/ })
  await expect(optionsTab).not.toHaveClass(/is--disabled/, { timeout: 20_000 })
  // The tab asks which Mondu methods to hide; the payment list is filtered once that is known.
  const hiddenMethodsLoaded = page.waitForResponse(response => response.url().includes('/api/mondu/async-order/config'))
  await optionsTab.click()
  await hiddenMethodsLoaded

  const paymentSelect = modal.locator('.sw-order-create-options__payment-method')
  await paymentSelect.click()
  // 6.6 puts the current choice into the search field and lists only what matches it.
  await paymentSelect.locator('input').first().fill('')
  // The list first shows the current choice only and loads the rest a moment later.
  const results = page.locator('.sw-select-result__result-item-text')
  // Settled once two readings a second apart agree. The list may be empty: a sales channel
  // that offers Mondu methods only has nothing left once they are hidden.
  let previous = -1
  let readings = 0
  await expect.poll(async () => {
    const count = await results.count()
    const settled = readings > 1 && count === previous
    previous = count
    readings += 1
    return settled
  }, { timeout: 20_000, intervals: [1_000] }).toBe(true)
  const offered = (await results.allInnerTexts()).map(text => text.trim())

  if (paymentMethod) {
    await page.locator('.sw-select-result', { hasText: paymentMethod }).first().click()
  } else {
    await page.keyboard.press('Escape')
  }

  await modal.locator('button', { hasText: /Vorschau|Preview/ }).click()
  await page.waitForURL(/sw\/order\/create\/general/, { timeout: 30_000 })

  return offered
}

/** Adds the product the way the line item grid of the order-create page does. */
async function addProduct(page: Page, productId: string, quantity = 1): Promise<void> {
  // The order-create store is a Pinia store in 6.7 and a Vuex module in 6.6.
  const orderState = `(() => {
    const sw = window.Shopware
    try { return sw.Store.get('swOrder') } catch (e) { return sw.State.get('swOrder') }
  })()`

  await expect.poll(() => page.evaluate(`(() => {
    const store = ${orderState}
    return !!(store && store.cart && store.cart.token && store.context && store.context.salesChannel && store.context.salesChannel.id)
  })()`), { timeout: 20_000 }).toBe(true)

  await page.evaluate(`(async () => {
    const sw = window.Shopware
    const store = ${orderState}
    const payload = {
      salesChannelId: store.context.salesChannel.id,
      contextToken: store.cart.token,
      items: [{ identifier: '${productId}', type: 'product', quantity: ${quantity}, label: '' }],
    }
    if (typeof store.saveMultipleLineItems === 'function') {
      await store.saveMultipleLineItems(payload)
    } else {
      await sw.State.dispatch('swOrder/saveMultipleLineItems', payload)
    }
  })()`)

  await expect.poll(() => page.evaluate(`(${orderState}).cart.lineItems.length`), { timeout: 20_000 })
    .toBeGreaterThan(0)
}

/** What a select shows: its text, or the value/placeholder of its input (the 6.7 select). */
async function shownValue(field: ReturnType<Page['locator']>): Promise<string> {
  return field.evaluate((element) => {
    const inputs = [...element.querySelectorAll('input')].map(input => `${input.value} ${input.placeholder}`)
    return `${(element as HTMLElement).innerText} ${inputs.join(' ')}`
  })
}

async function productId(a: Api): Promise<string> {
  const [product] = await search(a, 'product', { filter: [{ type: 'equals', field: 'productNumber', value: PRODUCT_NUMBER }], limit: 1 })
  return product.id
}

// Payment method names in either admin language (the plugin installs DE and EN names).
const INVOICE = /Rechnungskauf|Business net|Invoice/i
const SEPA = /SEPA/i
const PAY_NOW = /Sofort|Instant|Pay now/i

/**
 * The plugin's admin bundle has a fixed file name and is served with a one-year max-age, so a
 * CDN in front of the test shop keeps handing out the previous build. Asking for it under a
 * fresh query string always gets the deployed one.
 */
async function bypassCachedPluginBundle(page: Page): Promise<void> {
  await page.route('**/bundles/mond1sw6/administration/**/*.js', route => {
    const url = new URL(route.request().url())
    url.searchParams.set('e2e', String(Date.now()))
    return route.continue({ url: url.toString() })
  })
}

test.describe.serial('PT-5129 — Mondu orders created in the admin (async API)', () => {
  let a: Api
  let product = ''
  let orderId = ''
  let orderNumber = ''
  let uuid = ''

  test.beforeAll(async () => {
    a = await api()
    product = await productId(a)
    // The first-run wizard would cover the page after every reload.
    await setConfig(a, { 'Mond1SW6.config.backendOrdersEnabled': true, 'core.frw.completedAt': new Date().toISOString() })
  })

  test.beforeEach(async ({ page }) => {
    await bypassCachedPluginBundle(page)
    // Shopware's data-sharing consent dialog may pop up over any admin page.
    await page.addLocatorHandler(page.getByRole('button', { name: /Alle ablehnen|Reject all/ }), async (button) => {
      await button.click()
    })
  })

  test.afterAll(async () => {
    await a.ctx.dispose()
  })

  /** Loads the order page afresh: the page still shows the order as it was when saved. */
  async function openOrder(page: Page, id: string): Promise<void> {
    await page.goto(`${SHOP_URL}/admin#/sw/order/detail/${id}/general`)
    await page.reload()
    await expect(page.getByText(/Zahlungsstatus|Payment status/).first()).toBeVisible({ timeout: 30_000 })
  }

  async function saveOrder(page: Page): Promise<void> {
    const placed = page.waitForResponse(response => response.url().includes('/api/_proxy-order/'))
    await page.locator('button', { hasText: /Bestellung speichern|Save order/ }).click()
    const response = await placed
    expect(response.status(), `the order was refused: ${await response.text()}`).toBe(200)
    // No "remind the customer to pay" step: Mondu emails the buyer itself.
    await page.waitForURL(/sw\/order\/detail\//, { timeout: 60_000 })
    await expect(page.locator('.sw-order-create__remind-payment-modal')).toHaveCount(0)
  }

  test('admin creates an invoice order; Mondu authorizes it and it waits for the buyer', async ({ page }) => {
    test.setTimeout(240_000)
    const email = `ac.good.${Date.now()}@example.com`
    const customer = await createCustomer(a, email)

    await adminLogin(page)
    const offered = await startAdminOrder(page, customer.customerNumber, INVOICE)

    expect(offered.some(name => INVOICE.test(name)), `invoice is offered: ${offered.join(', ')}`).toBe(true)
    expect(offered.some(name => PAY_NOW.test(name)), 'pay now is not offered for admin orders').toBe(false)

    await addProduct(page, product)

    const card = page.locator('.mondu-async-order-fields')
    await expect(card).toBeVisible({ timeout: 20_000 })
    // The net term the account holds for DE is preselected.
    await expect.poll(() => shownValue(card.locator('.mondu-async-order-fields__net-term'))).toMatch(/30 (Tage|days)/)
    await expect(card.locator('.mondu-async-order-fields__registration-id')).toBeVisible()
    await expect(card.locator('.mondu-async-order-fields__iban')).toHaveCount(0)

    await saveOrder(page)

    const order = await findOrder(a, email)
    expect(order, 'the order was created').toBeTruthy()
    orderId = order.id
    orderNumber = order.orderNumber

    const data = await monduData(a, orderId)
    expect(data.orderFlow).toBe('async')
    uuid = data.referenceId

    const monduOrder = await getMonduOrder(a, uuid)
    expect(monduOrder.payment_method).toBe('invoice')
    expect(monduOrder.authorized_net_term ?? 30).toBe(30)

    // Mondu's order/authorized webhook moves the payment to "Pending buyer confirmation".
    await expect.poll(async () => (await orderStates(a, orderId)).transaction, { timeout: 60_000 })
      .toBe('mondu_pending_buyer_confirmation')

    await openOrder(page, orderId)
    await expect(page.getByText(/Wartet auf Käuferbestätigung \(Mondu\)|Pending buyer confirmation \(Mondu\)/).first()).toBeVisible({ timeout: 30_000 })
    await expect(page.locator('body')).toContainText(/Mondu-Zahlungsziel|Mondu payment term/)
  })

  test('once the buyer confirms, the order number goes to Mondu and the payment is settled', async () => {
    expect(orderId, 'needs the order of the previous test').toBeTruthy()

    await sendWebhook(a, 'order/confirmed', (await monduData(a, orderId)).externalReferenceId, uuid)

    expect(await orderStates(a, orderId)).toMatchObject({ transaction: 'paid', mondu: 'confirmed' })
    const data = await monduData(a, orderId)
    expect(data.successful).toBe(true)
    expect(data.externalReferenceId).toBe(orderNumber)
    // update_external_info: Mondu now knows the order under its Shopware number.
    expect((await getMonduOrder(a, uuid)).external_reference_id).toBe(orderNumber)
  })

  test('order/authorized leaves orders that were not created in the admin alone', async () => {
    const result = await sendWebhook(a, 'order/authorized', orderNumber, randomUUID())
    expect(result.message).toMatch(/Not an order created in the admin/)
  })

  test('a SEPA order Mondu reviews waits in "Payment review"', async ({ page }) => {
    test.setTimeout(240_000)
    const email = `pending.good.${Date.now()}@example.com`
    const customer = await createCustomer(a, email)

    await adminLogin(page)
    await startAdminOrder(page, customer.customerNumber, SEPA)
    await addProduct(page, product)

    const card = page.locator('.mondu-async-order-fields')
    await expect(card).toBeVisible({ timeout: 20_000 })
    await card.locator('.mondu-async-order-fields__iban input').fill('DE02 1203 0000 0000 2020 51')
    await card.locator('.mondu-async-order-fields__account-holder input').fill('Mondu GmbH')
    await page.waitForTimeout(1_000)

    await saveOrder(page)

    const order = await findOrder(a, email)
    expect((await getMonduOrder(a, (await monduData(a, order.id)).referenceId)).payment_method).toBe('direct_debit')
    await expect.poll(async () => (await orderStates(a, order.id)).transaction, { timeout: 60_000 }).toBe('mondu_payment_review')

    await openOrder(page, order.id)
    await expect(page.getByText(/Zahlungsprüfung \(Mondu\)|Payment review \(Mondu\)/).first()).toBeVisible({ timeout: 30_000 })
  })

  test('an order Mondu declines is cancelled without asking Mondu to cancel it again', async ({ page }) => {
    test.setTimeout(240_000)
    const email = 'declined@example.com'
    const customer = await createCustomer(a, email)

    await adminLogin(page)
    await startAdminOrder(page, customer.customerNumber, INVOICE)
    await addProduct(page, product)
    await expect(page.locator('.mondu-async-order-fields')).toBeVisible({ timeout: 20_000 })

    await saveOrder(page)

    const [order] = await search(a, 'order', {
      filter: [{ type: 'equals', field: 'orderCustomer.customerId', value: customer.id }],
      limit: 1,
    })
    await expect.poll(async () => orderStates(a, order.id), { timeout: 60_000 })
      .toEqual({ order: 'cancelled', transaction: 'failed', mondu: 'declined' })
    expect((await getMonduOrder(a, (await monduData(a, order.id)).referenceId)).state).toBe('declined')
  })

  test('a missing required field stops the order before it reaches Mondu', async ({ page }) => {
    test.setTimeout(180_000)
    const email = `ac.good.${Date.now()}@example.com`
    const customer = await createCustomer(a, email)

    await adminLogin(page)
    await startAdminOrder(page, customer.customerNumber, SEPA)
    await addProduct(page, product)
    await expect(page.locator('.mondu-async-order-fields__iban')).toBeVisible({ timeout: 20_000 })

    await page.locator('button', { hasText: /Bestellung speichern|Save order/ }).click()

    await expect(page.getByText(/please fill the following required fields: IBAN, Account holder/)).toBeVisible({ timeout: 30_000 })
    expect(await findOrder(a, email), 'no order was written').toBeUndefined()
  })

  test('with admin orders switched off the admin offers no Mondu method', async ({ page }) => {
    test.setTimeout(180_000)
    await setConfig(a, { 'Mond1SW6.config.backendOrdersEnabled': false })

    try {
      const email = `ac.good.${Date.now()}@example.com`
      const customer = await createCustomer(a, email)

      await adminLogin(page)
      const offered = await startAdminOrder(page, customer.customerNumber, null)
      expect(offered.some(name => INVOICE.test(name) || SEPA.test(name)), offered.join(', ')).toBe(false)
    } finally {
      await setConfig(a, { 'Mond1SW6.config.backendOrdersEnabled': true })
    }
  })
})
