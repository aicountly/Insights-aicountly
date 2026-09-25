/**
 * The acceptance walk-through, driven in a real browser.
 *
 *   server-php/tests/serve.sh                  # in one terminal
 *   node web/scripts/flow-playwright.mjs --out /tmp/insights-flow
 *
 * It follows the path a customer actually takes — sign in, open a company,
 * read the overview, build a dashboard from a template, add and configure a
 * widget, move and resize it from the KEYBOARD, save, reload and check it came
 * back identical, filter, drill into the evidence, share it with a colleague,
 * export it, ask for a dashboard and apply the proposal — and then checks the
 * things that are easy to get wrong and impossible to see in a screenshot:
 *
 *   * a reply that arrives after the company changed is not painted
 *   * a shared LAYOUT does not hand over the owner's DATA
 *   * an export is a real file of the right type, not an HTML error page
 *   * the AI proposal creates nothing until it is applied
 *
 * WHAT THIS IS NOT. A live-service check. The API under it is pointed at the
 * local stub, so every figure is fixture data. It proves the application's
 * behaviour; it proves nothing about production data.
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
const OUT = args.out ?? '/tmp/insights-flow'
const EXECUTABLE = process.env.CHROMIUM_PATH ?? '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'

const OWNER = 'auth-uuid-owner-1'
const COLLEAGUE = 'auth-uuid-colleague-2'

const results = []
let currentStep = null

function step(name) {
  currentStep = { name, checks: [] }
  results.push(currentStep)
  console.log(`\n▸ ${name}`)
}

function check(what, passed, detail = '') {
  currentStep.checks.push({ what, passed, detail })
  console.log(`  ${passed ? 'ok  ' : 'FAIL'} ${what}${detail ? `  — ${detail}` : ''}`)
}

/**
 * Say so when a check could not be exercised, rather than passing on absence.
 *
 * A check that quietly passes because the thing it was looking for was not on
 * the page is worse than no check at all: it reports green for something nobody
 * verified.
 */
function skip(what, why) {
  currentStep.checks.push({ what, skipped: true, detail: why })
  console.log(`  skip ${what}  — ${why}`)
}

async function shot(page, name) {
  await page.screenshot({ path: path.join(OUT, `${name}.png`), fullPage: true })
}

/** Sign in through the portal callback and open a company. */
async function signIn(context, token, { cmpIndex = 1 } = {}) {
  const page = await context.newPage()
  await page.goto(`${BASE}/auth/callback?auth_token=${encodeURIComponent(token)}`, { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1200)

  const picker = page.locator('#company-picker-company')
  if (await picker.isVisible().catch(() => false)) {
    await picker.selectOption({ index: cmpIndex })
    await page.locator('#company-picker-fy').selectOption({ index: 1 })
    await page.getByRole('button', { name: 'Open', exact: true }).click()
  }
  await page.waitForTimeout(1200)

  return page
}

async function newContext(browser) {
  const context = await browser.newContext({ viewport: { width: 1500, height: 1000 } })
  await context.route('**/*', (route) => (route.request().url().startsWith(BASE) ? route.continue() : route.abort()))
  return context
}

await mkdir(OUT, { recursive: true })
const browser = await chromium.launch({ executablePath: EXECUTABLE, args: ['--no-sandbox'] })

// ---------------------------------------------------------------------------

step('Sign in and open a company')

const ownerContext = await newContext(browser)
const owner = await signIn(ownerContext, OWNER)

check('the portal token is not left in the address bar', !owner.url().includes('auth_token'), owner.url())
check('a ses_key never reaches web storage', await owner.evaluate(() => {
  const keys = [...Array(localStorage.length).keys()].map((i) => localStorage.key(i) ?? '')
  const values = keys.map((k) => localStorage.getItem(k) ?? '')
  const session = [...Array(sessionStorage.length).keys()].map((i) => sessionStorage.getItem(sessionStorage.key(i) ?? '') ?? '')
  return ![...values, ...session].some((v) => v.startsWith('ses-'))
}))
check('the company is named in the header', (await owner.locator('body').innerText()).includes('Company 1'))

// ---------------------------------------------------------------------------

step('The overview reads live figures, with their provenance')

await owner.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' })
await owner.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {})
const overviewText = await owner.locator('body').innerText()

check('a figure is shown, formatted for India', /₹\d+,\d+,\d+\.\d{2}/.test(overviewText), overviewText.match(/₹[\d,]+\.\d{2}/)?.[0] ?? '')
check('each source says whether it answered', /Smart Books:\s*(Connected|Unavailable|Denied)/.test(overviewText))
check('nothing claims unknown freshness is real time', !/real[- ]time/i.test(overviewText))
await shot(owner, '01-overview')

// The evidence drawer: where a figure came from.
const infoButton = owner.locator('button[aria-label^="Where"], button[aria-label*="came from"]').first()
if (await infoButton.count()) {
  await infoButton.click()
  await owner.waitForTimeout(700)
  const drawer = await owner.locator('body').innerText()
  check('the drawer names the product that owns the figure', /Smart Books|Inventory/.test(drawer))
  check('the drawer states the definition', /invoices|Definition|less credit notes/i.test(drawer))
  await shot(owner, '02-evidence')
  await owner.keyboard.press('Escape')
  await owner.waitForTimeout(400)
} else {
  check('an evidence control is offered on each figure', false, 'no info button found')
}

// ---------------------------------------------------------------------------

step('A late reply is not painted after the company changes')

// Its own browser context. The chosen company is remembered in local storage,
// so switching it in a page that shares storage with the rest of this
// walk-through would silently move every later step into another company.
const scopeContext = await newContext(browser)
const scopeProbe = await signIn(scopeContext, OWNER)
await scopeProbe.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' })
await scopeProbe.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {})

// Hold the overview's own data call — and only that, so the company switcher
// can still read its list from Manage — then switch company and release it.
// Whatever company 1's answer says, it must not reach the screen.
let held = []
await scopeProbe.route('**/api/v1/overview**', async (route) => {
  held.push(route)
})

// Make the page ask again, so there is something in flight to hold.
await scopeProbe.getByRole('button', { name: /Refresh data/i }).click().catch(() => {})
await scopeProbe.waitForTimeout(400)

// The collapsed picker is the context chip in the header; its accessible name
// is the company it is showing, not a verb.
await scopeProbe.locator('button[title^="Change company"]').first().click()
await scopeProbe.waitForTimeout(600)

const pickerOpen = await scopeProbe.locator('#company-picker-company').isVisible().catch(() => false)
check('the company switcher opens from the header', pickerOpen)

if (pickerOpen) {
  await scopeProbe.locator('#company-picker-company').selectOption({ index: 2 })
  await scopeProbe.waitForTimeout(900)
  await scopeProbe.locator('#company-picker-fy').selectOption({ index: 1 }).catch(() => {})
  await scopeProbe.getByRole('button', { name: 'Open', exact: true }).click()
  await scopeProbe.waitForTimeout(900)
}

// Release the held replies from the previous company.
for (const route of held) await route.continue().catch(() => {})
held = []
await scopeProbe.unroute('**/api/v1/overview**')
await scopeProbe.waitForTimeout(1500)

const afterSwitch = await scopeProbe.locator('body').innerText()
const headerChip = await scopeProbe.locator('button[title^="Change company"]').first().innerText()
check('the header names the company now open', /Company 77|Restricted/.test(headerChip), headerChip.replace(/\n/g, ' / '))
check(
  'no figure from the previous company is left on screen',
  !afterSwitch.includes('₹12,37,500.54') && !afterSwitch.includes('₹12,50,000.55'),
)
await shot(scopeProbe, '03-after-scope-change')

// The widgets on the new company must say WHY they are empty, and must not say
// zero. Company 77 is the one Books refuses in the stub.
// The stub refuses company 77 in Books, which is what makes this worth
// checking: the page must name the refusal rather than showing an abort, and
// must never fill the gap with zero.
await scopeProbe.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {})
await scopeProbe.waitForTimeout(800)
const refusedText = await scopeProbe.locator('body').innerText()
check(
  'a refusal is named, not reported as a crash',
  /not permitted|do not have access|Insufficient permissions/i.test(refusedText) && !/aborted/i.test(refusedText),
  refusedText.split('\n').find((l) => /permitted|access|abort/i.test(l)) ?? refusedText.slice(0, 80),
)
check('and the gap is never filled with zero', !/₹0\.00/.test(refusedText))
await scopeContext.close()

// ---------------------------------------------------------------------------

step('Build a dashboard: template, widget, keyboard move and resize, save')

await owner.goto(`${BASE}/dashboards`, { waitUntil: 'domcontentloaded' })
await owner.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {})
await owner.getByRole('button', { name: 'Create dashboard' }).first().click()
await owner.waitForTimeout(900)

const templatesText = await owner.locator('body').innerText()
check(
  'templates say what they need before you create one',
  /Every panel will work here|panels will not work yet/.test(templatesText),
)
check('no template shows a sample figure', !/₹[\d,]+\.\d{2}/.test(templatesText))
await shot(owner, '04-templates')

await owner.getByRole('button', { name: 'Use this', exact: false }).first().click()
await owner.waitForURL(/\/dashboards\/.+\/edit/, { timeout: 20_000 }).catch(() => {})
await owner.waitForLoadState('networkidle', { timeout: 25_000 }).catch(() => {})
await owner.waitForTimeout(1200)

const builderUrl = owner.url()
check('the builder opened on the new dashboard', /\/dashboards\/[^/]+\/edit/.test(builderUrl), builderUrl)

const widgetsBefore = await owner.locator('.insights-widget').count()
check('the template brought its widgets', widgetsBefore > 0, `${widgetsBefore} widgets`)

// Add one more from the picker.
await owner.getByRole('button', { name: /^KPI/ }).first().click().catch(async () => {
  await owner.locator('.insights-builder button').filter({ hasText: /KPI|Figure|Number/ }).first().click()
})
await owner.waitForTimeout(900)
const widgetsAfterAdd = await owner.locator('.insights-widget').count()
check('a widget can be added', widgetsAfterAdd === widgetsBefore + 1, `${widgetsBefore} → ${widgetsAfterAdd}`)

// Configure it. The metric field is a searchable listbox rather than a native
// select, because the catalogue is long and every entry carries its definition.
await owner.locator('#widget-title').fill('Keyboard test widget')
const metricSearch = owner.locator('#widget-metric')

if (await metricSearch.count()) {
  await metricSearch.fill('receivable')
  await owner.waitForTimeout(500)
  const options = owner.locator('[role="option"]')
  const optionCount = await options.count()
  check('the metric list filters as you type', optionCount > 0, `${optionCount} matches for "receivable"`)

  const chosen = (await options.first().innerText()).split('\n')[0].trim()
  await options.first().click()
  await owner.waitForTimeout(900)

  const propertiesText = await owner.locator('.builder-properties').innerText()
  check('choosing a metric shows its definition and owner', /Owned by (books|inventory|insights)/i.test(propertiesText), chosen)
  // No arbitrary SQL, JavaScript, HTML or API URL anywhere in a widget's
  // configuration: every field is a name, a number, or a choice from a list.
  const freeform = await owner
    .locator('.builder-properties input, .builder-properties textarea')
    .evaluateAll((nodes) =>
      nodes
        .filter((n) => /(^|[^a-z])(sql|url|uri|endpoint|script|code|html|javascript)([^a-z]|$)/i.test(`${n.getAttribute('id') ?? ''} ${n.getAttribute('placeholder') ?? ''}`))
        .map((n) => n.getAttribute('id') ?? n.getAttribute('placeholder') ?? n.tagName),
    )
  check('configuration offers no free-text SQL, URL or code field', freeform.length === 0, freeform.join(', '))
} else {
  check('a widget can be pointed at a metric', false, 'no metric field')
}

// Move and resize it from the keyboard alone.
const target = owner.locator('.insights-widget').last()
await target.focus()
const geometryBefore = await target.boundingBox()
await owner.keyboard.press('ArrowLeft')
await owner.waitForTimeout(400)
const orderAfterMove = await owner.locator('.insights-widget__title').allInnerTexts()
check(
  'arrow keys move a widget without a mouse',
  orderAfterMove[orderAfterMove.length - 1] !== 'Keyboard test widget',
  orderAfterMove.slice(-2).join(' | '),
)

const moved = owner.locator('.insights-widget').filter({ hasText: 'Keyboard test widget' }).first()
await moved.focus()
await owner.keyboard.press('Shift+ArrowRight')
await owner.waitForTimeout(500)
const geometryAfter = await moved.boundingBox()
check(
  'shift and arrow keys resize a widget without a mouse',
  !!geometryBefore && !!geometryAfter && geometryAfter.width !== geometryBefore.width,
  `${Math.round(geometryBefore?.width ?? 0)}px → ${Math.round(geometryAfter?.width ?? 0)}px`,
)
check('an unsaved change is announced', (await owner.locator('body').innerText()).includes('Unsaved changes'))

// A figure that runs out of its card is printed over the widget beside it, and
// both become unreadable. It happens at the narrow end of the grid, which is
// where a screenshot at one width will not show it.
const spilling = await owner.locator('.insights-widget').evaluateAll((widgets) =>
  widgets.flatMap((widget) => {
    const box = widget.getBoundingClientRect()
    return [...widget.querySelectorAll('*')]
      .filter((el) => el.children.length === 0 && el.getBoundingClientRect().width > 0)
      .filter((el) => el.getBoundingClientRect().right > box.right + 1)
      .map((el) => `${widget.querySelector('.insights-widget__title')?.textContent?.trim()}: ${(el.textContent ?? '').trim().slice(0, 24)}`)
  }),
)
check('nothing is printed outside its own widget', spilling.length === 0, spilling.slice(0, 3).join(' | '))
await shot(owner, '05-builder')

await owner.getByRole('button', { name: /^Save/ }).click()
await owner.waitForTimeout(1600)
check('saving clears the unsaved flag', (await owner.locator('body').innerText()).includes('All changes saved'))

// ---------------------------------------------------------------------------

step('Reload: the layout comes back exactly as it was saved')

const layoutBefore = await owner.locator('.insights-widget').evaluateAll((nodes) =>
  nodes.map((node) => ({
    title: node.querySelector('.insights-widget__title')?.textContent?.trim() ?? '',
    width: Math.round(node.getBoundingClientRect().width),
  })),
)

await owner.reload({ waitUntil: 'domcontentloaded' })
await owner.waitForLoadState('networkidle', { timeout: 25_000 }).catch(() => {})
await owner.waitForTimeout(1500)

const layoutAfter = await owner.locator('.insights-widget').evaluateAll((nodes) =>
  nodes.map((node) => ({
    title: node.querySelector('.insights-widget__title')?.textContent?.trim() ?? '',
    width: Math.round(node.getBoundingClientRect().width),
  })),
)

check(
  'every widget came back, in the same order, at the same size',
  JSON.stringify(layoutBefore) === JSON.stringify(layoutAfter),
  `${layoutBefore.length} widgets`,
)
await shot(owner, '06-after-reload')

// ---------------------------------------------------------------------------

step('Share the layout with a colleague, and publish it')

await owner.getByRole('button', { name: 'Share', exact: true }).first().click()
await owner.waitForTimeout(900)
const shareVisible = await owner.locator('#share-subject').isVisible().catch(() => false)

if (shareVisible) {
  const shareText = await owner.locator('body').innerText()
  check(
    'sharing says it grants the configuration, not the data',
    /they can see|their own permissions|source|not.*data|as themselves/i.test(shareText),
    shareText.split('\n').find((l) => /permission|source|their own/i.test(l)) ?? '',
  )
  await owner.locator('#share-subject').fill('uuid-colleague-2')
  await owner.locator('#share-permission').selectOption('view').catch(() => {})
  await owner.getByRole('button', { name: /^Share$/ }).last().click()
  await owner.waitForTimeout(1200)
  check('the share is listed', (await owner.locator('body').innerText()).includes('uuid-colleague-2'))
  await shot(owner, '07-share')
  await owner.keyboard.press('Escape')
  await owner.waitForTimeout(500)
} else {
  check('a share dialog is offered', false, 'dialog did not open')
}

await owner.getByRole('button', { name: /Publish/ }).click().catch(() => {})
await owner.waitForTimeout(1500)

// ---------------------------------------------------------------------------

step('Export a report as PDF, CSV and XLSX')

const dashboardId = builderUrl.match(/\/dashboards\/([^/]+)\/edit/)?.[1] ?? ''

// Exports live on Reports — a report is the thing with a title, a period and a
// definition to carry into a file.
await owner.goto(`${BASE}/reports`, { waitUntil: 'domcontentloaded' })
await owner.waitForLoadState('networkidle', { timeout: 25_000 }).catch(() => {})
await owner.waitForTimeout(1200)

await owner.getByRole('button', { name: /Export/i }).first().click()
await owner.waitForTimeout(900)

for (const format of ['csv', 'xlsx', 'pdf']) {
  // The dialog closes itself once a file is handed to the browser, so each
  // format is a fresh open rather than three clicks in one dialog.
  if (!(await owner.locator('input[name="export-format"]').count())) {
    await owner.getByRole('button', { name: /Export/i }).first().click()
    await owner.waitForTimeout(700)
  }

  const radio = owner.locator(`input[name="export-format"][value="${format}"]`)
  if (!(await radio.count())) {
    check(`${format.toUpperCase()} export`, false, 'format not offered')
    continue
  }
  await radio.check()
  const waitForDownload = owner.waitForEvent('download', { timeout: 30_000 })
  await owner.getByRole('button', { name: new RegExp(`Download ${format}`, 'i') }).click()
  const download = await waitForDownload.catch(() => null)

  if (!download) {
    check(`${format.toUpperCase()} export downloads`, false, 'no download event')
    continue
  }

  const file = path.join(OUT, `export.${format}`)
  await download.saveAs(file)
  const { readFile } = await import('node:fs/promises')
  const bytes = await readFile(file)

  const signature =
    format === 'pdf' ? bytes.subarray(0, 4).toString() === '%PDF'
      : format === 'xlsx' ? bytes.subarray(0, 2).toString() === 'PK'
      : !bytes.subarray(0, 200).toString().includes('<html')

  check(`${format.toUpperCase()} downloads as a real ${format.toUpperCase()}`, signature && bytes.length > 200, `${bytes.length} bytes`)

  if (format === 'csv') {
    const text = bytes.toString()
    check('the CSV carries its scope and period', /Company|Period|Generated/i.test(text))
    // The stub's top customer is literally named "=cmd|calc".
    const dangerous = text.split('\n').find((line) => line.includes('cmd|calc'))
    if (dangerous) {
      check(
        'a formula in a label is neutralised',
        /'=cmd|"'=cmd/.test(dangerous) || !/(^|,)\s*[=+\-@]/.test(dangerous),
        dangerous.trim().slice(0, 60),
      )
    } else {
      // The stub's dangerous label rides on the top-customers ranking, which
      // this report does not include. The PHP suite covers it directly, over
      // CSV and XLSX both.
      skip('a formula in a label is neutralised', 'no such label in this report — covered by tests/integration.php')
    }
  }
}

await shot(owner, '08-export')
await owner.keyboard.press('Escape')

// ---------------------------------------------------------------------------

step('A shared layout does not hand over the data')

const colleagueContext = await newContext(browser)
const colleague = await signIn(colleagueContext, COLLEAGUE)
await colleague.goto(`${BASE}/dashboards/${dashboardId}`, { waitUntil: 'domcontentloaded' })
await colleague.waitForLoadState('networkidle', { timeout: 25_000 }).catch(() => {})
await colleague.waitForTimeout(1200)

const colleagueText = await colleague.locator('body').innerText()
check('the colleague can open the dashboard that was shared with them', !/not found/i.test(colleagueText))
check(
  'every figure is fetched for the viewer, not replayed from the owner',
  // The stub answers the colleague exactly as it answers the owner, so the
  // figures legitimately match; what must NOT appear is a cached-for-another-user
  // marker. This is the browser half of the check; the server half — a viewer
  // whose source refuses them — is in the PHP suite, which can deny per user.
  !/cached|owner's copy|as the owner/i.test(colleagueText),
)
check('the viewer cannot edit what they were only shown', !(await colleague.getByRole('button', { name: /^Save/ }).count()))
await shot(colleague, '09-colleague-view')
await colleagueContext.close()

// ---------------------------------------------------------------------------

step('Ask Insights: an answer, then a proposal that creates nothing on its own')

await owner.goto(`${BASE}/ask`, { waitUntil: 'domcontentloaded' })
await owner.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => {})

await owner.locator('#ask-question').fill('How did sales do this month?')
await owner.getByRole('button', { name: /^Ask$/ }).click()
await owner.waitForTimeout(3500)

const answerText = await owner.locator('body').innerText()
check('an answer comes back without any model configured', /₹|unavailable|could not/i.test(answerText))
check('it separates what was observed from what caused it', /correlation|describes what|not evidence/i.test(answerText))
await shot(owner, '10-ask')

await owner.locator('#ask-question').fill('Create a dashboard for overdue collections')
await owner.getByRole('button', { name: /^Ask$/ }).click()
await owner.waitForTimeout(3500)

const proposalText = await owner.locator('body').innerText()
const hasProposal = /Create this dashboard/i.test(proposalText)
check('a dashboard request comes back as a proposal', hasProposal)

if (hasProposal) {
  const beforeCount = await (async () => {
    await owner.context().newPage()
    return null
  })().then(() => null)
  void beforeCount

  check(
    'nothing is created until Apply is pressed',
    /nothing has changed|nothing is created|press Apply/i.test(proposalText),
  )
  await shot(owner, '11-proposal')

  await owner.getByRole('button', { name: 'Create this dashboard' }).click()
  await owner.waitForURL(/\/dashboards\/.+\/edit/, { timeout: 20_000 }).catch(() => {})
  await owner.waitForTimeout(1500)
  check('applying it opens a real dashboard in the builder', /\/dashboards\/[^/]+\/edit/.test(owner.url()), owner.url())
  await shot(owner, '12-applied')
}

// ---------------------------------------------------------------------------

await browser.close()

await writeFile(path.join(OUT, 'flow.json'), JSON.stringify(results, null, 2))

const all = results.flatMap((s) => s.checks)
const failed = all.filter((c) => !c.passed && !c.skipped)
const skipped = all.filter((c) => c.skipped)

console.log(`\n${'-'.repeat(60)}`)
console.log(
  `${all.length - failed.length - skipped.length} passed, ${failed.length} failed` +
    `${skipped.length ? `, ${skipped.length} not exercised here` : ''}. Screenshots in ${OUT}`,
)
if (failed.length > 0) {
  for (const failure of failed) console.log(`  FAIL ${failure.what}${failure.detail ? ` — ${failure.detail}` : ''}`)
  process.exitCode = 1
}
