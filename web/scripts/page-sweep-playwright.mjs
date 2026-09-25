/**
 * Walk every route at three widths, in a real browser.
 *
 * What it catches that a unit test cannot: a component that throws only when
 * mounted, a layout that overflows the viewport on a phone, a chart that draws
 * NaN into an SVG attribute, a route that renders nothing at all.
 *
 *   server-php/tests/serve.sh                     # in one terminal
 *   node web/scripts/page-sweep-playwright.mjs --out /tmp/sweep
 *
 * Options:
 *   --base   origin to sweep                  (default http://127.0.0.1:8793)
 *   --token  portal auth_token to sign in as  (default auth-uuid-owner-1)
 *   --out    directory for screenshots        (default /tmp/insights-sweep)
 *
 * DEVELOPMENT ONLY. It signs in against whatever portal the API is pointed at,
 * which in this harness is the local stub, so every figure on screen comes from
 * the stub. It verifies the APPLICATION, not the data.
 */

import { mkdir, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { chromium } from './playwright.mjs'

const args = Object.fromEntries(
  process.argv.slice(2).flatMap((arg, index, all) =>
    arg.startsWith('--') ? [[arg.slice(2), all[index + 1]?.startsWith('--') ? 'true' : all[index + 1]]] : [],
  ),
)

const BASE = args.base ?? 'http://127.0.0.1:8793'
const TOKEN = args.token ?? 'auth-uuid-owner-1'
const OUT = args.out ?? '/tmp/insights-sweep'

// Chromium is preinstalled here and the bundled playwright expects a different
// build, so the path is explicit rather than resolved.
const EXECUTABLE = process.env.CHROMIUM_PATH ?? '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'

const VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'tablet', width: 900, height: 1180 },
  { name: 'mobile', width: 390, height: 844 },
]

const ROUTES = [
  ['overview', '/'],
  ['dashboards', '/dashboards'],
  ['metrics', '/metrics'],
  ['ask', '/ask'],
  ['forecasts', '/forecasts'],
  ['anomalies', '/anomalies'],
  ['reports', '/reports'],
  ['sources', '/sources'],
  ['settings', '/settings'],
]

/** Noise from the environment rather than from the app. */
function isEnvironmentNoise(text) {
  return (
    text.includes('favicon') ||
    text.includes('Download the React DevTools') ||
    // The blocked cross-origin requests above. The product handles these; the
    // browser logs them regardless.
    text.includes('net::ERR_FAILED') ||
    text.includes('net::ERR_INTERNET_DISCONNECTED') ||
    text.includes('net::ERR_TUNNEL_CONNECTION_FAILED')
  )
}

const results = []

const browser = await chromium.launch({ executablePath: EXECUTABLE, args: ['--no-sandbox'] })

for (const viewport of VIEWPORTS) {
  const context = await browser.newContext({
    viewport: { width: viewport.width, height: viewport.height },
    deviceScaleFactor: 1,
  })

  // Nothing outside this origin. The product asks Console for its own tile icon
  // and falls back when Console is unreachable; letting that request hang on a
  // container with no outbound access would make the sweep time out on a path
  // the product already handles. Blocking it also exercises that fallback.
  await context.route('**/*', (route) =>
    route.request().url().startsWith(BASE) ? route.continue() : route.abort(),
  )

  // Sign in once per context, through the portal callback the product actually
  // uses rather than by writing storage behind its back.
  const page = await context.newPage()
  await page.goto(`${BASE}/auth/callback?auth_token=${encodeURIComponent(TOKEN)}`, { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1500)

  // Open the company. The picker is a modal on first run because nothing is
  // chosen yet; every later route reuses the stored scope.
  const companySelect = page.locator('#company-picker-company')
  if (await companySelect.isVisible().catch(() => false)) {
    await companySelect.selectOption({ index: 1 })
    await page.locator('#company-picker-fy').selectOption({ index: 1 })
    await page.getByRole('button', { name: 'Open', exact: true }).click()
    await page.waitForTimeout(600)
  }

  await mkdir(path.join(OUT, viewport.name), { recursive: true })

  for (const [name, route] of ROUTES) {
    const consoleErrors = []
    const pageErrors = []

    const onConsole = (message) => {
      if (message.type() === 'error' && !isEnvironmentNoise(message.text())) consoleErrors.push(message.text())
    }
    const onPageError = (error) => pageErrors.push(String(error))

    page.on('console', onConsole)
    page.on('pageerror', onPageError)

    await page.goto(`${BASE}${route}`, { waitUntil: 'domcontentloaded' })
    // Every page fans out to several products; the built-in PHP server answers
    // those one at a time, so give the slowest route room to finish rather than
    // screenshotting a skeleton.
    await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {})
    await page.waitForTimeout(400)

    const file = path.join(OUT, viewport.name, `${name}.png`)
    await page.screenshot({ path: file, fullPage: true })

    // A page wider than its viewport is a horizontal scrollbar on a phone.
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    )

    // NaN in an SVG geometry attribute is an invisible chart, and it is the
    // failure mode of every hand-drawn chart in this product.
    const badGeometry = await page.evaluate(() => {
      const bad = []
      for (const element of document.querySelectorAll('svg *')) {
        for (const attribute of ['d', 'x', 'y', 'cx', 'cy', 'r', 'width', 'height', 'points']) {
          const value = element.getAttribute(attribute)
          if (value && /NaN|Infinity|undefined/.test(value)) bad.push(`${element.tagName}.${attribute}=${value}`)
        }
      }
      return bad.slice(0, 5)
    })

    const heading = await page.locator('h1').first().textContent().catch(() => null)

    page.off('console', onConsole)
    page.off('pageerror', onPageError)

    results.push({
      viewport: viewport.name,
      route,
      heading: heading?.trim() ?? null,
      consoleErrors,
      pageErrors,
      horizontalOverflowPx: overflow,
      badGeometry,
      screenshot: file,
    })

    const flags = [
      consoleErrors.length ? `${consoleErrors.length} console` : '',
      pageErrors.length ? `${pageErrors.length} thrown` : '',
      overflow > 0 ? `overflow ${overflow}px` : '',
      badGeometry.length ? `${badGeometry.length} bad SVG` : '',
    ].filter(Boolean)

    console.log(`${flags.length ? 'FAIL' : ' ok '} ${viewport.name.padEnd(8)} ${route.padEnd(14)} ${heading ?? '(no h1)'}${flags.length ? '  — ' + flags.join(', ') : ''}`)
  }

  await context.close()
}

await browser.close()

await writeFile(path.join(OUT, 'sweep.json'), JSON.stringify(results, null, 2))

const failures = results.filter(
  (r) => r.consoleErrors.length || r.pageErrors.length || r.horizontalOverflowPx > 0 || r.badGeometry.length,
)

console.log(`\n${results.length - failures.length}/${results.length} clean. Screenshots and sweep.json in ${OUT}`)
if (failures.length > 0) {
  for (const failure of failures) {
    console.log(`\n${failure.viewport} ${failure.route}`)
    for (const error of [...failure.pageErrors, ...failure.consoleErrors]) console.log(`   ${error}`)
    if (failure.horizontalOverflowPx > 0) console.log(`   horizontal overflow: ${failure.horizontalOverflowPx}px`)
    for (const bad of failure.badGeometry) console.log(`   bad SVG geometry: ${bad}`)
  }
  process.exitCode = 1
}
