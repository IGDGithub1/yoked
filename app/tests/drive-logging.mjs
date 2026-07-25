/**
 * Drives the deployed logging UI in a real browser.
 *
 * Verifies the things a build cannot: that the day loads, a check-in saves, a
 * meal logs as planned, a session logs per-exercise, and that all of it is still
 * there after a reload.
 *
 * Needs the fixture user, and needs it seeded TODAY — the logging screen opens
 * on today and the fixture's plan week has to contain it:
 *
 *   ssh …  'cd … && php bin/seed-uitest.php'
 *   node app/tests/drive-logging.mjs
 *
 * Runs against the deployed site rather than a dev server on purpose. Everything
 * here is a client/server contract, and the host has already been the
 * interesting variable twice (nginx serving static files itself, the 60-second
 * MySQL wait_timeout).
 */
import { chromium } from 'playwright'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const here = dirname(fileURLToPath(import.meta.url))
const shot = (name) => resolve(here, name)

const BASE = process.env.YOKED_BASE || 'https://yoked.lil-boxes.com'
const USER = 'uitest_logging'
const PASS = 'a-long-enough-passphrase'

const results = []
const ok = (label) => { results.push(['ok', label]); console.log(`  ok    ${label}`) }
const fail = (label, why) => { results.push(['FAIL', label]); console.log(`  FAIL  ${label} — ${why}`) }

async function check(label, fn) {
  try {
    const r = await fn()
    if (r === true || r === undefined) ok(label)
    else fail(label, typeof r === 'string' ? r : 'false')
  } catch (e) {
    fail(label, e.message.split('\n')[0])
  }
}

const browser = await chromium.launch()
// A phone viewport: this is a PWA and the logging screen is a thumb interface.
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } })
const page = await ctx.newPage()

const consoleErrors = []
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()) })
page.on('pageerror', (e) => consoleErrors.push(`pageerror: ${e.message}`))

// ---- sign in ---------------------------------------------------------------

console.log('\n1. signing in')

await page.goto(BASE, { waitUntil: 'networkidle' })

await check('the sign-in screen renders', async () => {
  await page.locator('form.card').waitFor({ timeout: 15000 })
})

// Sign-in is the default mode. The submit button inside the form is the only
// unambiguous "Sign in" — the mode toggle outside it uses the same words.
const form = page.locator('form.card')
await form.locator('input[type="text"]').first().fill(USER)
await form.locator('input[type="password"]').first().fill(PASS)
await form.getByRole('button', { name: /^sign in$/i }).click()

await check('signing in lands on the logging screen', async () => {
  await page.getByRole('heading', { name: /how are you today/i }).waitFor({ timeout: 20000 })
})

await check('today\'s date is shown', async () => {
  const eyebrow = await page.locator('.eyebrow').first().textContent()
  return /today/i.test(eyebrow) ? true : `eyebrow said "${eyebrow}"`
})

// ---- the check-in ----------------------------------------------------------

console.log('\n2. the daily check-in')

await check('the energy scale offers five ratings', async () => {
  const n = await page.locator('[aria-labelledby="sc-energy"] [role="radio"]').count()
  return n === 5 ? true : `found ${n}`
})

await check('tapping an energy rating saves it', async () => {
  const four = page.locator('[aria-labelledby="sc-energy"] [role="radio"]').nth(3)
  await four.click()
  await four.and(page.locator('[aria-checked="true"]')).waitFor({ timeout: 10000 })
})

await check('the check-in counter reflects what saved', async () => {
  await page.waitForFunction(
    () => /1 of 5/.test(document.body.innerText),
    { timeout: 10000 }
  )
})

await check('soreness saves independently of energy', async () => {
  await page.locator('[aria-labelledby="sc-soreness"] [role="radio"]').nth(1).click()
  await page.waitForFunction(
    () => /2 of 5/.test(document.body.innerText),
    { timeout: 10000 }
  )
})

await check('hours slept saves on blur', async () => {
  await page.locator('#sleep-hours').fill('7.5')
  await page.locator('#sleep-hours').blur()
  await page.waitForFunction(
    () => /3 of 5/.test(document.body.innerText),
    { timeout: 10000 }
  )
})

// ---- food ------------------------------------------------------------------

console.log('\n3. food')

await check('the prescribed breakfast is shown', async () => {
  const txt = await page.locator('.card', { hasText: 'Breakfast' }).first().innerText()
  return /Eggs and oats/.test(txt) ? true : `card read: ${txt.replace(/\n/g, ' | ')}`
})

await check('"ate as planned" logs the meal', async () => {
  const card = page.locator('.card', { hasText: 'Breakfast' }).first()
  await card.getByRole('button', { name: /ate as planned/i }).click()
  await card.getByRole('button', { name: /logged as planned/i }).waitFor({ timeout: 15000 })
})

await check('the meal shows the prescribed food as an entry', async () => {
  const card = page.locator('.card', { hasText: 'Breakfast' }).first()
  const txt = await card.innerText()
  return /600 kcal/.test(txt) ? true : `card read: ${txt.replace(/\n/g, ' | ')}`
})

await check('the day total picked up the meal', async () => {
  await page.waitForFunction(
    () => /600\s*\/\s*2400/.test(document.body.innerText.replace(/\s+/g, ' ')),
    { timeout: 10000 }
  )
})

await check('the meal is tagged as planned', async () => {
  const card = page.locator('.card', { hasText: 'Breakfast' }).first()
  const tag = await card.locator('.tag').first().textContent()
  return /as planned/.test(tag) ? true : `tag read "${tag}"`
})

// The additive delta — the correction path that mattered most in the original.
await check('the +100 nudge adds on top of the entries', async () => {
  const card = page.locator('.card', { hasText: 'Breakfast' }).first()
  await card.getByRole('button', { name: '+100' }).click()
  await page.waitForFunction(
    () => /700\s*\/\s*2400/.test(document.body.innerText.replace(/\s+/g, ' ')),
    { timeout: 10000 }
  )
})

await check('the nudge is absolute against itself, not cumulative', async () => {
  // +25 on a running +100 must land on 125, not 225: the client sends the
  // running total and the server stores it absolutely.
  const card = page.locator('.card', { hasText: 'Breakfast' }).first()
  await card.getByRole('button', { name: '+25' }).click()
  await page.waitForFunction(
    () => /725\s*\/\s*2400/.test(document.body.innerText.replace(/\s+/g, ' ')),
    { timeout: 10000 }
  )
  const txt = await card.innerText()
  return /\+125 kcal/.test(txt) ? true : `delta label read: ${txt.replace(/\n/g, ' | ')}`
})

// ---- training --------------------------------------------------------------

console.log('\n4. training')

await check('the committed session is shown with its rationale', async () => {
  const card = page.locator('.card', { hasText: 'Full body' }).first()
  const txt = await card.innerText()
  if (!/establishing load/.test(txt)) return `no rationale: ${txt.replace(/\n/g, ' | ')}`
  return true
})

await check('the optional session is labelled optional', async () => {
  const card = page.locator('.card', { hasText: 'Conditioning' }).first()
  const txt = await card.innerText()
  return /optional/.test(txt) ? true : `card read: ${txt.replace(/\n/g, ' | ')}`
})

await check('the session form pre-fills the prescription', async () => {
  const card = page.locator('.card', { hasText: 'Full body' }).first()
  await card.getByRole('button', { name: /log this session/i }).click()
  const sets = card.locator('.exrow-inputs input').first()
  await sets.waitFor({ timeout: 10000 })
  const v = await sets.inputValue()
  return v === '3' ? true : `sets pre-filled as "${v}", expected 3`
})

await check('the prescribed target is visible while typing', async () => {
  const card = page.locator('.card', { hasText: 'Full body' }).first()
  const txt = await card.innerText()
  return /Asked for:.*3 × 10/.test(txt) ? true : `no target line: ${txt.replace(/\n/g, ' | ')}`
})

await check('logging the session saves it', async () => {
  const card = page.locator('.card', { hasText: 'Full body' }).first()
  // Fill an RPE on the first exercise so there is something beyond the defaults.
  const inputs = card.locator('.exrow-inputs input')
  await inputs.nth(3).fill('8')
  await card.getByRole('button', { name: /^done$/i }).click()
  await card.getByRole('button', { name: /remove/i }).waitFor({ timeout: 20000 })
})

await check('adherence counts the committed session', async () => {
  await page.waitForFunction(
    () => /1 of 1 done/.test(document.body.innerText),
    { timeout: 10000 }
  )
})

await check('the logged exercise shows what was actually done', async () => {
  const card = page.locator('.card', { hasText: 'Full body' }).first()
  const txt = await card.innerText()
  return /RPE 8/.test(txt) ? true : `card read: ${txt.replace(/\n/g, ' | ')}`
})

// ---- persistence -----------------------------------------------------------

console.log('\n5. it survives a reload')

await page.reload({ waitUntil: 'networkidle' })
await page.getByRole('heading', { name: /how are you today/i }).waitFor({ timeout: 20000 })

await check('the check-in ratings came back', async () => {
  const energy = await page
    .locator('[aria-labelledby="sc-energy"] [role="radio"]')
    .nth(3)
    .getAttribute('aria-checked')
  const hours = await page.locator('#sleep-hours').inputValue()
  if (energy !== 'true') return `energy 4 came back as aria-checked=${energy}`
  if (hours !== '7.5') return `hours came back as "${hours}"`
  return true
})

await check('the logged meal and its delta came back', async () => {
  await page.waitForFunction(
    () => /725\s*\/\s*2400/.test(document.body.innerText.replace(/\s+/g, ' ')),
    { timeout: 10000 }
  )
})

await check('the logged session came back', async () => {
  await page.waitForFunction(
    () => /1 of 1 done/.test(document.body.innerText),
    { timeout: 10000 }
  )
})

// ---- the date stepper ------------------------------------------------------

console.log('\n6. moving between days')

await check('forward is disabled on today', async () => {
  const disabled = await page.getByRole('button', { name: /next day/i }).isDisabled()
  return disabled === true ? true : 'next-day button was enabled on today'
})

await check('stepping back shows an empty yesterday', async () => {
  await page.getByRole('button', { name: /previous day/i }).click()
  await page.waitForFunction(
    () => /yesterday/i.test(document.body.innerText),
    { timeout: 15000 }
  )
  // Wait for the fetch to settle before reading totals — the label changes on
  // the click, the numbers only after the response. Yesterday has no
  // prescription in the fixture, so the check-in card returning is the signal
  // that the new day actually rendered.
  await page.getByRole('heading', { name: /how are you today/i }).waitFor({ timeout: 15000 })
  await page.waitForFunction(
    () => !/725/.test(document.body.innerText),
    { timeout: 15000 }
  ).catch(() => {})
  const body = await page.locator('body').innerText()
  if (/725/.test(body)) return 'yesterday showed today\'s totals'
  // And the ratings must not carry over either.
  const energy = await page
    .locator('[aria-labelledby="sc-energy"] [role="radio"]')
    .nth(3)
    .getAttribute('aria-checked')
  return energy === 'true' ? 'yesterday inherited today\'s energy rating' : true
})

await check('"back to today" returns and re-loads the day', async () => {
  await page.getByRole('button', { name: /back to today/i }).click()
  await page.waitForFunction(
    () => /725\s*\/\s*2400/.test(document.body.innerText.replace(/\s+/g, ' ')),
    { timeout: 15000 }
  )
})

// ---- quality floor ---------------------------------------------------------

console.log('\n7. quality floor')

await check('the accent is spent sparingly', async () => {
  // DESIGN.md: one yellow element per view, and it is earned. The header yolk
  // plus at most one primary button is the budget — a column of yellow buttons
  // down a day of meals is what this guards against.
  const n = await page.locator('.btn--primary').count()
  return n <= 1 ? true : `${n} primary buttons on screen`
})

await check('a logged meal no longer offers a primary action', async () => {
  const card = page.locator('.card', { hasText: 'Breakfast' }).first()
  const n = await card.locator('.btn--primary').count()
  return n === 0 ? true : 'the logged breakfast still has a yellow button'
})

await check('no verdict is shown for a day still in progress', async () => {
  const body = await page.locator('body').innerText()
  return /macros are off|On target today/.test(body)
    ? 'a verdict was shown for today'
    : true
})

await check('no horizontal scroll at 390px', async () => {
  const over = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth
  )
  return over <= 1 ? true : `body overflows by ${over}px`
})

await check('it renders at 360px too', async () => {
  await page.setViewportSize({ width: 360, height: 780 })
  await page.waitForTimeout(300)
  const over = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth
  )
  return over <= 1 ? true : `body overflows by ${over}px at 360`
})

await check('the console is clean', async () => {
  // Ignore the favicon/manifest noise a PWA emits on a fresh profile.
  const real = consoleErrors.filter((e) => !/favicon|manifest|sw\.php|service ?worker/i.test(e))
  return real.length === 0 ? true : real.slice(0, 3).join(' ;; ')
})

await page.screenshot({ path: shot('logging-today.png'), fullPage: true })

// ---- dark mode -------------------------------------------------------------

const dark = await ctx.newPage()
await dark.emulateMedia({ colorScheme: 'dark' })
await dark.goto(BASE, { waitUntil: 'networkidle' })
await dark.getByRole('heading', { name: /how are you today/i }).waitFor({ timeout: 20000 })
await check('dark mode picks up the dark shell', async () => {
  const bg = await dark.evaluate(() => getComputedStyle(document.body).backgroundColor)
  // --shell dark is #17140F.
  return /23,\s*20,\s*15/.test(bg) ? true : `body background was ${bg}`
})
await dark.setViewportSize({ width: 390, height: 844 })
await dark.screenshot({ path: shot('logging-today-dark.png'), fullPage: true })

await browser.close()

const failed = results.filter((r) => r[0] === 'FAIL')
console.log(`\n${results.length - failed.length} passed, ${failed.length} failed`)
process.exit(failed.length ? 1 : 0)
