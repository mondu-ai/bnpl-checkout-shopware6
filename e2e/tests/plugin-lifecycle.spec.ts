import { test, expect } from '@playwright/test'
import { execSync } from 'child_process'
import { getContainerName } from '../helpers/config'

function runConsole(container: string, command: string, timeout = 60_000): string {
  return execSync(
    `docker exec ${container} php /var/www/html/bin/console ${command} 2>&1`,
    { encoding: 'utf-8', timeout }
  )
}

test.describe('Plugin lifecycle', () => {
  test('Plugin deactivate and reactivate works', async ({}, testInfo) => {
    const container = getContainerName(testInfo.project.name)

    const deactivate = runConsole(container, 'plugin:deactivate Mond1SW6')
    expect(deactivate.toLowerCase()).not.toContain('error')

    const listAfterDeactivate = runConsole(container, 'plugin:list --json')
    const pluginsDeactivated = JSON.parse(listAfterDeactivate)
    const monduDeactivated = pluginsDeactivated.find((p: any) => p.name === 'Mond1SW6')
    expect(monduDeactivated.active).toBe(false)

    const activate = runConsole(container, 'plugin:activate Mond1SW6')
    expect(activate.toLowerCase()).not.toContain('error')

    const listAfterActivate = runConsole(container, 'plugin:list --json')
    const pluginsActivated = JSON.parse(listAfterActivate)
    const monduActivated = pluginsActivated.find((p: any) => p.name === 'Mond1SW6')
    expect(monduActivated.active).toBe(true)

    runConsole(container, 'cache:clear')
  })

  test('Mondu database tables exist', async ({}, testInfo) => {
    const container = getContainerName(testInfo.project.name)

    const output = execSync(
      `docker exec ${container} php /var/www/html/bin/console debug:container --parameter=kernel.project_dir 2>&1`,
      { encoding: 'utf-8', timeout: 30_000 }
    ).trim()

    const tablesOutput = execSync(
      `docker exec ${container} php -r "
        require '/var/www/html/vendor/autoload.php';
        \\$env = parse_ini_file('/var/www/html/.env');
        \\$dsn = \\$env['DATABASE_URL'];
        preg_match('/mysql:\\/\\/([^:]+):([^@]+)@([^:]+):(\\d+)\\/(.+)/', \\$dsn, \\$m);
        \\$pdo = new PDO('mysql:host='.\\$m[3].';port='.\\$m[4].';dbname='.\\$m[5], \\$m[1], \\$m[2]);
        \\$stmt = \\$pdo->query('SHOW TABLES LIKE \\'mondu%\\'');
        while(\\$row = \\$stmt->fetch(PDO::FETCH_NUM)) echo \\$row[0].PHP_EOL;
      " 2>&1`,
      { encoding: 'utf-8', timeout: 30_000 }
    )

    expect(tablesOutput).toContain('mondu_order_data')
    expect(tablesOutput).toContain('mondu_invoice_data')
  })

  test('Theme compile succeeds after plugin activation', async ({}, testInfo) => {
    const container = getContainerName(testInfo.project.name)
    const output = runConsole(container, 'theme:compile', 120_000)
    expect(output.toLowerCase()).not.toContain('fatal')
  })
})
