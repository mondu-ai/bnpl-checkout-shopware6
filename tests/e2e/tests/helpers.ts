import { request as playwrightRequest, type Page } from '@playwright/test'

export const SHOP_URL = process.env.SHOP_URL || 'https://sw66-ivan-local.casa-kuhl.de'
export const ADMIN_USER = process.env.SW_ADMIN_USER || 'demo'
export const ADMIN_PASS = process.env.SW_ADMIN_PASS || 'Passwort,123456'
export const STORE_API_KEY = process.env.STORE_API_KEY || ''

export async function getAdminToken(apiContext?: any): Promise<{ token: string; ctx: any }> {
  const ctx = apiContext || await playwrightRequest.newContext()
  const res = await ctx.post(`${SHOP_URL}/api/oauth/token`, {
    headers: { 'Content-Type': 'application/json' },
    data: {
      grant_type: 'password',
      client_id: 'administration',
      username: ADMIN_USER,
      password: ADMIN_PASS,
    },
  })
  const body = await res.json()
  if (!body.access_token) throw new Error(`Admin auth failed: ${JSON.stringify(body)}`)
  return { token: body.access_token, ctx }
}

export function adminHeaders(token: string) {
  return { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' }
}

export async function adminLogin(page: any): Promise<void> {
  await page.goto(`${SHOP_URL}/admin`)
  const usernameInput = page.locator('input[name="sw-field--username"]')
  await usernameInput.waitFor({ state: 'visible', timeout: 30_000 })
  await usernameInput.fill(ADMIN_USER)
  await page.locator('input[name="sw-field--password"]').fill(ADMIN_PASS)
  await page.getByRole('button', { name: /anmelden|log\s*in/i }).click()
  await page.waitForURL('**/admin#/sw/dashboard/**', { timeout: 30_000 })
  // Wait for Vue app to finish mounting
  await page.waitForSelector('.sw-admin-menu', { timeout: 20_000 })

  // Always finish the wizard via API (idempotent) — marks it done in DB for all future sessions
  await page.evaluate(async () => {
    const getToken = () => (window as any).Shopware?.Context?.api?.authToken?.access
    // Wait briefly for token to be available
    for (let i = 0; i < 10; i++) {
      if (getToken()) break
      await new Promise(r => setTimeout(r, 200))
    }
    const token = getToken()
    if (token) {
      await fetch('/api/_action/first-run-wizard/finish', {
        method: 'POST',
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json' },
      }).catch(() => {})
    }
  }).catch(() => {})

  // Dismiss update notification if present
  const updateCancelBtn = page.locator('button:has-text("Abbrechen"), button:has-text("Cancel")').first()
  if (await updateCancelBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await updateCancelBtn.click()
    await page.waitForTimeout(500)
  }

  // If wizard modal still visible, reload to clear it
  const wizardModal = page.locator('.sw-first-run-wizard, [class*="first-run-wizard"], .sw-modal:has-text("Willkommen")')
  if (await wizardModal.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await page.reload({ waitUntil: 'networkidle' })
    await page.waitForSelector('.sw-admin-menu', { timeout: 15_000 })
    const updateBtn2 = page.locator('button:has-text("Abbrechen"), button:has-text("Cancel")').first()
    if (await updateBtn2.isVisible({ timeout: 2_000 }).catch(() => false)) {
      await updateBtn2.click()
      await page.waitForTimeout(500)
    }
  }
}

export async function getMonduPaymentMethodIds(ctx: any, token: string): Promise<Record<string, string>> {
  const res = await ctx.post(`${SHOP_URL}/api/search/payment-method`, {
    headers: adminHeaders(token),
    data: {
      filter: [{ type: 'contains', field: 'handlerIdentifier', value: 'Mondu' }],
      limit: 10,
    },
  })
  const body = await res.json()
  const map: Record<string, string> = {}
  for (const pm of body.data || []) {
    const hi = pm.handlerIdentifier || ''
    if (hi.includes('MonduHandler') && !hi.includes('Sepa') && !hi.includes('Installment') && !hi.includes('PayNow')) {
      map['invoice'] = pm.id
    } else if (hi.includes('MonduSepa')) {
      map['sepa'] = pm.id
    } else if (hi.includes('MonduInstallmentByInvoice')) {
      map['installment_by_invoice'] = pm.id
    } else if (hi.includes('MonduInstallment')) {
      map['installment'] = pm.id
    } else if (hi.includes('MonduPayNow')) {
      map['pay_now'] = pm.id
    }
  }
  return map
}

const PRODUCT_URL = '/Hauptprodukt-versandkostenfrei-mit-Hervorhebung/SWDEMO10006'
const CUSTOMER_FIRST = 'Test'
const CUSTOMER_LAST = 'Mondu'
const CUSTOMER_COMPANY = 'Mondu GmbH'
const CUSTOMER_STREET = 'Charlottenstraße 68'
const CUSTOMER_ZIP = '10117'
const CUSTOMER_CITY = 'Berlin'

export function generateEmail(): string {
  const rand = Math.random().toString(36).substring(2, 10)
  return `ac.good.${rand}@example.com`
}

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

function storeApiHeaders(key: string) {
  return { 'sw-access-key': key, Accept: 'application/json', 'Content-Type': 'application/json' }
}

export async function registerAndCheckout(page: Page, email: string, paymentMethodName: RegExp, countryIso: string = 'DE'): Promise<string> {
  const apiKey = await getStoreApiKey()
  const ctx = await playwrightRequest.newContext()

  // Fetch salutation and country IDs via Store API
  const [salRes, countryRes] = await Promise.all([
    ctx.get(`${SHOP_URL}/store-api/salutation`, { headers: storeApiHeaders(apiKey) }),
    ctx.post(`${SHOP_URL}/store-api/country`, {
      headers: storeApiHeaders(apiKey),
      data: { filter: [{ type: 'equals', field: 'iso', value: countryIso }], limit: 1 },
    }),
  ])
  const salutations = (await salRes.json()).elements || []
  const salutationId = salutations.find((s: any) => s.salutationKey !== 'not_specified')?.id || salutations[0]?.id
  const countries = (await countryRes.json()).elements || []
  const countryId = countries[0]?.id
  if (!salutationId || !countryId) throw new Error(`Could not fetch salutation or country ID (${countryIso}) from Store API`)

  // Register customer via Store API
  const regRes = await ctx.post(`${SHOP_URL}/store-api/account/register`, {
    headers: storeApiHeaders(apiKey),
    data: {
      email,
      password: 'Shopware123!',
      salutationId,
      firstName: CUSTOMER_FIRST,
      lastName: CUSTOMER_LAST,
      storefrontUrl: SHOP_URL,
      accountType: 'business',
      billingAddress: {
        salutationId,
        firstName: CUSTOMER_FIRST,
        lastName: CUSTOMER_LAST,
        company: CUSTOMER_COMPANY,
        street: CUSTOMER_STREET,
        zipcode: CUSTOMER_ZIP,
        city: CUSTOMER_CITY,
        countryId,
      },
    },
  })
  if (!regRes.ok()) {
    const body = await regRes.json().catch(() => ({}))
    throw new Error(`Store API registration failed: ${JSON.stringify(body)}`)
  }
  await ctx.dispose()

  // Log in via browser
  await page.goto(`${SHOP_URL}/account/login`)
  await page.waitForSelector('input[name="email"]', { timeout: 15_000 })

  const cookieBtn = page.locator('button:has-text("Only technically required"), button:has-text("Nur technisch erforderliche")')
  if (await cookieBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await cookieBtn.click()
    await page.waitForTimeout(500)
  }

  await page.locator('#loginMail, input[name="email"]').first().fill(email)
  await page.locator('#loginPassword, input[name="password"]').first().fill('Shopware123!')
  await page.locator('form[action*="login"] button[type="submit"], .login-submit .btn-primary').first().click()
  await page.waitForURL(/\/account/, { timeout: 30_000 })

  if (page.url().includes('/account/login')) {
    throw new Error(`Login failed for ${email}. URL: ${page.url()}`)
  }

  // Add product to cart
  await page.goto(`${SHOP_URL}${PRODUCT_URL}`)
  await page.waitForSelector('.product-detail-buy', { timeout: 15_000 })
  await page.locator('.btn-buy').click()
  await page.waitForTimeout(2_000)

  // Navigate through checkout flow to initialize session
  // Go to /checkout/register first (SW6 checkout identity step)
  await page.goto(`${SHOP_URL}/checkout/register`)
  await page.waitForTimeout(2_000)

  // For a logged-in customer with billing address, /checkout/register should show
  // the address confirmation step with a link to /checkout/confirm
  if (page.url().includes('/checkout/register')) {
    // Look for the direct link to confirm page
    const confirmLink = page.locator('a[href*="/checkout/confirm"]').first()
    const hasConfirmLink = await confirmLink.isVisible({ timeout: 8_000 }).catch(() => false)
    if (hasConfirmLink) {
      await confirmLink.click()
      await page.waitForURL('**/checkout/confirm**', { timeout: 15_000 })
      await page.waitForTimeout(1_500)
    } else {
      // Fallback: navigate directly to confirm
      await page.goto(`${SHOP_URL}/checkout/confirm`)
      await page.waitForTimeout(2_000)
    }
  }

  if (!page.url().includes('/checkout/confirm')) {
    throw new Error(`Expected checkout/confirm, got: ${page.url()}`)
  }

  await page.waitForSelector('.confirm-main, .confirm-product-item, .checkout-container', { timeout: 15_000 })

  // Debug: print all payment method labels visible on the page
  const pmInputs = page.locator('input[name="paymentMethodId"], .payment-method-input, .payment-method-radio')
  const pmCount = await pmInputs.count()
  console.log(`Payment method inputs found: ${pmCount}`)
  if (pmCount > 0) {
    const pmLabels = await page.locator('.payment-method-label-name, .payment-method-label, label[for*="payment"]').allTextContents()
    console.log('Payment method labels:', pmLabels)
  }

  // Switch payment method via Store API (browser context shares session cookie)
  const switched = await page.evaluate(async (params: { shopUrl: string; apiKey: string; methodName: string }) => {
    // Fetch available payment methods
    const pmRes = await fetch(`${params.shopUrl}/store-api/payment-method?onlyAvailable=true`, {
      headers: { 'sw-access-key': params.apiKey, 'Content-Type': 'application/json' },
    }).catch(() => null)
    if (!pmRes) return { ok: false, error: 'fetch failed', methods: [] }
    const pmData = await pmRes.json().catch(() => ({}))
    const methods = (pmData.elements || []).map((m: any) => ({ id: m.id, name: m.name, shortName: m.shortName || '' }))
    console.log('Store API payment methods:', JSON.stringify(methods))

    // Find Mondu invoice method (shortName: "mondu_handler", name: "Rechnungskauf (30 Tage)")
    const monduMethod = methods.find((m: any) =>
      m.shortName === 'mondu_handler' ||
      /Rechnungskauf|Business net 30|Invoice.*Mondu/i.test(m.name)
    )
    if (!monduMethod) return { ok: false, error: 'Mondu invoice method not found', methods }

    // Switch payment method
    const switchRes = await fetch(`${params.shopUrl}/store-api/context`, {
      method: 'PATCH',
      headers: { 'sw-access-key': params.apiKey, 'Content-Type': 'application/json' },
      body: JSON.stringify({ paymentMethodId: monduMethod.id }),
    }).catch(() => null)
    if (!switchRes) return { ok: false, error: 'context patch failed', methods }
    const switchData = await switchRes.json().catch(() => ({}))
    return { ok: switchRes.ok, status: switchRes.status, switchData, monduId: monduMethod.id, methods }
  }, { shopUrl: SHOP_URL, apiKey: apiKey, methodName: 'Mondu' })

  console.log('Payment method switch result:', JSON.stringify(switched))

  const monduMethodId = (switched as any).ok ? (switched as any).monduId : null

  if ((switched as any).ok) {
    // Reload confirm page to get a fresh hash for the current cart state
    await page.reload({ waitUntil: 'networkidle' })
    await page.waitForSelector('.confirm-main, .confirm-product-item, .checkout-container', { timeout: 15_000 })
  }

  // SW6: payment method is changed via "changePaymentForm" (separate from the order submit form).
  // We must select the Mondu radio and SUBMIT changePaymentForm, which updates the cart and regenerates the hash.
  if (monduMethodId) {
    const radioByValue = page.locator(`#changePaymentForm input[name="paymentMethodId"][value="${monduMethodId}"]`)
    if (await radioByValue.count() > 0) {
      // Select the Mondu radio
      await radioByValue.first().click({ force: true })
      // Find and submit the changePaymentForm
      const changeFormSubmit = page.locator('#changePaymentForm button[type="submit"], #changePaymentForm [type="submit"]').first()
      if (await changeFormSubmit.count() > 0) {
        console.log('Submitting changePaymentForm to switch to Mondu')
        await changeFormSubmit.click({ force: true })
      } else {
        // Fallback: submit the form programmatically
        console.log('No submit button in changePaymentForm — submitting programmatically')
        await page.evaluate((id: string) => {
          const form = document.getElementById('changePaymentForm') as HTMLFormElement | null
          if (form) {
            const radio = form.querySelector(`input[value="${id}"]`) as HTMLInputElement | null
            if (radio) radio.checked = true
            form.submit()
          }
        }, monduMethodId)
      }
      // Wait for redirect back to /checkout/confirm with new hash
      await page.waitForURL('**/checkout/confirm**', { timeout: 20_000 }).catch(() => {})
      await page.waitForSelector('.confirm-main, .confirm-product-item, .checkout-container', { timeout: 15_000 })
      console.log(`After payment switch, URL: ${page.url()}`)
    } else {
      console.log(`changePaymentForm radio not found for id=${monduMethodId}`)
    }
  }

  const tosCheckbox = page.locator('#tos, input[name="tos"]')
  if (await tosCheckbox.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await tosCheckbox.check({ force: true })
  }

  const cookieBtn2 = page.locator('button:has-text("Only technically required"), button:has-text("Nur technisch erforderliche")')
  if (await cookieBtn2.isVisible({ timeout: 1_000 }).catch(() => false)) {
    await cookieBtn2.click()
    await page.waitForTimeout(500)
  }

  console.log(`Submitting order from: ${page.url()}`)
  await page.locator('#confirmFormSubmit').click()

  const isMonduPage = await page.waitForURL(/pay\.demo\.mondu|mondu\.ai/, { timeout: 30_000 }).catch(() => null)
  if (isMonduPage || page.url().includes('mondu')) {
    console.log(`Redirected to Mondu: ${page.url()}`)

    const payBtn = page.locator('button:has-text("Zahlen mit"), button:has-text("Pay with"), button:has-text("Confirm"), button:has-text("Bestätigen")').first()
    await payBtn.waitFor({ state: 'visible', timeout: 30_000 })
    await payBtn.click()
    console.log('Clicked Mondu pay button')

    await page.waitForURL(`${SHOP_URL}/**`, { timeout: 60_000 })
    console.log(`Returned to shop: ${page.url()}`)
  }

  await page.waitForTimeout(3_000)
  const currentUrl = page.url()
  console.log(`After checkout URL: ${currentUrl}`)

  let num = ''
  const pageText = await page.locator('body').textContent({ timeout: 10_000 }).catch(() => '') || ''
  const match = pageText.match(/#(\d{4,})/) || pageText.match(/(?:order\s*number|Bestellnummer)[:\s#]*(\d{4,})/i)
  num = match ? match[1] : ''

  if (!num) {
    const urlMatch = page.url().match(/orderId=([a-f0-9]+)/)
    if (urlMatch) num = urlMatch[1]
  }

  console.log(`Order number: ${num}`)
  return num
}

export async function getMonduOrders(ctx: any, token: string, limit = 10): Promise<any[]> {
  const res = await ctx.post(`${SHOP_URL}/api/search/order`, {
    headers: adminHeaders(token),
    data: {
      limit,
      sort: [{ field: 'createdAt', order: 'DESC' }],
      associations: {
        transactions: { associations: { paymentMethod: {}, stateMachineState: {} } },
        stateMachineState: {},
        documents: { associations: { documentType: {} } },
      },
    },
  })
  const body = await res.json()
  return (body.data || []).filter((o: any) => {
    const txns = o.transactions || []
    return txns.some((t: any) => (t.paymentMethod?.handlerIdentifier || '').includes('Mondu'))
  })
}
