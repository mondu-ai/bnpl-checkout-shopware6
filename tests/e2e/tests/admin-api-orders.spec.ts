import { test, expect } from '@playwright/test'
import { SHOP_URL, getAdminToken, adminHeaders, generateEmail, registerAndCheckout } from './helpers'

test.describe.serial('Admin API — Mondu order queries', () => {
  let orderId = ''
  let orderNumber = ''
  const email = generateEmail()

  test('Place Mondu order, create invoice, ship', async ({ page }) => {
    test.setTimeout(180_000)
    orderNumber = await registerAndCheckout(page, email, /Rechnungskauf|Business net 30|Invoice.*Mondu/i)
    expect(orderNumber.length).toBeGreaterThan(0)

    const { token, ctx } = await getAdminToken()

    await page.waitForTimeout(3_000)

    const orderRes = await ctx.post(`${SHOP_URL}/api/search/order`, {
      headers: adminHeaders(token),
      data: {
        filter: [{ type: 'equals', field: 'orderNumber', value: orderNumber }],
        limit: 1,
        associations: { deliveries: {} },
      },
    })
    const order = (await orderRes.json()).data?.[0]
    expect(order).toBeTruthy()
    orderId = order.id

    await ctx.post(`${SHOP_URL}/api/_action/state-machine/order/${orderId}/state/process`, {
      headers: adminHeaders(token),
    })

    await ctx.post(`${SHOP_URL}/api/_action/order/document/invoice/create`, {
      headers: adminHeaders(token),
      data: [{
        orderId,
        type: 'invoice',
        config: { documentNumber: `INV-API-${Date.now()}`, documentComment: 'E2E admin-api test' },
      }],
    })

    const delivery = order.deliveries?.[0]
    if (delivery) {
      await ctx.post(`${SHOP_URL}/api/_action/state-machine/order_delivery/${delivery.id}/state/ship`, {
        headers: adminHeaders(token),
      })
    }

    await page.waitForTimeout(2_000)
    console.log(`Setup complete: order #${orderNumber} (${orderId})`)
    await ctx.dispose()
  })

  test('GET /api/mondu/orders/{id}/document-statuses returns data', async () => {
    const { token, ctx } = await getAdminToken()
    const res = await ctx.get(`${SHOP_URL}/api/mondu/orders/${orderId}/document-statuses`, {
      headers: adminHeaders(token),
    })
    expect(res.status()).toBe(200)
    const body = await res.json()
    console.log('Document statuses:', JSON.stringify(body))
    await ctx.dispose()
  })

  test('GET /api/mondu/orders/{id}/mondu-amount returns data', async () => {
    const { token, ctx } = await getAdminToken()
    const res = await ctx.get(`${SHOP_URL}/api/mondu/orders/${orderId}/mondu-amount`, {
      headers: adminHeaders(token),
    })
    expect(res.status()).toBe(200)
    const body = await res.json()
    console.log(`Mondu amount for #${orderNumber}: ${JSON.stringify(body)}`)
    expect(body).toHaveProperty('gross_amount_cents')
    await ctx.dispose()
  })

  test('GET /api/mondu/orders/{fakeId}/document-statuses returns error (no 500)', async () => {
    const { token, ctx } = await getAdminToken()
    const fakeOrderId = '00000000000000000000000000000000'
    const res = await ctx.get(`${SHOP_URL}/api/mondu/orders/${fakeOrderId}/document-statuses`, {
      headers: adminHeaders(token),
    })
    expect(res.status()).not.toBe(500)
    console.log(`Document statuses on fake order: ${res.status()}`)
    await ctx.dispose()
  })

  test('GET /api/mondu/orders/{fakeId}/mondu-amount returns error (no 500)', async () => {
    const { token, ctx } = await getAdminToken()
    const fakeOrderId = '00000000000000000000000000000000'
    const res = await ctx.get(`${SHOP_URL}/api/mondu/orders/${fakeOrderId}/mondu-amount`, {
      headers: adminHeaders(token),
    })
    expect(res.status()).not.toBe(500)
    console.log(`Mondu amount on fake order: ${res.status()}`)
    await ctx.dispose()
  })

  test('POST /api/mondu/orders/{fakeId}/{fakeInvoiceId}/cancel returns error (no 500)', async () => {
    const { token, ctx } = await getAdminToken()
    const fakeOrderId = '00000000000000000000000000000000'
    const fakeInvoiceId = '00000000000000000000000000000000'
    const res = await ctx.post(`${SHOP_URL}/api/mondu/orders/${fakeOrderId}/${fakeInvoiceId}/cancel`, {
      headers: adminHeaders(token),
    })
    expect(res.status()).not.toBe(500)
    console.log(`Cancel invoice on fake order: ${res.status()}`)
    await ctx.dispose()
  })

  test('POST /api/mondu/orders/{fakeId}/credit_notes/{fakeId}/cancel returns error (no 500)', async () => {
    const { token, ctx } = await getAdminToken()
    const fakeOrderId = '00000000000000000000000000000000'
    const fakeCnId = '00000000000000000000000000000000'
    const res = await ctx.post(`${SHOP_URL}/api/mondu/orders/${fakeOrderId}/credit_notes/${fakeCnId}/cancel`, {
      headers: adminHeaders(token),
    })
    expect(res.status()).not.toBe(500)
    console.log(`Cancel credit note on fake order: ${res.status()}`)
    await ctx.dispose()
  })
})

test.describe('Admin API — Mondu config', () => {
  test('POST /api/mondu/config/test validates credentials', async () => {
    const { token, ctx } = await getAdminToken()
    const res = await ctx.post(`${SHOP_URL}/api/mondu/config/test`, {
      headers: adminHeaders(token),
      data: {},
    })
    console.log(`Config test: ${res.status()}`)
    expect(res.status()).not.toBe(500)
    await ctx.dispose()
  })

  test('Admin API requires authentication', async () => {
    const ctx = (await getAdminToken()).ctx
    const res = await ctx.get(`${SHOP_URL}/api/mondu/orders/fake/document-statuses`, {
      headers: { Accept: 'application/json' },
    })
    expect(res.status()).toBe(401)
    await ctx.dispose()
  })
})

test.describe.serial('Admin API — order data integrity', () => {
  let orderId = ''
  const email = generateEmail()

  test('Place Mondu order for data integrity checks', async ({ page }) => {
    test.setTimeout(180_000)
    const num = await registerAndCheckout(page, email, /Rechnungskauf|Business net 30|Invoice.*Mondu/i)
    expect(num.length).toBeGreaterThan(0)

    const { token, ctx } = await getAdminToken()
    await page.waitForTimeout(3_000)

    const orderRes = await ctx.post(`${SHOP_URL}/api/search/order`, {
      headers: adminHeaders(token),
      data: {
        filter: [{ type: 'equals', field: 'orderNumber', value: num }],
        limit: 1,
        associations: { deliveries: {} },
      },
    })
    const order = (await orderRes.json()).data?.[0]
    expect(order).toBeTruthy()
    orderId = order.id

    await ctx.post(`${SHOP_URL}/api/_action/state-machine/order/${orderId}/state/process`, {
      headers: adminHeaders(token),
    })
    await ctx.post(`${SHOP_URL}/api/_action/order/document/invoice/create`, {
      headers: adminHeaders(token),
      data: [{
        orderId,
        type: 'invoice',
        config: { documentNumber: `INV-INT-${Date.now()}`, documentComment: 'E2E integrity test' },
      }],
    })
    const delivery = order.deliveries?.[0]
    if (delivery) {
      await ctx.post(`${SHOP_URL}/api/_action/state-machine/order_delivery/${delivery.id}/state/ship`, {
        headers: adminHeaders(token),
      })
    }
    await page.waitForTimeout(2_000)
    console.log(`Integrity test order: #${num} (${orderId})`)
    await ctx.dispose()
  })

  test('Mondu order has valid transaction state', async () => {
    const { token, ctx } = await getAdminToken()
    const res = await ctx.post(`${SHOP_URL}/api/search/order`, {
      headers: adminHeaders(token),
      data: {
        filter: [{ type: 'equals', field: 'id', value: orderId }],
        limit: 1,
        associations: { transactions: { associations: { stateMachineState: {} } } },
      },
    })
    const order = (await res.json()).data?.[0]
    expect(order).toBeTruthy()

    const validTxStates = ['open', 'paid', 'authorized', 'cancelled', 'failed', 'in_progress',
      'reminded', 'refunded', 'partially_refunded', 'unconfirmed', 'process_unconfirmed',
      'paid_partially', 'refunded_partially']

    for (const tx of order.transactions || []) {
      const txState = tx.stateMachineState?.technicalName
      if (txState) {
        expect(validTxStates).toContain(txState)
      }
    }
    console.log(`Transaction state verified for order ${orderId}`)
    await ctx.dispose()
  })

  test('Mondu order with invoice has document-statuses working', async () => {
    const { token, ctx } = await getAdminToken()
    const res = await ctx.get(`${SHOP_URL}/api/mondu/orders/${orderId}/document-statuses`, {
      headers: adminHeaders(token),
    })
    expect(res.status()).toBe(200)
    const body = await res.json()
    console.log(`Document statuses: ${JSON.stringify(body)}`)
    await ctx.dispose()
  })

  test('Mondu order has mondu-amount returning valid data', async () => {
    const { token, ctx } = await getAdminToken()
    const res = await ctx.get(`${SHOP_URL}/api/mondu/orders/${orderId}/mondu-amount`, {
      headers: adminHeaders(token),
    })
    expect(res.status()).toBe(200)
    const body = await res.json()
    expect(typeof body.gross_amount_cents).toBe('number')
    expect(body.gross_amount_cents).toBeGreaterThanOrEqual(0)
    console.log(`Mondu amount: ${JSON.stringify(body)}`)
    await ctx.dispose()
  })
})
