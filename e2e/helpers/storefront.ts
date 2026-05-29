import { Page } from '@playwright/test'

export async function addProductToCart(page: Page): Promise<void> {
  const baseURL = new URL(page.url()).origin

  await page.goto(baseURL)
  await page.waitForTimeout(3000)

  // Homepage may not show products directly — navigate to a category first
  let productLink = page.locator('.product-box .product-name, .product-image-link, .product-info a').first()
  if (!await productLink.isVisible({ timeout: 3000 }).catch(() => false)) {
    // Skip the first nav link (home) and click a category
    const categoryLink = page.locator('.main-navigation-link:not(.home-link)').first()
    if (await categoryLink.isVisible({ timeout: 3000 }).catch(() => false)) {
      await categoryLink.click()
      await page.waitForTimeout(3000)
      productLink = page.locator('.product-box .product-name, .product-image-link, .product-info a').first()
    }
  }

  await productLink.waitFor({ state: 'visible', timeout: 15_000 })
  await productLink.click()
  await page.waitForTimeout(3000)

  const addToCartBtn = page.locator('button.btn-buy[type="submit"]')
  await addToCartBtn.waitFor({ state: 'visible', timeout: 10_000 })
  await addToCartBtn.click()

  await page.waitForTimeout(2000)
}

export async function goToCheckout(page: Page): Promise<void> {
  const baseURL = new URL(page.url()).origin
  await page.goto(`${baseURL}/checkout/confirm`)
  await page.waitForTimeout(3000)
}

export async function registerB2BCustomer(page: Page, baseURL?: string): Promise<void> {
  if (!baseURL) baseURL = new URL(page.url()).origin
  const timestamp = Date.now()

  await page.goto(`${baseURL}/account/login`)
  await page.waitForTimeout(3000)

  const registerForm = page.locator('.register-form, form[action*="register"]').first()
  if (!await registerForm.isVisible({ timeout: 3000 }).catch(() => false)) {
    await page.goto(`${baseURL}/account/register`)
    await page.waitForTimeout(3000)
  }

  // SW6.6: #accountType, SW6.7: #-accountType
  const accountTypeSelect = page.locator('#accountType, #-accountType, select[name="accountType"]').first()
  if (await accountTypeSelect.isVisible({ timeout: 3000 }).catch(() => false)) {
    await accountTypeSelect.selectOption('business')
    await page.waitForTimeout(500)
  }

  const salutation = registerForm.locator('#personalSalutation, select[name="salutationId"]').first()
  if (await salutation.isVisible({ timeout: 3000 }).catch(() => false)) {
    await salutation.selectOption({ index: 1 })
  }

  // SW6.6: #personalFirstName, SW6.7: #billingAddress-personalFirstName
  await registerForm.locator('#personalFirstName, #billingAddress-personalFirstName').first().fill('Test')
  await registerForm.locator('#personalLastName, #billingAddress-personalLastName').first().fill('User')

  const companyField = registerForm.locator('#billingAddresscompany, #personalCompany, input[name="billingAddress\\[company\\]"]').first()
  if (await companyField.isVisible({ timeout: 2000 }).catch(() => false)) {
    await companyField.fill('Test GmbH')
  }

  await registerForm.locator('#personalMail').fill(`test+${timestamp}@example.com`)
  await registerForm.locator('#personalPassword').fill('Mondu123!')

  // SW6.6: #billingAddressAddressStreet, SW6.7: #billingAddress-AddressStreet
  await registerForm.locator('#billingAddressAddressStreet, #billingAddress-AddressStreet').first().fill('Teststraße 1')
  await registerForm.locator('#billingAddressAddressZipcode').fill('10115')
  await registerForm.locator('#billingAddressAddressCity').fill('Berlin')

  const countrySelect = registerForm.locator('#billingAddressAddressCountry')
  const germanyOption = countrySelect.locator('option:has-text("Germany"), option:has-text("Deutschland")')
  if (await germanyOption.count() > 0) {
    const value = await germanyOption.first().getAttribute('value')
    if (value) await countrySelect.selectOption(value)
  }

  await registerForm.locator('button[type="submit"]').click()
  await page.waitForTimeout(5000)
}
