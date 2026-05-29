import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'
import { getContainerName } from '../helpers/config'

function runConsole(container: string, command: string, timeout = 60_000): string {
  return execSync(
    `docker exec ${container} php /var/www/html/bin/console ${command} 2>&1`,
    { encoding: 'utf-8', timeout }
  )
}

test.describe('CLI commands', () => {
  test('Mond1SW6:Config:ApiToken command exists', async ({}, testInfo) => {
    const container = getContainerName(testInfo.project.name)
    const output = runConsole(container, 'list Mond1SW6')
    expect(output).toContain('Mond1SW6:Config:ApiToken')
  })

  test('Mond1SW6:Activate:Payment command exists', async ({}, testInfo) => {
    const container = getContainerName(testInfo.project.name)
    const output = runConsole(container, 'list Mond1SW6')
    expect(output).toContain('Mond1SW6:Activate:Payment')
  })

  test('mondu:check-flow-aware command exists', async ({}, testInfo) => {
    const container = getContainerName(testInfo.project.name)
    const output = runConsole(container, 'list mondu')
    expect(output).toContain('mondu:check-flow-aware')
  })

  test('Mond1SW6:Test --help runs without fatal errors', async ({}, testInfo) => {
    const container = getContainerName(testInfo.project.name)
    const output = runConsole(container, 'Mond1SW6:Test --help')
    expect(output.toLowerCase()).not.toContain('fatal')
    expect(output).toContain('Mond1SW6:Test')
  })

  test('Scheduled tasks are registered', async ({}, testInfo) => {
    const container = getContainerName(testInfo.project.name)
    const output = runConsole(container, 'scheduled-task:list')
    expect(output.toLowerCase()).not.toContain('error')
  })

  test('dal:refresh:index runs successfully', async ({}, testInfo) => {
    const container = getContainerName(testInfo.project.name)
    const output = runConsole(container, 'dal:refresh:index', 120_000)
    expect(output.toLowerCase()).not.toContain('fatal')
  })
})
