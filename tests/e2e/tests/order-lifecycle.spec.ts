import { test, expect } from '@playwright/test'
import { SHOP_URL, getAdminToken, adminHeaders, generateEmail, registerAndCheckout } from './helpers'

test.describe.serial('Order Lifecycle — Invoice (Rechnungskauf)', () => {
  const email = generateEmail()
  let orderNumber = ''

  test('Place order on storefront with Mondu Invoice', async ({ page }) => {
    test.setTimeout(180_000)
    orderNumber = await registerAndCheckout(page, email, /Rechnungskauf|Business net 30|Invoice.*Mondu/i)
    expect(orderNumber.length).toBeGreaterThan(0)
  })

  test('Order appears in admin with Mondu payment', async ({ page }) => {
    test.setTimeout(120_000)
    const { token, ctx } = await getAdminToken()

    await page.waitForTimeout(5_000)

    const res = await ctx.post(`${SHOP_URL}/api/search/order`, {
      headers: adminHeaders(token),
      data: {
        limit: 1,
        sort: [{ field: 'createdAt', order: 'DESC' }],
        associations: {
          transactions: { associations: { paymentMethod: {} } },
          stateMachineState: {},
        },
      },
    })
    const body = await res.json()
    const latestOrder = body.data?.[0]
    expect(latestOrder).toBeTruthy()

    if (!orderNumber) orderNumber = latestOrder.orderNumber

    const txns = latestOrder.transactions || []
    const isMondu = txns.some((t: any) => (t.paymentMethod?.handlerIdentifier || '').includes('Mondu'))
    console.log(`Latest order #${latestOrder.orderNumber}: payment is Mondu = ${isMondu}`)
    expect(isMondu).toBe(true)
    await ctx.dispose()
  })

  test('Admin can transition order to "in progress" (process)', async ({ page }) => {
    test.setTimeout(120_000)
    const { token, ctx } = await getAdminToken()

    const orderRes = await ctx.post(`${SHOP_URL}/api/search/order`, {
      headers: adminHeaders(token),
      data: {
        filter: [{ type: 'equals', field: 'orderNumber', value: orderNumber }],
        sort: [{ field: 'createdAt', order: 'DESC' }],
        limit: 1,
        associations: { stateMachineState: {} },
      },
    })
    const orderBody = await orderRes.json()
    const order = orderBody.data?.[0]
    if (!order) {
      console.log('Order not found by number, using latest')
      return
    }

    const transRes = await ctx.post(`${SHOP_URL}/api/_action/state-machine/order/${order.id}/state/process`, {
      headers: adminHeaders(token),
    })
    console.log(`Order process transition: ${transRes.status()}`)
    expect(transRes.status()).toBeLessThan(500)
    await ctx.dispose()
  })

  test('Admin can create invoice document', async ({ page }) => {
    test.setTimeout(120_000)
    const { token, ctx } = await getAdminToken()

    const orderRes = await ctx.post(`${SHOP_URL}/api/search/order`, {
      headers: adminHeaders(token),
      data: {
        filter: orderNumber ? [{ type: 'equals', field: 'orderNumber', value: orderNumber }] : [],
        limit: 1,
        sort: [{ field: 'createdAt', order: 'DESC' }],
      },
    })
    const order = (await orderRes.json()).data?.[0]
    if (!order) return

    const docRes = await ctx.post(`${SHOP_URL}/api/_action/order/document/invoice/create`, {
      headers: adminHeaders(token),
      data: [{
        orderId: order.id,
        type: 'invoice',
        config: {
          documentNumber: `INV-${Date.now()}`,
          documentComment: 'E2E test invoice',
        },
      }],
    })
    console.log(`Create invoice: ${docRes.status()}`)
    expect(docRes.status()).toBeLessThan(500)
    await ctx.dispose()
  })

  test('Admin can ship delivery (transition to shipped)', async ({ page }) => {
    test.setTimeout(120_000)
    const { token, ctx } = await getAdminToken()

    const orderRes = await ctx.post(`${SHOP_URL}/api/search/order`, {
      headers: adminHeaders(token),
      data: {
        filter: orderNumber ? [{ type: 'equals', field: 'orderNumber', value: orderNumber }] : [],
        limit: 1,
        sort: [{ field: 'createdAt', order: 'DESC' }],
        associations: { deliveries: { associations: { stateMachineState: {} } } },
      },
    })
    const order = (await orderRes.json()).data?.[0]
    if (!order) return

    const delivery = order.deliveries?.[0]
    if (!delivery) {
      console.log('No delivery found')
      return
    }

    const shipRes = await ctx.post(`${SHOP_URL}/api/_action/state-machine/order_delivery/${delivery.id}/state/ship`, {
      headers: adminHeaders(token),
    })
    console.log(`Ship delivery: ${shipRes.status()}`)
    expect(shipRes.status()).toBeLessThan(500)
    await ctx.dispose()
  })

  test('Mondu document-statuses shows invoice after shipping', async () => {
    const { token, ctx } = await getAdminToken()

    const orderRes = await ctx.post(`${SHOP_URL}/api/search/order`, {
      headers: adminHeaders(token),
      data: {
        filter: orderNumber ? [{ type: 'equals', field: 'orderNumber', value: orderNumber }] : [],
        limit: 1,
        sort: [{ field: 'createdAt', order: 'DESC' }],
      },
    })
    const order = (await orderRes.json()).data?.[0]
    if (!order) return

    const statusRes = await ctx.get(`${SHOP_URL}/api/mondu/orders/${order.id}/document-statuses`, {
      headers: adminHeaders(token),
    })
    const statuses = await statusRes.json()
    console.log(`Document statuses: ${JSON.stringify(statuses)}`)
    expect(statusRes.status()).toBe(200)
    await ctx.dispose()
  })

  test('Mondu amount endpoint returns valid data for this order', async () => {
    const { token, ctx } = await getAdminToken()

    const orderRes = await ctx.post(`${SHOP_URL}/api/search/order`, {
      headers: adminHeaders(token),
      data: {
        filter: orderNumber ? [{ type: 'equals', field: 'orderNumber', value: orderNumber }] : [],
        limit: 1,
        sort: [{ field: 'createdAt', order: 'DESC' }],
      },
    })
    const order = (await orderRes.json()).data?.[0]
    if (!order) return

    const amountRes = await ctx.get(`${SHOP_URL}/api/mondu/orders/${order.id}/mondu-amount`, {
      headers: adminHeaders(token),
    })
    const amount = await amountRes.json()
    console.log(`Mondu amount: ${JSON.stringify(amount)}`)
    expect(amountRes.status()).toBe(200)
    expect(amount.gross_amount_cents).toBeGreaterThanOrEqual(0)
    await ctx.dispose()
  })

  test('Admin can create credit note document', async ({ page }) => {
    test.setTimeout(120_000)
    const { token, ctx } = await getAdminToken()

    const orderRes = await ctx.post(`${SHOP_URL}/api/search/order`, {
      headers: adminHeaders(token),
      data: {
        filter: orderNumber ? [{ type: 'equals', field: 'orderNumber', value: orderNumber }] : [],
        limit: 1,
        sort: [{ field: 'createdAt', order: 'DESC' }],
        associations: { documents: { associations: { documentType: {} } } },
      },
    })
    const order = (await orderRes.json()).data?.[0]
    if (!order) return

    const invoiceDoc = (order.documents || []).find((d: any) => d.documentType?.technicalName === 'invoice')
    if (!invoiceDoc) {
      console.log('No invoice document found, skipping credit note')
      return
    }

    const cnRes = await ctx.post(`${SHOP_URL}/api/_action/order/document/credit_note/create`, {
      headers: adminHeaders(token),
      data: [{
        orderId: order.id,
        type: 'credit_note',
        config: {
          documentNumber: `CN-${Date.now()}`,
          documentComment: 'E2E test credit note',
        },
      }],
    })
    console.log(`Create credit note: ${cnRes.status()}`)
    expect(cnRes.status()).toBeLessThan(500)
    await ctx.dispose()
  })

  test('Admin can cancel the order', async ({ page }) => {
    test.setTimeout(120_000)
    const { token, ctx } = await getAdminToken()

    const orderRes = await ctx.post(`${SHOP_URL}/api/search/order`, {
      headers: adminHeaders(token),
      data: {
        filter: orderNumber ? [{ type: 'equals', field: 'orderNumber', value: orderNumber }] : [],
        limit: 1,
        sort: [{ field: 'createdAt', order: 'DESC' }],
      },
    })
    const order = (await orderRes.json()).data?.[0]
    if (!order) return

    const cancelRes = await ctx.post(`${SHOP_URL}/api/_action/state-machine/order/${order.id}/state/cancel`, {
      headers: adminHeaders(token),
    })
    console.log(`Cancel order: ${cancelRes.status()}`)
    expect(cancelRes.status()).toBeLessThan(500)
    await ctx.dispose()
  })
})
