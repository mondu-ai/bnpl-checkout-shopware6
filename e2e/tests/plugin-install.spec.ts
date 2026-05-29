import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'
import { getContainerName } from '../helpers/config'

test.describe('Plugin installation', () => {
  test('Mond1SW6 plugin is installed and active', async ({}, testInfo) => {
    const container = getContainerName(testInfo.project.name)

    const output = execSync(
      `docker exec ${container} php /var/www/html/bin/console plugin:list --json`,
      { encoding: 'utf-8', timeout: 30_000 }
    )

    const plugins = JSON.parse(output)
    const mondu = plugins.find((p: any) => p.name === 'Mond1SW6')

    expect(mondu, 'Mond1SW6 plugin should be present').toBeTruthy()
    expect(mondu.active, 'Mond1SW6 should be active').toBe(true)
    expect(mondu.installedAt, 'Mond1SW6 should have an install date').toBeTruthy()
  })

  test('No errors in plugin:list output', async ({}, testInfo) => {
    const container = getContainerName(testInfo.project.name)

    const output = execSync(
      `docker exec ${container} php /var/www/html/bin/console plugin:list 2>&1`,
      { encoding: 'utf-8', timeout: 30_000 }
    )

    expect(output.toLowerCase()).not.toContain('error')
    expect(output.toLowerCase()).not.toContain('exception')
  })

  test('Cache clear runs without errors', async ({}, testInfo) => {
    const container = getContainerName(testInfo.project.name)

    const output = execSync(
      `docker exec ${container} php /var/www/html/bin/console cache:clear 2>&1`,
      { encoding: 'utf-8', timeout: 60_000 }
    )

    expect(output.toLowerCase()).not.toContain('fatal')
    expect(output.toLowerCase()).not.toContain('exception')
  })
})
