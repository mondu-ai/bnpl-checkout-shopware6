import { test, expect, request as playwrightRequest } from '@playwright/test'
import { generateEmail, registerAndCheckout } from './helpers'

/**
 * PT-5131: the plugin has to send the storefront language to Mondu.
 *
 * Without it the API derives the language from the billing country, and Belgium is mapped to
 * Dutch there, so a buyer who shopped in another language was sent to a Dutch hosted checkout.
 *
 * The test buys with a Belgian billing address on the German storefront and reads the order back
 * from the Mondu API. The stored language must be the storefront one, 'de', and never the country
 * default 'nl'.
 */

const MONDU_API_URL = process.env.MONDU_API_URL || 'https://api.demo.mondu.ai/api/v1'
const MONDU_API_TOKEN = process.env.MONDU_API_TOKEN || ''

/**
 * Looked up by the buyer email, not by the order number: order numbers restart per shop, and
 * several shops can share one Mondu merchant, so a number match can silently hit another shop's
 * order and turn a broken checkout into a passing test.
 */
async function findOrderByBuyerEmail(email: string): Promise<any> {
  const ctx = await playwrightRequest.newContext()

  try {
    for (let attempt = 0; attempt < 6; attempt++) {
      const res = await ctx.get(`${MONDU_API_URL}/orders?limit=50`, {
        headers: { 'Api-Token': MONDU_API_TOKEN, Accept: 'application/json' },
      })
      if (!res.ok()) {
        throw new Error(`Mondu API returned ${res.status()} for the order list`)
      }

      const orders = (await res.json()).orders || []
      const order = orders.find((o: any) => String(o.buyer?.email).toLowerCase() === email.toLowerCase())
      if (order) {
        return order
      }

      await new Promise((resolve) => setTimeout(resolve, 5_000))
    }

    return null
  } finally {
    await ctx.dispose()
  }
}

test.describe('PT-5131 hosted checkout language', () => {
  test.skip(!MONDU_API_TOKEN, 'MONDU_API_TOKEN is required to read the order back from Mondu')

  test('a Belgian buyer on the German storefront gets the German hosted checkout', async ({ page }) => {
    test.setTimeout(240_000)

    const email = generateEmail()
    const orderNumber = await registerAndCheckout(page, email, /Rechnungskauf|Invoice/i, 'BE')
    expect(orderNumber, 'the checkout must produce an order number').toBeTruthy()

    // Fails when the checkout fell back to a non-Mondu payment method, which is the failure this
    // lookup is here to catch: no Mondu order exists for that buyer at all.
    const order = await findOrderByBuyerEmail(email)
    expect(order, `Mondu must hold an order for buyer ${email}`).toBeTruthy()

    expect(order.billing_address?.country_code).toBe('BE')

    // The point of the fix: the storefront language wins over the country default.
    expect(order.language).toBe('de')
    expect(order.language).not.toBe('nl')
  })
})
