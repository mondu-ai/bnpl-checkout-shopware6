import { Page } from '@playwright/test'
import { SW_ADMIN_USER, SW_ADMIN_PASS } from './config'

export async function loginToAdmin(page: Page, baseURL: string): Promise<void> {
  await page.goto(`${baseURL}/admin#/login`)
  await page.waitForTimeout(3000)

  const usernameField = page.locator('#sw-field--username')
  await usernameField.waitFor({ state: 'visible', timeout: 30_000 })
  await usernameField.fill(SW_ADMIN_USER)

  const passwordField = page.locator('#sw-field--password')
  await passwordField.fill(SW_ADMIN_PASS)

  await page.locator('.sw-login__submit button, button.sw-button').first().click()

  await page.waitForTimeout(5000)

  // Verify we're past the login screen
  await page.waitForFunction(
    () => !document.querySelector('.sw-login') || window.location.hash.includes('dashboard'),
    { timeout: 30_000 }
  ).catch(() => {})

  await dismissModals(page)
}

async function dismissModals(page: Page): Promise<void> {
  // "Domain change detected" dialog — click Cancel to skip migration
  const domainCancelBtn = page.locator('.sw-modal__footer button:has-text("Cancel")')
  if (await domainCancelBtn.first().isVisible({ timeout: 2000 }).catch(() => false)) {
    await domainCancelBtn.first().click()
    await page.waitForTimeout(1000)
  }

  // "A new Shopware version is available" notification — click Cancel
  const updateCancelBtn = page.locator('.sw-alert__close, button:has-text("Cancel")')
  if (await updateCancelBtn.first().isVisible({ timeout: 1000 }).catch(() => false)) {
    await updateCancelBtn.first().click()
    await page.waitForTimeout(500)
  }

  // Any remaining modals — close via X button
  const closeBtn = page.locator('.sw-modal__close')
  if (await closeBtn.first().isVisible({ timeout: 1000 }).catch(() => false)) {
    await closeBtn.first().click()
    await page.waitForTimeout(500)
  }

  await page.waitForTimeout(1000)
}

export async function navigateToMonduConfig(page: Page, baseURL: string): Promise<void> {
  await page.goto(`${baseURL}/admin#/sw/extension/config/Mond1SW6`)
  await page.waitForTimeout(5000)
  await dismissModals(page)
}

export async function navigateToPaymentMethods(page: Page, baseURL: string): Promise<void> {
  await page.goto(`${baseURL}/admin#/sw/settings/payment/overview`)
  await page.waitForTimeout(5000)
  await dismissModals(page)
}

export async function navigateToOrders(page: Page, baseURL: string): Promise<void> {
  await page.goto(`${baseURL}/admin#/sw/order/index`)
  await page.waitForTimeout(5000)
  await dismissModals(page)
}

export async function getAdminApiToken(baseURL: string, page: Page): Promise<string> {
  const response = await page.request.post(`${baseURL}/api/oauth/token`, {
    data: {
      grant_type: 'password',
      client_id: 'administration',
      username: SW_ADMIN_USER,
      password: SW_ADMIN_PASS,
    },
  })

  const body = await response.json()
  return body.access_token
}
