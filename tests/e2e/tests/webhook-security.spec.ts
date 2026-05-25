import { test, expect, request as playwrightRequest } from '@playwright/test'
import { SHOP_URL } from './helpers'

test.describe('Webhook endpoint — signature validation', () => {
  test('POST /mondu/webhooks without signature returns 401', async () => {
    const ctx = await playwrightRequest.newContext()
    const res = await ctx.post(`${SHOP_URL}/mondu/webhooks`, {
      data: { topic: 'order/confirmed', order_uuid: 'test-uuid' },
      headers: { 'Content-Type': 'application/json' },
    })
    expect(res.status()).toBe(401)
    await ctx.dispose()
  })

  test('POST /mondu/webhooks with invalid signature returns 401', async () => {
    const ctx = await playwrightRequest.newContext()
    const res = await ctx.post(`${SHOP_URL}/mondu/webhooks`, {
      data: { topic: 'order/confirmed', order_uuid: 'test-uuid' },
      headers: {
        'Content-Type': 'application/json',
        'X-Mondu-Signature': 'invalid-signature-abc123',
      },
    })
    expect(res.status()).toBe(401)
    await ctx.dispose()
  })

  test('POST /mondu/webhooks with empty signature returns 401', async () => {
    const ctx = await playwrightRequest.newContext()
    const res = await ctx.post(`${SHOP_URL}/mondu/webhooks`, {
      data: { topic: 'order/confirmed', order_uuid: 'test-uuid' },
      headers: {
        'Content-Type': 'application/json',
        'X-Mondu-Signature': '',
      },
    })
    expect(res.status()).toBe(401)
    await ctx.dispose()
  })

  test('POST /mondu/webhooks with empty body returns 401', async () => {
    const ctx = await playwrightRequest.newContext()
    const res = await ctx.post(`${SHOP_URL}/mondu/webhooks`, {
      data: {},
      headers: { 'Content-Type': 'application/json' },
    })
    expect(res.status()).toBe(401)
    await ctx.dispose()
  })

  test('POST /mondu/webhooks returns response body with error info', async () => {
    const ctx = await playwrightRequest.newContext()
    const res = await ctx.post(`${SHOP_URL}/mondu/webhooks`, {
      data: { topic: 'order/confirmed', order_uuid: 'test-uuid' },
      headers: { 'Content-Type': 'application/json' },
    })
    expect(res.status()).toBe(401)
    const body = await res.text()
    expect(body.length).toBeGreaterThan(0)
    await ctx.dispose()
  })

  test('GET /mondu/webhooks is not allowed (POST only)', async () => {
    const ctx = await playwrightRequest.newContext()
    const res = await ctx.get(`${SHOP_URL}/mondu/webhooks`)
    expect(res.status()).toBeGreaterThanOrEqual(400)
    await ctx.dispose()
  })

  test('POST /mondu/webhooks with various invalid topics still requires auth', async () => {
    const ctx = await playwrightRequest.newContext()
    const topics = ['order/shipped', 'invoice/created', 'unknown_topic', '../../../etc/passwd']
    for (const topic of topics) {
      const res = await ctx.post(`${SHOP_URL}/mondu/webhooks`, {
        data: { topic, order_uuid: 'test-uuid' },
        headers: { 'Content-Type': 'application/json' },
      })
      expect(res.status()).toBe(401)
    }
    await ctx.dispose()
  })
})
