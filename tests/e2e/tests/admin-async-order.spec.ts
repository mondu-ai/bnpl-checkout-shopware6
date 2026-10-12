import { test, expect, request as playwrightRequest, type Page, type Locator } from '@playwright/test'
import { createHmac, randomUUID } from 'node:crypto'
import { SHOP_URL, getAdminToken, adminHeaders, adminLogin } from './helpers'

/**
 * PT-5129: orders the merchant creates in the admin go to Mondu through the async API.
 *
 * The flow runs for real end to end: the order is created through the admin UI, Mondu's
 * sandbox answers with real webhooks (the buyer email picks the outcome: ac.good.* authorized,
 * pending.good.* pending, declined@example.com declined), and the buyer confirms the order on
 * the Mondu portal page the confirmation email links to (the order's confirmation_link), which
 * makes Mondu send order/confirmed. The webhooks must reach the shop.
 */

const PRODUCT_NUMBER = 'SWDEMO10006'
const MONDU_API = 'https://api.demo.mondu.ai/api/v1'
const CUSTOMER_PASSWORD = 'Mondu-E2E-2026!'
// Installments start at a few hundred euros in the sandbox; one product is below that.
const INSTALLMENT_QUANTITY = 30

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

async function storefrontChannel(a: Api): Promise<any> {
  const [salesChannel] = await search(a, 'sales-channel', {
    filter: [{ type: 'equals', field: 'typeId', value: '8a243080f92e4c719546314b577cf82b' }],
    limit: 1,
  })
  return salesChannel
}

/** A business customer bound to the storefront sales channel, so the admin needs no channel choice. */
async function createCustomer(
  a: Api,
  email: string,
  countryIso = 'DE',
): Promise<{ id: string; salesChannelId: string; customerNumber: string }> {
  const [country] = await search(a, 'country', { filter: [{ type: 'equals', field: 'iso', value: countryIso }], limit: 1 })
  const [salutation] = await search(a, 'salutation', { filter: [{ type: 'equals', field: 'salutationKey', value: 'mr' }], limit: 1 })
  const [group] = await search(a, 'customer-group', { limit: 1 })
  const salesChannel = await storefrontChannel(a)

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
  const address = countryIso === 'NL'
    ? { street: 'Keizersgracht 1', zipcode: '1015 CJ', city: 'Amsterdam', phoneNumber: '+31201234567' }
    : { street: 'Charlottenstraße 68', zipcode: '10117', city: 'Berlin', phoneNumber: '+4930123456' }
  const res = await a.ctx.post(`${SHOP_URL}/api/customer`, {
    headers: adminHeaders(a.token),
    data: {
      id,
      email,
      password: CUSTOMER_PASSWORD,
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
        ...address,
        countryId: country.id,
        salutationId: salutation.id,
      }],
    },
  })
  expect(res.status(), await res.text()).toBe(204)

  return { id, salesChannelId: salesChannel.id, customerNumber }
}

async function findOrder(a: Api, email: string): Promise<any | undefined> {
  const [order] = await search(a, 'order', {
    filter: [{ type: 'equals', field: 'orderCustomer.email', value: email }],
    associations: {
      transactions: { associations: { stateMachineState: {} } },
      stateMachineState: {},
      deliveries: {},
      lineItems: {},
    },
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

/**
 * Confirms the order as the buyer: opens the page the confirmation email links to and accepts
 * the order details there. Mondu then sends order/confirmed to the shop.
 */
async function confirmAsBuyer(a: Api, page: Page, uuid: string): Promise<void> {
  const link = (await getMonduOrder(a, uuid)).confirmation_link
  expect(link, 'Mondu offers the buyer a confirmation page').toBeTruthy()

  const portal = await page.context().newPage()
  try {
    const confirmed = portal.waitForResponse(
      response => response.url().includes('/buyers/orders/confirm') && response.request().method() === 'POST',
      { timeout: 60_000 },
    )
    const confirm = portal.getByRole('button', { name: /Bestelldetails bestätigen|Confirm order details/ })
    // The portal is a single-page app on Mondu's side; a load that drops is simply repeated.
    await expect(async () => {
      await portal.goto(link)
      await expect(confirm).toBeVisible({ timeout: 20_000 })
    }).toPass({ timeout: 90_000 })
    await confirm.click()
    expect((await confirmed).status()).toBe(200)
    await expect(portal.getByText(/Danke für Ihre Bestätigung|Thank you for your confirmation/)).toBeVisible({ timeout: 30_000 })
  } finally {
    await portal.close()
  }
}

/** Logs the buyer in to the Store API the way the storefront account does. */
async function storeLogin(a: Api, buyerEmail: string): Promise<Record<string, string>> {
  const salesChannel = await storefrontChannel(a)
  const store = { 'sw-access-key': salesChannel.accessKey, Accept: 'application/json', 'Content-Type': 'application/json' }
  const login = await a.ctx.post(`${SHOP_URL}/store-api/account/login`, {
    headers: store,
    data: { username: buyerEmail, password: CUSTOMER_PASSWORD },
  })
  expect(login.status(), await login.text()).toBe(200)
  return { ...store, 'sw-context-token': login.headers()['sw-context-token'] }
}

/** "Pay now" on an order in the buyer's account; returns where Shopware sends the buyer. */
async function payFromAccount(a: Api, store: Record<string, string>, id: string): Promise<string> {
  const pay = await a.ctx.post(`${SHOP_URL}/store-api/handle-payment`, {
    headers: store,
    data: { orderId: id, finishUrl: `${SHOP_URL}/checkout/finish`, errorUrl: `${SHOP_URL}/account/order` },
  })
  expect(pay.status(), await pay.text()).toBe(200)
  return String((await pay.json()).redirectUrl ?? '')
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
    await page.locator('.sw-select-result')
      .filter({ has: page.locator('.sw-select-result__result-item-text', { hasText: paymentMethod }) })
      .first()
      .click()
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
async function shownValue(field: Locator): Promise<string> {
  return field.evaluate((element) => {
    const inputs = [...element.querySelectorAll('input')].map(input => `${input.value} ${input.placeholder}`)
    return `${(element as HTMLElement).innerText} ${inputs.join(' ')}`
  })
}

/** Picks an option of a single select in the Mondu card (sw-single-select in 6.6, mt-select in 6.7). */
async function chooseOption(page: Page, field: Locator, option: RegExp): Promise<void> {
  await field.locator('.sw-select__selection, .mt-select__selection, input').first().click()
  await page.locator('.sw-select-result, .mt-select-result')
    .filter({ hasText: option })
    .first()
    .click()
  await expect.poll(() => shownValue(field)).toMatch(option)
}

/** Types into a text field of the Mondu card; the card saves its values a moment later. */
async function fillField(field: Locator, value: string): Promise<void> {
  await field.locator('input').fill(value)
  await field.locator('input').blur()
}

/**
 * Picks a date in the birth date calendar the way a merchant does: year, month, then day
 * (vue-datepicker behind mt-datepicker in 6.7, flatpickr behind sw-datepicker in 6.6).
 */
async function pickDate(page: Page, field: Locator, isoDate: string): Promise<void> {
  const [year, month, day] = isoDate.split('-').map(Number)
  await field.locator('input:visible').first().click()

  const menu = page.locator('.dp__menu')
  if (await menu.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await menu.locator('[data-dp-element="overlay-year"]').click()
    await menu.locator('.dp__overlay_cell', { hasText: new RegExp(`^\\s*${year}\\s*$`) }).click()
    if (await menu.locator('[data-dp-element="overlay-month"]').isVisible()) {
      await menu.locator('[data-dp-element="overlay-month"]').click()
    }
    await menu.locator('.dp__overlay_cell').nth(month - 1).click()
    await menu.locator(`[id="${isoDate}"]`).click()
    const select = menu.locator('.dp__action_select')
    if (await select.isVisible().catch(() => false)) {
      await select.click()
    }
  } else {
    const calendar = page.locator('.flatpickr-calendar.open')
    // flatpickr reads the year on keyup, which fill() does not send.
    const yearInput = calendar.locator('input.cur-year')
    await yearInput.fill('')
    await yearInput.pressSequentially(String(year))
    await yearInput.press('Enter')
    await calendar.locator('select.flatpickr-monthDropdown-months').selectOption({ index: month - 1 })
    await calendar.locator('.flatpickr-day:not(.prevMonthDay):not(.nextMonthDay)', { hasText: new RegExp(`^${day}$`) }).click()
  }

  await expect.poll(async () => field.locator('input:visible').first().inputValue()).toContain(String(year))
}

async function productId(a: Api): Promise<string> {
  const [product] = await search(a, 'product', { filter: [{ type: 'equals', field: 'productNumber', value: PRODUCT_NUMBER }], limit: 1 })
  return product.id
}

/** Status code and body of an admin API state transition. */
async function transition(a: Api, entity: string, id: string, name: string): Promise<{ status: number; body: string }> {
  const res = await a.ctx.post(`${SHOP_URL}/api/_action/state-machine/${entity}/${id}/state/${name}`, { headers: adminHeaders(a.token) })
  return { status: res.status(), body: await res.text() }
}

// Payment method names in either admin language (the plugin installs DE and EN names).
const INVOICE = /Rechnungskauf|Business net|Invoice/i
const SEPA = /SEPA/i
const PAY_NOW = /Sofort|Echtzeit|Instant|Pay now/i
const INSTALLMENT = /^\s*(Ratenkauf|Installment)(?!.*(UK|by invoice))/i
const INSTALLMENT_BY_INVOICE = /(Ratenkauf|Installment).*(UK|by invoice)/i

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
  let email = ''
  let declinedOrderId = ''

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

  async function clickSave(page: Page): Promise<void> {
    await page.locator('button', { hasText: /Bestellung speichern|Save order/ }).click()
  }

  async function saveOrder(page: Page): Promise<void> {
    const placed = page.waitForResponse(response => response.url().includes('/api/_proxy-order/'))
    await clickSave(page)
    const response = await placed
    expect(response.status(), `the order was refused: ${await response.text()}`).toBe(200)
    // No "remind the customer to pay" step: Mondu emails the buyer itself.
    await page.waitForURL(/sw\/order\/detail\//, { timeout: 60_000 })
    await expect(page.locator('.sw-order-create__remind-payment-modal')).toHaveCount(0)
  }

  /** Saves and expects the order to be refused with the given message; no order is written. */
  async function expectRefused(page: Page, buyerEmail: string, message: RegExp): Promise<void> {
    const placed = page.waitForResponse(response => response.url().includes('/api/_proxy-order/'))
    await clickSave(page)
    expect((await placed).status()).toBeGreaterThanOrEqual(400)
    await expect(page.getByText(message).first()).toBeVisible({ timeout: 30_000 })
    expect(await findOrder(a, buyerEmail), 'no order was written').toBeUndefined()
  }

  test('admin creates an invoice order; Mondu authorizes it and it waits for the buyer', async ({ page }) => {
    test.setTimeout(240_000)
    email = `ac.good.${Date.now()}@example.com`
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

  test('before the buyer confirms, the order cannot be shipped', async () => {
    expect(orderId, 'needs the order of the previous test').toBeTruthy()
    const order = await findOrder(a, email)

    const ship = await transition(a, 'order_delivery', order.deliveries[0].id, 'ship')
    expect(ship.status, ship.body).toBeGreaterThanOrEqual(400)
    expect(ship.body).toMatch(/must be confirmed/)
    expect((await orderStates(a, orderId)).transaction).toBe('mondu_pending_buyer_confirmation')
  })

  test('while the buyer is asked to confirm, the account offers no second payment', async () => {
    expect(orderId, 'needs the order of the previous test').toBeTruthy()
    const store = await storeLogin(a, email)

    // Shopware runs no payment for a transaction in a Mondu state, so no second Mondu order starts.
    expect(await payFromAccount(a, store, orderId)).toBe('')
    expect((await monduData(a, orderId)).referenceId).toBe(uuid)
    expect((await orderStates(a, orderId)).transaction).toBe('mondu_pending_buyer_confirmation')
  })

  test('the buyer confirms on the Mondu page; the order number goes to Mondu and the payment is settled', async ({ page }) => {
    test.setTimeout(180_000)
    expect(orderId, 'needs the order of the previous test').toBeTruthy()

    await confirmAsBuyer(a, page, uuid)

    // Mondu's real order/confirmed webhook settles the payment.
    await expect.poll(async () => orderStates(a, orderId), { timeout: 90_000 })
      .toMatchObject({ transaction: 'paid', mondu: 'confirmed' })
    const data = await monduData(a, orderId)
    expect(data.successful).toBe(true)
    expect(data.externalReferenceId).toBe(orderNumber)
    // update_external_info: Mondu now knows the order under its Shopware number.
    const monduOrder = await getMonduOrder(a, uuid)
    expect(monduOrder.state).toBe('confirmed')
    expect(monduOrder.external_reference_id).toBe(orderNumber)
  })

  test('once confirmed, the order ships and the invoice reaches Mondu', async () => {
    test.setTimeout(120_000)
    expect(orderId, 'needs the order of the previous test').toBeTruthy()

    const invoice = await a.ctx.post(`${SHOP_URL}/api/_action/order/document/invoice/create`, {
      headers: adminHeaders(a.token),
      data: [{ orderId, type: 'invoice', config: { documentNumber: `INV-ASYNC-${Date.now()}` } }],
    })
    expect(invoice.status(), await invoice.text()).toBe(200)

    const order = await findOrder(a, email)
    const ship = await transition(a, 'order_delivery', order.deliveries[0].id, 'ship')
    expect(ship.status, ship.body).toBe(200)

    await expect.poll(async () => (await getMonduOrder(a, uuid)).state, { timeout: 60_000 }).toBe('shipped')
    const apiToken = (await systemConfig(a, 'Mond1SW6.config'))['Mond1SW6.config.apiToken']
    await expect.poll(async () => {
      const res = await a.ctx.get(`${MONDU_API}/orders/${uuid}/invoices`, { headers: { 'Api-Token': apiToken } })
      return ((await res.json()).invoices || []).map((invoice: any) => invoice.invoice_number ?? invoice.external_reference_id)
    }, { timeout: 60_000 }).toEqual([expect.stringMatching(/^INV-ASYNC-/)])
  })

  test('order/authorized leaves orders that were not created in the admin alone', async () => {
    const result = await sendWebhook(a, 'order/authorized', orderNumber, randomUUID())
    expect(result.message).toMatch(/Not an order created in the admin/)
  })

  test('a SEPA order Mondu reviews waits in "Payment review"', async ({ page }) => {
    test.setTimeout(240_000)
    const buyer = `pending.good.${Date.now()}@example.com`
    const customer = await createCustomer(a, buyer)

    await adminLogin(page)
    await startAdminOrder(page, customer.customerNumber, SEPA)
    await addProduct(page, product)

    const card = page.locator('.mondu-async-order-fields')
    await expect(card).toBeVisible({ timeout: 20_000 })
    await fillField(card.locator('.mondu-async-order-fields__iban'), 'DE02 1203 0000 0000 2020 51')
    await fillField(card.locator('.mondu-async-order-fields__account-holder'), 'Mondu GmbH')

    await saveOrder(page)

    const order = await findOrder(a, buyer)
    const monduOrder = await getMonduOrder(a, (await monduData(a, order.id)).referenceId)
    expect(monduOrder.payment_method).toBe('direct_debit')
    expect(monduOrder.buyer.iban).toBe('DE02120300000000202051')
    await expect.poll(async () => (await orderStates(a, order.id)).transaction, { timeout: 60_000 }).toBe('mondu_payment_review')

    await openOrder(page, order.id)
    await expect(page.getByText(/Zahlungsprüfung \(Mondu\)|Payment review \(Mondu\)/).first()).toBeVisible({ timeout: 30_000 })
  })

  test('an installment order goes to Mondu with the chosen plan and bank account', async ({ page }) => {
    test.setTimeout(240_000)
    const buyer = `ac.good.${Date.now()}@example.com`
    const customer = await createCustomer(a, buyer)

    await adminLogin(page)
    await startAdminOrder(page, customer.customerNumber, INSTALLMENT)
    await addProduct(page, product, INSTALLMENT_QUANTITY)

    const card = page.locator('.mondu-async-order-fields')
    await expect(card).toBeVisible({ timeout: 20_000 })
    await expect(card.locator('.mondu-async-order-fields__net-term')).toHaveCount(0)
    await chooseOption(page, card.locator('.mondu-async-order-fields__number-of-installments'), /\b6\b/)
    await fillField(card.locator('.mondu-async-order-fields__iban'), 'DE02120300000000202051')
    await fillField(card.locator('.mondu-async-order-fields__account-holder'), 'Mondu GmbH')

    await saveOrder(page)

    const order = await findOrder(a, buyer)
    const monduOrder = await getMonduOrder(a, (await monduData(a, order.id)).referenceId)
    expect(monduOrder.payment_method).toBe('installment')
    expect(monduOrder.buyer.iban).toBe('DE02120300000000202051')
    await expect.poll(async () => (await orderStates(a, order.id)).transaction, { timeout: 60_000 })
      .toBe('mondu_pending_buyer_confirmation')
  })

  test('without enough order total Mondu refuses installments and no order is written', async ({ page }) => {
    test.setTimeout(180_000)
    const buyer = `ac.good.${Date.now()}@example.com`
    const customer = await createCustomer(a, buyer)

    await adminLogin(page)
    await startAdminOrder(page, customer.customerNumber, INSTALLMENT)
    await addProduct(page, product)

    const card = page.locator('.mondu-async-order-fields')
    await expect(card).toBeVisible({ timeout: 20_000 })
    await chooseOption(page, card.locator('.mondu-async-order-fields__number-of-installments'), /\b3\b/)
    await fillField(card.locator('.mondu-async-order-fields__iban'), 'DE02120300000000202051')
    await fillField(card.locator('.mondu-async-order-fields__account-holder'), 'Mondu GmbH')

    await expectRefused(page, buyer, /order total is below the minimum for this payment method/)
  })

  test('an installment-by-invoice order is confirmed by the buyer and settled', async ({ page }) => {
    test.setTimeout(300_000)
    const buyer = `ac.good.${Date.now()}@example.com`
    const customer = await createCustomer(a, buyer)

    await adminLogin(page)
    await startAdminOrder(page, customer.customerNumber, INSTALLMENT_BY_INVOICE)
    await addProduct(page, product, INSTALLMENT_QUANTITY)

    const card = page.locator('.mondu-async-order-fields')
    await expect(card).toBeVisible({ timeout: 20_000 })
    await expect(card.locator('.mondu-async-order-fields__iban')).toHaveCount(0)
    await chooseOption(page, card.locator('.mondu-async-order-fields__number-of-installments'), /\b12\b/)

    await saveOrder(page)

    const order = await findOrder(a, buyer)
    const reference = (await monduData(a, order.id)).referenceId
    expect((await getMonduOrder(a, reference)).payment_method).toBe('installment_by_invoice')
    await expect.poll(async () => (await orderStates(a, order.id)).transaction, { timeout: 60_000 })
      .toBe('mondu_pending_buyer_confirmation')

    await confirmAsBuyer(a, page, reference)
    await expect.poll(async () => orderStates(a, order.id), { timeout: 90_000 })
      .toMatchObject({ transaction: 'paid', mondu: 'confirmed' })
    expect((await getMonduOrder(a, reference)).external_reference_id).toBe(order.orderNumber)
  })

  test('changing an order that waits for the buyer does not adjust it at Mondu', async () => {
    test.setTimeout(120_000)
    // The installment order created above still waits for its buyer.
    const [order] = await search(a, 'order', {
      filter: [
        { type: 'equals', field: 'transactions.stateMachineState.technicalName', value: 'mondu_pending_buyer_confirmation' },
      ],
      associations: { lineItems: {} },
      sort: [{ field: 'orderDateTime', order: 'DESC' }],
      limit: 1,
    })
    expect(order, 'an order waiting for the buyer exists').toBeTruthy()
    const reference = (await monduData(a, order.id)).referenceId
    const before = (await getMonduOrder(a, reference)).real_price_cents
    const line = order.lineItems.find((item: any) => item.type === 'product')

    const version = await a.ctx.post(`${SHOP_URL}/api/_action/version/order/${order.id}`, { headers: adminHeaders(a.token) })
    expect(version.status()).toBe(200)
    const versionId = (await version.json()).versionId
    const versioned = { ...adminHeaders(a.token), 'sw-version-id': versionId }

    const patch = await a.ctx.patch(`${SHOP_URL}/api/order-line-item/${line.id}`, {
      headers: versioned,
      data: { quantity: line.quantity + 1 },
    })
    expect(patch.status(), await patch.text()).toBe(204)
    const recalc = await a.ctx.post(`${SHOP_URL}/api/_action/order/${order.id}/recalculate`, { headers: versioned })
    expect(recalc.status(), await recalc.text()).toBe(204)
    const merge = await a.ctx.post(`${SHOP_URL}/api/_action/version/merge/order/${versionId}`, { headers: versioned })
    expect(merge.status(), await merge.text()).toBe(204)

    const [changed] = await search(a, 'order', { ids: [order.id] })
    expect(changed.amountTotal).toBeGreaterThan(order.amountTotal)
    // The buyer still has to confirm the amount Mondu emailed, so Mondu keeps it.
    expect((await getMonduOrder(a, reference)).real_price_cents).toBe(before)
    expect((await orderStates(a, order.id)).transaction).toBe('mondu_pending_buyer_confirmation')
  })



  test('a sole trader needs the owner details, which go to Mondu with the order', async ({ page }) => {
    test.setTimeout(240_000)
    const buyer = `ac.good.${Date.now()}@example.com`
    const customer = await createCustomer(a, buyer)

    await adminLogin(page)
    await startAdminOrder(page, customer.customerNumber, INVOICE)
    await addProduct(page, product)

    const card = page.locator('.mondu-async-order-fields')
    await expect(card).toBeVisible({ timeout: 20_000 })
    await expect(card.locator('.mondu-async-order-fields__owner-birth-date')).toHaveCount(0)
    await chooseOption(page, card.locator('.mondu-async-order-fields__legal-form-category'), /Einzelunternehmen|Sole trader|Sole proprietorship/i)

    // The owner's name is taken from the customer; the date of birth is up to the merchant.
    await expect(card.locator('.mondu-async-order-fields__owner-first-name input')).toHaveValue('Test')
    await expect(card.locator('.mondu-async-order-fields__owner-last-name input')).toHaveValue('Mondu')
    await expect(card.locator('.mondu-async-order-fields__owner-birth-date')).toBeVisible()
    await page.waitForTimeout(1_000)

    await expectRefused(page, buyer, /Owner date of birth|owner_birth_date/)

    await pickDate(page, card.locator('.mondu-async-order-fields__owner-birth-date'), '1980-05-17')
    await page.waitForTimeout(1_000)
    await saveOrder(page)

    const order = await findOrder(a, buyer)
    expect(order, 'the order was created').toBeTruthy()
    const data = await monduData(a, order.id)
    expect(data.orderFlow).toBe('async')
    expect((await getMonduOrder(a, data.referenceId)).payment_method).toBe('invoice')
  })

  test('outside Germany the registration number is required before the order goes to Mondu', async ({ page }) => {
    test.setTimeout(300_000)
    const buyer = `ac.good.${Date.now()}@example.com`
    const customer = await createCustomer(a, buyer, 'NL')

    await adminLogin(page)
    // The sandbox account holds net terms for DE only, so invoice has none to offer a Dutch buyer.
    await startAdminOrder(page, customer.customerNumber, INVOICE)
    await addProduct(page, product)
    const card = page.locator('.mondu-async-order-fields')
    await expect(card).toBeVisible({ timeout: 20_000 })
    await expect(card.locator('.mondu-async-order-fields__no-net-terms')).toBeVisible()
    await expect(card.locator('.mondu-async-order-fields__net-term')).toHaveCount(0)
    await expectRefused(page, buyer, /Net term \(days\)/)

    // Installments by invoice need no net term; for NL Mondu wants the KVK number.
    await startAdminOrder(page, customer.customerNumber, INSTALLMENT_BY_INVOICE)
    await addProduct(page, product, INSTALLMENT_QUANTITY)
    await expect(card).toBeVisible({ timeout: 20_000 })
    await chooseOption(page, card.locator('.mondu-async-order-fields__number-of-installments'), /\b3\b/)
    await page.waitForTimeout(1_000)
    await expectRefused(page, buyer, /Registration ID/)

    // With it the order reaches Mondu. The sandbox account has no installment plans for NL, so
    // Mondu's answer is a refusal, and the merchant is told why in words they can act on.
    await fillField(card.locator('.mondu-async-order-fields__registration-id'), '86653938')
    await page.waitForTimeout(1_000)
    await expectRefused(page, buyer, /number of installments is not available for this buyer/)
  })

  test('an order Mondu declines is cancelled without asking Mondu to cancel it again', async ({ page }) => {
    test.setTimeout(240_000)
    const buyer = 'declined@example.com'
    // Mondu's sandbox declines this one address; earlier runs left customers with it behind,
    // and the buyer has to log in to the account later.
    for (const old of await search(a, 'customer', { filter: [{ type: 'equals', field: 'email', value: buyer }] })) {
      await a.ctx.delete(`${SHOP_URL}/api/customer/${old.id}`, { headers: adminHeaders(a.token) })
    }
    const customer = await createCustomer(a, buyer)

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
    declinedOrderId = order.id
  })

  test('a declined order cannot be paid with Mondu again from the account', async () => {
    expect(declinedOrderId, 'needs the order of the previous test').toBeTruthy()
    const reference = (await monduData(a, declinedOrderId)).referenceId
    const store = await storeLogin(a, 'declined@example.com')

    // A failed payment may be retried: the buyer picks a method again, and Shopware runs it.
    const [invoice] = await search(a, 'payment-method', {
      filter: [{ type: 'suffix', field: 'handlerIdentifier', value: '\\MonduHandler' }],
      limit: 1,
    })
    const change = await a.ctx.post(`${SHOP_URL}/store-api/order/payment`, {
      headers: store,
      data: { orderId: declinedOrderId, paymentMethodId: invoice.id },
    })
    expect(change.status(), await change.text()).toBe(200)

    // The Mondu handler refuses: the order was created in the admin and is with Mondu already.
    expect(await payFromAccount(a, store, declinedOrderId)).toMatch(/error-code=CHECKOUT__PAYMENT_ERROR/)
    expect((await monduData(a, declinedOrderId)).referenceId).toBe(reference)
  })

  test('a missing required field stops the order before it reaches Mondu', async ({ page }) => {
    test.setTimeout(180_000)
    const buyer = `ac.good.${Date.now()}@example.com`
    const customer = await createCustomer(a, buyer)

    await adminLogin(page)
    await startAdminOrder(page, customer.customerNumber, SEPA)
    await addProduct(page, product)
    await expect(page.locator('.mondu-async-order-fields__iban')).toBeVisible({ timeout: 20_000 })

    await expectRefused(page, buyer, /please fill the following required fields: IBAN, Account holder/)
  })

  test('with admin orders switched off the admin offers no Mondu method', async ({ page }) => {
    test.setTimeout(180_000)
    await setConfig(a, { 'Mond1SW6.config.backendOrdersEnabled': false })

    try {
      const buyer = `ac.good.${Date.now()}@example.com`
      const customer = await createCustomer(a, buyer)

      await adminLogin(page)
      const offered = await startAdminOrder(page, customer.customerNumber, null)
      expect(offered.some(name => INVOICE.test(name) || SEPA.test(name)), offered.join(', ')).toBe(false)
    } finally {
      await setConfig(a, { 'Mond1SW6.config.backendOrdersEnabled': true })
    }
  })
})
