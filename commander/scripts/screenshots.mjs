// Headless screenshot capture of every Commander screen.
// Uses playwright-core driving the system google-chrome against the running
// commander-web container (https://commander.snakepc.test:8443). Writes PNGs to
// <repo>/.screenshots. Run: npm run screenshots
import { chromium } from 'playwright-core'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { mkdirSync, rmSync, readdirSync } from 'node:fs'

const __dirname = dirname(fileURLToPath(import.meta.url))
const OUT = resolve(__dirname, '../../.screenshots')
const BASE = process.env.COMMANDER_URL || 'https://commander.snakepc.test:8443'
const EMAIL = process.env.ADMIN_EMAIL || 'admin@snake.com'
const PASS = process.env.ADMIN_PASSWORD || 'secret1234'
const CHROME = process.env.CHROME_BIN || '/usr/bin/google-chrome'
const PKG = process.env.PKG || 'Crm' // provisioned package used for detail tabs

// route -> filename (no extension). Captured logged-in unless noted.
const ROUTES = [
  ['/dashboard', '01-dashboard'],
  ['/clients', '02-clients'],
  ['/packages', '03-packages'],
  [`/packages/${PKG}/overview`, '04-package-overview'],
  [`/packages/${PKG}/plans`, '05-package-plans'],
  [`/packages/${PKG}/quotas`, '06-package-quotas'],
  [`/packages/${PKG}/usage`, '07-package-usage'],
  [`/packages/${PKG}/logs`, '08-package-logs'],
  [`/packages/${PKG}/settings`, '09-package-settings'],
  ['/invoices', '10-invoices'],
  ['/usage', '11-usage'],
  ['/request-logs', '12-request-logs'],
  ['/settings', '13-settings'],
]
// also capture these in light theme (g10)
const G10 = new Set(['01-dashboard', '03-packages', '04-package-overview'])

const sleep = (ms) => new Promise((r) => setTimeout(r, ms))

async function settle(page, ms = 900) {
  try {
    await page.waitForLoadState('networkidle', { timeout: 8000 })
  } catch {}
  await sleep(ms)
}

async function main() {
  // fresh output dir
  try {
    for (const f of readdirSync(OUT)) {
      if (f.endsWith('.png')) rmSync(resolve(OUT, f))
    }
  } catch {}
  mkdirSync(OUT, { recursive: true })

  const browser = await chromium.launch({
    executablePath: CHROME,
    headless: true,
    args: [
      '--host-resolver-rules=MAP commander.snakepc.test 127.0.0.1',
      '--no-sandbox',
      '--force-color-profile=srgb',
    ],
  })
  const context = await browser.newContext({
    ignoreHTTPSErrors: true,
    viewport: { width: 1440, height: 1024 },
    deviceScaleFactor: 2,
  })
  const page = await context.newPage()

  // ---- login page (logged out) ----
  await page.goto(`${BASE}/login`, { waitUntil: 'domcontentloaded' })
  await settle(page, 600)
  await page.screenshot({ path: resolve(OUT, '00-login.png') })

  // ---- authenticate via the form ----
  await page.fill('#login-email', EMAIL)
  await page.fill('#login-password', PASS)
  await Promise.all([
    page.click('button[type="submit"]'),
    page.waitForResponse((r) => r.url().includes('/auth/login'), { timeout: 15000 }).catch(() => {}),
  ])
  await sleep(1500)
  const token = await page.evaluate(() => localStorage.getItem('commander_token'))
  if (!token) throw new Error('login failed: no commander_token in localStorage')
  console.log('authenticated, token present')

  for (const theme of ['g100', 'g10']) {
    await page.evaluate((t) => localStorage.setItem('commander.theme', t), theme)
    for (const [route, name] of ROUTES) {
      if (theme === 'g10' && !G10.has(name)) continue
      const file = theme === 'g10' ? `${name}-g10.png` : `${name}.png`
      await page.goto(`${BASE}${route}`, { waitUntil: 'domcontentloaded' })
      const charty = /usage|dashboard/.test(route)
      await settle(page, charty ? 1600 : 800)
      await page.screenshot({ path: resolve(OUT, file), fullPage: true })
      console.log('captured', file)

      // clients: also open the row drawer (g100 only)
      if (name === '02-clients' && theme === 'g100') {
        try {
          await page.click('table tbody tr:first-child', { timeout: 3000 })
          await sleep(700)
          await page.screenshot({ path: resolve(OUT, '02b-clients-drawer.png') })
          console.log('captured 02b-clients-drawer.png')
          await page.keyboard.press('Escape')
          await sleep(300)
        } catch (e) {
          console.log('drawer capture skipped:', e.message)
        }
      }
    }
  }

  await browser.close()
  console.log('done ->', OUT)
}

main().catch((e) => {
  console.error(e)
  process.exit(1)
})
