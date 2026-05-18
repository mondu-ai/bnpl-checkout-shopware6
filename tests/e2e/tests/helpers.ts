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

export async function registerAndCheckout(page: Page, email: string, paymentMethodName: RegExp): Promise<string> {
  await page.goto(`${SHOP_URL}/account/register`)
  await page.waitForSelector('select[name="accountType"]', { timeout: 15_000 })

  const cookieBtn = page.locator('.cookie-permission-container .btn-primary, button:has-text("Only technically required"), button:has-text("Nur technisch erforderliche")')
  if (await cookieBtn.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await cookieBtn.click()
    await page.waitForTimeout(500)
  }

  const registerForm = page.locator('.register-form, form[action*="register"]').last()
  await registerForm.locator('select[name="accountType"]').selectOption('business')
  await page.waitForTimeout(500)

  const salutationSelect = registerForm.locator('select[name="salutationId"]')
  if (await salutationSelect.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await salutationSelect.selectOption({ index: 1 })
  }

  const fnField = registerForm.locator('[name="firstName"], [name="billingAddress[firstName]"]').first()
  await fnField.fill(CUSTOMER_FIRST)
  const lnField = registerForm.locator('[name="lastName"], [name="billingAddress[lastName]"]').first()
  await lnField.fill(CUSTOMER_LAST)

  const companyField = registerForm.locator('[name="billingAddress[company]"]')
  if (await companyField.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await companyField.fill(CUSTOMER_COMPANY)
  }

  const emailField = registerForm.locator('[name="email"]').last()
  await emailField.fill(email)
  await registerForm.locator('#personalPassword, [name="password"][autocomplete="new-password"], .register-form [name="password"]').last().fill('Shopware123!')

  await registerForm.locator('[name="billingAddress[street]"]').fill(CUSTOMER_STREET)
  await registerForm.locator('[name="billingAddress[zipcode]"]').fill(CUSTOMER_ZIP)
  await registerForm.locator('[name="billingAddress[city]"]').fill(CUSTOMER_CITY)

  const countrySelect = registerForm.locator('select[name="billingAddress[countryId]"]')
  const options = countrySelect.locator('option')
  const count = await options.count()
  for (let i = 0; i < count; i++) {
    const text = await options.nth(i).textContent()
    if (text?.includes('Deutschland') || text?.includes('Germany')) {
      await countrySelect.selectOption({ index: i })
      break
    }
  }

  await registerForm.locator('.register-submit button[type="submit"], .register-submit .btn-primary, button[type="submit"]:has-text("Continue")').click()
  await page.waitForURL(/\/(account|checkout)/, { timeout: 30_000 })

  await page.goto(`${SHOP_URL}${PRODUCT_URL}`)
  await page.waitForSelector('.product-detail-buy', { timeout: 15_000 })
  await page.locator('.btn-buy').click()

  await page.waitForTimeout(2_000)

  await page.goto(`${SHOP_URL}/checkout/confirm`)
  await page.waitForTimeout(2_000)

  if (page.url().includes('/checkout/cart')) {
    const checkoutLink = page.locator('a.begin-checkout-btn, a.btn-primary[href*="confirm"]').first()
    if (await checkoutLink.isVisible({ timeout: 5_000 }).catch(() => false)) {
      await checkoutLink.click()
      await page.waitForURL('**/checkout/confirm**', { timeout: 15_000 })
    }
  }

  if (page.url().includes('/account/login') || page.url().includes('/account/register')) {
    throw new Error(`Redirected to login — registration may have failed. URL: ${page.url()}`)
  }

  await page.waitForSelector('.confirm-main, .confirm-product-item, .checkout-container', { timeout: 15_000 })

  const paymentOption = page.locator('.payment-method-input, .payment-method-radio').locator('..').locator('..').filter({ hasText: paymentMethodName })
  if (await paymentOption.count() > 0) {
    await paymentOption.first().locator('label, .payment-method-label').first().click()
    await page.waitForTimeout(2_000)
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
