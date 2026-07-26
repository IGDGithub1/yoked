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

/**
 * Sign a page in through the API rather than the form.
 *
 * THE SUITE WAS THROTTLING ITSELF. It signs in nine times — once per fixture per section —
 * against src/routes/auth.php's limit of 20 logins per IP per 15 minutes. Two consecutive
 * runs, or one run plus a hand-written probe, exhausts that bucket, and then EVERY remaining
 * block fails at `locator.waitFor` on the sign-in form. Thirty-odd cascading failures that all
 * read like real regressions while the app is defending itself correctly.
 *
 * The form path is still exercised, once, by section 1 — that is the thing actually under
 * test. Every later block only needs a session, so it posts to /api/login directly. Same
 * endpoint, same rate bucket, but one request instead of a page load plus a form fill plus a
 * navigation, and no dependence on the sign-in screen rendering.
 *
 * Returns nothing and throws on failure, so a caller that forgets to check still fails loudly
 * rather than proceeding anonymously — which is how the 401 cascade above got mistaken for
 * missing UI in the first place.
 */
async function signInVia(target, username = USER, password = PASS) {
  await target.goto(BASE, { waitUntil: 'domcontentloaded' })

  const result = await target.evaluate(async ({ u, p }) => {
    // Log out first: the shared browser context carries whichever fixture the previous block
    // left signed in, and /api/login on top of a live session is not guaranteed to switch.
    const me = await (await fetch('/api/me', { credentials: 'same-origin' })).json()
    if (me.authenticated) {
      await fetch('/api/logout', {
        method: 'POST',
        headers: { 'x-csrf-token': me.csrf, accept: 'application/json' },
        credentials: 'same-origin',
      })
    }

    // A fresh token: logout rotates it as session-fixation defence.
    const fresh = await (await fetch('/api/csrf', { credentials: 'same-origin' })).json()
    const res = await fetch('/api/login', {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'x-csrf-token': fresh.csrf,
        accept: 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ identifier: u, password: p }),
    })
    return { status: res.status, body: await res.json() }
  }, { u: username, p: password })

  if (result.status !== 200) {
    throw new Error(
      `API sign-in for ${username} failed with ${result.status}: `
      + (result.body?.error || 'no message')
      + (result.status === 429 ? ' (login rate limit — wait out the 15-minute window)' : '')
    )
  }

  // The SPA reads the session on boot, so it has to load after the cookie exists.
  await target.reload({ waitUntil: 'networkidle' })
  await target.getByRole('button', { name: /^Dashboard$/ }).waitFor({ timeout: 20000 })
}

/** A local date N days from today, as YYYY-MM-DD. For absence return dates. */
function inDays(n) {
  const d = new Date()
  d.setDate(d.getDate() + n)
  const p = (x) => String(x).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

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

await check('signing in lands on the Dashboard, not the Journal', async () => {
  /*
   * The landing page is REVIEW, not entry. The two views were split because every
   * review surface had been accumulating on the logging screen and pushing the actual
   * meals down the page.
   */
  await page.getByRole('button', { name: /^Dashboard$/ }).waitFor({ timeout: 20000 })
  const current = await page.getByRole('button', { name: /^Dashboard$/ })
    .getAttribute('aria-current')
  if (current !== 'page') return `the Dashboard tab was not current (${current})`
  // And the hash reflects it, so a reload comes back to the same place.
  return /#\/dashboard/.test(page.url()) ? true : `url was ${page.url()}`
})

/**
 * Both views are reachable from the header nav; most checks below live in the Journal.
 *
 * Matches on the ACCESSIBLE NAME, which is the aria-label on an icon button. That is the
 * right thing to match: it is what a screen reader announces, and it kept working
 * unchanged when the text tabs became icons.
 */
async function goto(view, target = page) {
  const want = `#/${view.toLowerCase()}`
  if (new URL(target.url()).hash === want) return
  await target.getByRole('button', { name: new RegExp(`^${view}$`, 'i') }).click()
  await target.waitForFunction((h) => window.location.hash === h, want, { timeout: 10000 })
}

/*
 * A reload now lands on the Dashboard, because the hash is written with replaceState
 * on first load and a fresh page has none. Journal checks that reload have to come
 * back, so this does both.
 */
async function reloadJournal(target = page) {
  await target.reload({ waitUntil: 'networkidle' })
  await goto('Journal', target)
  await target.getByRole('heading', { name: /how are you today/i })
    .waitFor({ timeout: 20000 })
}

await check('all navigation is in one place, and it is the header', async () => {
  /*
   * The first version split it: view switching in a bottom tab bar, everything else at
   * the top, so a user looking for "where can I go" had to check two edges of the
   * screen. Asserted structurally rather than by eye — a future card could reintroduce a
   * fixed bottom bar without anyone noticing.
   */
  const inHeader = await page.locator('.appbar .navicons .navbtn').count()
  // Dashboard, Journal, Friends, theme, sign out.
  if (inHeader < 5) return `${inHeader} nav controls in the header, expected 5`
  const bottomBar = await page.locator('.tabbar').count()
  return bottomBar === 0 ? true : 'a bottom tab bar is still present'
})

await check('every icon control has a name for a screen reader and a hover', async () => {
  // An icon is never the accessible name. "gauge" is not what the control does, so each
  // button carries an aria-label, and title= gives a sighted user the same string.
  const missing = await page.locator('.navicons .navbtn').evaluateAll((els) =>
    els
      .filter((el) => !el.getAttribute('aria-label') || !el.getAttribute('title'))
      .map((el) => el.outerHTML.slice(0, 60))
  )
  return missing.length === 0 ? true : `unlabelled: ${missing.join(' ;; ')}`
})

await check('the logo goes home', async () => {
  await goto('Journal')
  await page.getByRole('button', { name: /yoked home/i }).click()
  await page.waitForFunction(
    () => window.location.hash === '#/dashboard',
    { timeout: 10000 }
  )
})

await check('the Journal is reachable and holds the day', async () => {
  await goto('Journal')
  await page.getByRole('heading', { name: /how are you today/i }).waitFor({ timeout: 20000 })
})

/*
 * Clear the browser-side state this suite itself writes.
 *
 * Collapse and theme live in localStorage, so a second run inherited a collapsed
 * Food section from the first and then failed a dozen assertions that could not
 * find the cards inside it. Re-seeding the database is not enough — half the
 * state under test is on the client.
 *
 * The DB side still needs `php bin/seed-uitest.php` before each run; this only
 * guarantees the browser starts where a new user would.
 */
await page.evaluate(() => {
  localStorage.removeItem('yoked.sections')
  localStorage.removeItem('yoked.theme')
})
await reloadJournal()

await check('today\'s date is shown', async () => {
  const eyebrow = await page.locator('.eyebrow').first().textContent()
  return /today/i.test(eyebrow) ? true : `eyebrow said "${eyebrow}"`
})

/*
 * Refuse to run against a dirty fixture.
 *
 * Almost every assertion below is written against a freshly seeded day, and a
 * re-run without re-seeding produced twenty cascading failures that all looked
 * like real bugs. One honest error beats twenty misleading ones.
 */
{
  // Check the .tag elements, not the body text: "Ate as planned" is a BUTTON on
  // an unlogged meal and "Did something else" is a status option, so matching
  // prose here flagged a clean fixture as dirty. A .tag is only rendered for a
  // meal that has actually been logged.
  const tags = await page.locator('.tag').allInnerTexts()
  const logged = tags.filter((t) => /as planned|substituted|skipped|off-plan/.test(t))
  if (logged.length > 0) {
    console.error(
      `\nThe fixture already has food logged (${logged.join(', ')}).\n` +
      '\nUse `npm run drive`, which re-seeds first. `npm run drive:logging` skips that\n' +
      'and is only right when you have just seeded by hand.\n'
    )
    await browser.close()
    process.exit(2)
  }
}

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

await reloadJournal()

await check('the check-in ratings came back', async () => {
  // The card defaults CLOSED once answered, so the scales are not mounted on a
  // fresh load — the collapsed summary is what carries the answers here.
  const summary = await page.locator('body').innerText()
  if (!/energy 4\/5/i.test(summary)) return `collapsed summary read: ${summary.slice(0, 200)}`
  if (!/7\.5h sleep/i.test(summary)) return 'the sleep hours were not in the summary'

  // Then open it and confirm the controls agree with the summary.
  await page.getByRole('button', { name: /how are you today/i }).click()
  const four = page.locator('[aria-labelledby="sc-energy"] [role="radio"]').nth(3)
  await four.waitFor({ timeout: 10000 })
  const energy = await four.getAttribute('aria-checked')
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

// ---- favorites, scan, and the placeholder ----------------------------------

console.log('\n7. the ways into a meal')

await check('a logged food can be starred as a usual', async () => {
  const card = page.locator('.card', { hasText: 'Breakfast' }).first()
  const star = card.locator('.star').first()
  await star.click()
  await star.and(page.locator('[aria-pressed="true"]')).waitFor({ timeout: 15000 })
})

await check('the star survives a reload', async () => {
  await reloadJournal()
  const card = page.locator('.card', { hasText: 'Breakfast' }).first()
  const star = card.locator('.star').first()
  await star.and(page.locator('[aria-pressed="true"]')).waitFor({ timeout: 15000 })
})

await check('the starred food appears as a usual on another meal', async () => {
  const card = page.locator('.card', { hasText: 'Lunch' }).first()
  await card.getByRole('button', { name: /^Add food$/i }).click()
  // The four ways into a meal, for design review.
  await card.locator('.chips').first().waitFor({ timeout: 10000 })
  await page.screenshot({ path: shot('logging-addfood.png'), fullPage: true })
  // Opens on "Usual" when there are any favorites — eating is repetitive, and
  // that is the highest-yield thing to show first.
  const tab = card.getByRole('tab', { name: /Usual/i })
  await tab.waitFor({ timeout: 10000 })
  if ((await tab.getAttribute('aria-selected')) !== 'true') {
    return 'the Usual tab was not selected by default'
  }
  const txt = await card.innerText()
  return /Eggs and oats/.test(txt) ? true : `usuals read: ${txt.replace(/\n/g, ' | ')}`
})

await check('logging from usuals adds the food', async () => {
  const card = page.locator('.card', { hasText: 'Lunch' }).first()
  await card.locator('.result', { hasText: 'Eggs and oats' }).first().click()
  // 725 (breakfast) + 600 (the re-logged usual) = 1325.
  await page.waitForFunction(
    () => /1325\s*\/\s*2400/.test(document.body.innerText.replace(/\s+/g, ' ')),
    { timeout: 15000 }
  )
})

/*
 * Fiber must survive the round trip through a favorite.
 *
 * favorite_foods used to store net carbs only, so starring a food discarded its
 * fiber for good and every later re-log produced an entry with no fiber figure.
 * Migration 008 gave favorites the same three carb columns as an entry.
 */
await check('a favorite keeps the fiber it was starred with', async () => {
  const card = page.locator('.card', { hasText: 'Dinner' }).first()
  await card.getByRole('button', { name: /^Add food$/i }).click()
  await card.getByRole('tab', { name: /by hand/i }).click()

  await card.locator('input[aria-label="Food name"]').fill('Fiber Test Bread')
  await card.locator('.macro-grid input').nth(0).fill('200')   // kcal
  await card.locator('.macro-grid input').nth(1).fill('8')     // protein
  await card.locator('.macro-grid input').nth(2).fill('3')     // fat
  await card.locator('.macro-grid input').nth(3).fill('40')    // total carbs
  await card.locator('.macro-grid input').nth(4).fill('12')    // fiber
  await card.getByRole('button', { name: /^Add it$/i }).click()

  // Net is derived server-side: 40 - 12 = 28.
  const row = page.locator('.entry', { hasText: 'Fiber Test Bread' }).first()
  await row.waitFor({ timeout: 20000 })

  // Star it, then read the favorite back and confirm the fiber came with it.
  await row.locator('.star').click()
  await row.locator('.star[aria-pressed="true"]').waitFor({ timeout: 15000 })

  const favs = await page.evaluate(async () => {
    const res = await fetch('/api/nutrition/favorites', {
      headers: { accept: 'application/json' },
      credentials: 'same-origin',
    })
    return res.json()
  })
  const fav = (favs.favorites || []).find((f) => f.name === 'Fiber Test Bread')
  if (!fav) return 'the favorite was not saved'
  if (fav.fiber !== 12) return `fiber came back as ${fav.fiber}, expected 12`
  if (fav.total_carbs !== 40) return `total_carbs came back as ${fav.total_carbs}, expected 40`
  if (fav.carbs !== 28) return `net carbs came back as ${fav.carbs}, expected 28`
  return true
})

await check('re-logging that favorite reproduces the fiber', async () => {
  const card = page.locator('.card', { hasText: 'Dinner' }).first()
  await card.getByRole('button', { name: /^Add food$/i }).click()
  await card.getByRole('tab', { name: /Usual/i }).click()
  await card.locator('.result', { hasText: 'Fiber Test Bread' }).first().click()

  // Two of them now. Read the day back and check the newest entry's fiber, which
  // before 008 came back null even though the original had 12g.
  const day = await page.evaluate(async () => {
    const d = new Date()
    const p = (n) => String(n).padStart(2, '0')
    const date = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
    const res = await fetch(`/api/nutrition/day/${date}`, {
      headers: { accept: 'application/json' },
      credentials: 'same-origin',
    })
    return res.json()
  })
  const entries = (day.meals || [])
    .flatMap((m) => m.entries)
    .filter((e) => e.name === 'Fiber Test Bread')
  if (entries.length < 2) return `expected 2 entries, found ${entries.length}`
  const relogged = entries[entries.length - 1]
  if (relogged.fiber !== 12) return `re-logged fiber was ${relogged.fiber}, expected 12`
  if (relogged.carbs !== 28) return `re-logged net carbs was ${relogged.carbs}, expected 28`
  return true
})

/*
 * Starring a PRESCRIBED entry must not shrink its carbs.
 *
 * A prescribed meal's carbs are already net, so that entry has net + fiber but no
 * total. Sending net-as-total alongside the fiber made the server subtract the
 * fiber a second time: the 50g net breakfast came back as 44g in the favorite,
 * and again on every re-log. Double-netting, in a new place.
 */
await check('starring a prescribed meal keeps its net carbs unchanged', async () => {
  const day = await page.evaluate(async () => {
    const d = new Date()
    const p = (n) => String(n).padStart(2, '0')
    const res = await fetch(
      `/api/nutrition/day/${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`,
      { headers: { accept: 'application/json' }, credentials: 'same-origin' }
    )
    return res.json()
  })
  const breakfast = (day.meals || []).find((m) => m.slot === 'breakfast')
  const entry = breakfast?.entries?.[0]
  if (!entry) return 'no breakfast entry to compare against'

  const favs = await page.evaluate(async () => {
    const res = await fetch('/api/nutrition/favorites', {
      headers: { accept: 'application/json' }, credentials: 'same-origin',
    })
    return res.json()
  })
  const fav = (favs.favorites || []).find(
    (f) => f.name.toLowerCase() === entry.name.toLowerCase()
  )
  if (!fav) return `"${entry.name}" was starred earlier but is not in favorites`

  return fav.carbs === entry.carbs
    ? true
    : `entry net carbs ${entry.carbs} became ${fav.carbs} in the favorite`
})

// ---- snacks as one card ----------------------------------------------------

await check('snacks are one card, not three slots', async () => {
  const body = await page.locator('body').innerText()
  if (/Morning snack|Afternoon snack|Evening snack/.test(body)) {
    return 'the individual snack slots are still shown as separate cards'
  }
  return /Snacks/.test(body) ? true : 'no Snacks card at all'
})

await check('a snack logs without asking which time of day', async () => {
  const card = page.locator('.card', { hasText: 'Snacks' }).first()
  await card.getByRole('button', { name: /add a snack/i }).click()
  await card.getByRole('tab', { name: /by hand/i }).click()
  await card.locator('input[aria-label="Food name"]').fill('Test Almonds')
  await card.locator('.macro-grid input').nth(0).fill('90')
  await card.getByRole('button', { name: /^Add it$/i }).click()
  await page.locator('.entry', { hasText: 'Test Almonds' }).first().waitFor({ timeout: 20000 })
  // And it counted toward the day.
  const txt = await page.locator('.card', { hasText: 'Snacks' }).first().innerText()
  return /1 logged/.test(txt) ? true : `snacks card read: ${txt.replace(/\n/g, ' | ')}`
})

await check('a second snack joins the same card', async () => {
  const card = page.locator('.card', { hasText: 'Snacks' }).first()
  await card.getByRole('button', { name: /add a snack/i }).click()
  await card.getByRole('tab', { name: /by hand/i }).click()
  await card.locator('input[aria-label="Food name"]').fill('Test Yoghurt')
  await card.locator('.macro-grid input').nth(0).fill('120')
  await card.getByRole('button', { name: /^Add it$/i }).click()
  await page.locator('.entry', { hasText: 'Test Yoghurt' }).first().waitFor({ timeout: 20000 })
  const txt = await page.locator('.card', { hasText: 'Snacks' }).first().innerText()
  if (!/2 logged/.test(txt)) return `snacks card read: ${txt.replace(/\n/g, ' | ')}`
  // Both are in ONE card, so there is exactly one Snacks card on screen.
  const n = await page.locator('.card', { hasText: 'Snacks' }).count()
  return n === 1 ? true : `${n} snack cards`
})

await check('the search placeholder matches the meal', async () => {
  const card = page.locator('.card', { hasText: 'Dinner' }).first()
  await card.getByRole('button', { name: /^Add food$/i }).click()
  await card.getByRole('tab', { name: /Search/i }).click()
  const ph = await card.locator('input[aria-label="What did you eat?"]').getAttribute('placeholder')
  // Whatever it picked, it must be from the DINNER list — not the old hardcoded
  // "6oz chicken and a cup of broccoli" that read as breakfast nonsense.
  const dinnerish = /sirloin|salmon|curry|bolognese|stir-fried/i.test(ph)
  return dinnerish ? true : `dinner placeholder was "${ph}"`
})

await check('breakfast and dinner get different placeholders', async () => {
  const dinner = await page.locator('.card', { hasText: 'Dinner' }).first()
    .locator('input[aria-label="What did you eat?"]').getAttribute('placeholder')
  const bcard = page.locator('.card', { hasText: 'Breakfast' }).first()
  await bcard.getByRole('button', { name: /^Add food$/i }).click()
  await bcard.getByRole('tab', { name: /Search/i }).click()
  const brek = await bcard.locator('input[aria-label="What did you eat?"]')
    .getAttribute('placeholder')
  return brek !== dinner ? true : `both slots showed "${brek}"`
})

await check('the scan tab offers a typed-barcode fallback', async () => {
  const card = page.locator('.card', { hasText: 'Breakfast' }).first()
  await card.getByRole('tab', { name: /Scan/i }).click()
  // Headless Chromium has no camera, so this exercises exactly the path an iOS
  // Safari user gets: the manual number entry.
  await card.locator('input[aria-label="Barcode number"]').waitFor({ timeout: 15000 })
})

await check('a barcode lookup reaches the server', async () => {
  const card = page.locator('.card', { hasText: 'Breakfast' }).first()
  await card.locator('input[aria-label="Barcode number"]').fill('5000112637922')
  await card.getByRole('button', { name: /look up/i }).click()
  // Either a hit or an honest "not in the database" — both prove the round trip.
  // What must NOT happen is a silent nothing.
  await page.waitForFunction(
    () => /not in the barcode database|best guess|Scan another|kcal ·/i.test(document.body.innerText),
    { timeout: 45000 }
  )
})

// ---- collapsing and theme --------------------------------------------------

console.log('\n8. sections and theme')

// Read the total BEFORE collapsing, so the assertion does not depend on how much
// food earlier checks happened to log.
const totalBeforeCollapse = await page.locator('.sec-summary').first().innerText()

await check('the food section collapses', async () => {
  const toggle = page.getByRole('button', { name: /^Food$/ })
  await toggle.click()
  await page.waitForFunction(
    () => {
      const b = document.getElementById('sec-food')
      return !b || b.offsetParent === null
    },
    { timeout: 10000 }
  )
})

await check('a collapsed section still shows the day total', async () => {
  // The whole point of the summary living in the heading: a shut section still
  // says where the day stands.
  const after = await page.locator('.sec-summary').first().innerText()
  return after.trim() === totalBeforeCollapse.trim()
    ? true
    : `summary was "${totalBeforeCollapse.trim()}" open, "${after.trim()}" closed`
})

await check('the collapse survives a reload', async () => {
  await reloadJournal()
  const expanded = await page.getByRole('button', { name: /^Food$/ }).getAttribute('aria-expanded')
  return expanded === 'false' ? true : 'the section re-opened itself'
})

await check('re-opening it restores the meals', async () => {
  await page.getByRole('button', { name: /^Food$/ }).click()
  await page.locator('.card', { hasText: 'Breakfast' }).first().waitFor({ timeout: 10000 })
})

await check('the check-in collapses once answered', async () => {
  // Answered in section 2, so on a fresh load it should default shut — and it
  // must do so even though section 5 opened it by hand. Unlike Food/Training,
  // this card's open state is deliberately NOT persisted: whether it should be
  // open depends on whether today is answered, which changes daily, so a
  // remembered "open" would pin it open every morning afterwards.
  const expanded = await page
    .getByRole('button', { name: /how are you today/i })
    .getAttribute('aria-expanded')
  if (expanded !== 'false') return 'the answered check-in was still expanded'
  const body = await page.locator('body').innerText()
  // And the answers are still legible while closed.
  return /energy 4\/5/i.test(body) ? true : 'the collapsed summary did not show the ratings'
})

await check('a rating can be cleared by tapping it again', async () => {
  await page.getByRole('button', { name: /how are you today/i }).click()
  const four = page.locator('[aria-labelledby="sc-energy"] [role="radio"]').nth(3)
  await four.waitFor({ timeout: 10000 })
  await four.click()
  await page.waitForFunction(
    () => {
      const el = document.querySelectorAll('[aria-labelledby="sc-energy"] [role="radio"]')[3]
      return el && el.getAttribute('aria-checked') === 'false'
    },
    { timeout: 15000 }
  )
})

await check('the cleared rating stays cleared after a reload', async () => {
  await reloadJournal()
  const body = await page.locator('body').innerText()
  return /energy 4\/5/i.test(body) ? 'the cleared rating came back' : true
})

await check('unanswered scales are dimmed', async () => {
  const expanded = await page
    .getByRole('button', { name: /how are you today/i })
    .getAttribute('aria-expanded')
  if (expanded === 'false') {
    await page.getByRole('button', { name: /how are you today/i }).click()
  }
  const row = page.locator('[aria-labelledby="sc-energy"]')
  await row.waitFor({ timeout: 10000 })
  const answered = await row.getAttribute('data-answered')
  return answered === 'false' ? true : `data-answered was "${answered}" after clearing`
})

await check('the theme toggle forces light and dark', async () => {
  const btn = page.getByRole('button', { name: /^Theme:/ })
  const before = await btn.getAttribute('aria-label')
  await btn.click()
  const after = await page.getByRole('button', { name: /^Theme:/ }).getAttribute('aria-label')
  if (before === after) return 'the label did not change'
  const attr = await page.evaluate(() => document.documentElement.getAttribute('data-theme'))
  return attr === 'light' || attr === 'dark'
    ? true
    : `data-theme was "${attr}" after one tap`
})

await check('the forced theme survives a reload with no flash', async () => {
  const wanted = await page.evaluate(() => document.documentElement.getAttribute('data-theme'))
  await page.reload({ waitUntil: 'domcontentloaded' })
  // Read immediately, before React has necessarily mounted: applyStoredTheme()
  // runs at module scope precisely so there is no flash of the wrong theme.
  const got = await page.evaluate(() => document.documentElement.getAttribute('data-theme'))
  return got === wanted ? true : `wanted ${wanted}, got ${got} on load`
})

// ---- free-logging a workout ------------------------------------------------

console.log('\n9. logging a workout nobody prescribed')

await check('a day with no plan offers to log a workout', async () => {
  // Two days back: the fixture only prescribes today, so this is a genuinely
  // unprescribed day — which is every day of the baseline fortnight.
  await page.getByRole('button', { name: /previous day/i }).click()
  await page.getByRole('button', { name: /previous day/i }).click()
  await page.getByRole('button', { name: /log a workout/i }).waitFor({ timeout: 20000 })
})

await check('the exercise typeahead finds an exercise by name', async () => {
  await page.getByRole('button', { name: /log a workout/i }).click()
  await page.locator('input[aria-label="Search exercises"]').fill('press')
  await page.locator('.result', { hasText: /press/i }).first().waitFor({ timeout: 20000 })
})

await check('picking an exercise adds a row with the right inputs', async () => {
  await page.locator('.result', { hasText: /press/i }).first().click()
  const row = page.locator('.exrow').first()
  await row.waitFor({ timeout: 10000 })
  const txt = await row.innerText()
  // A press is weight-loaded, so it must offer kg — and not seconds.
  if (!/kg/i.test(txt)) return `no kg input: ${txt.replace(/\n/g, ' | ')}`
  return /Seconds/i.test(txt) ? `offered seconds for a press: ${txt.replace(/\n/g, ' | ')}` : true
})

await check('a timed exercise asks for seconds, not kilos', async () => {
  await page.locator('input[aria-label="Search exercises"]').fill('plank')
  const opt = page.locator('.result', { hasText: /plank/i }).first()
  await opt.waitFor({ timeout: 20000 })
  await opt.click()
  const row = page.locator('.exrow').nth(1)
  await row.waitFor({ timeout: 10000 })
  const txt = await row.innerText()
  // load_type from the exercises table is what drives this — asking for kg on a
  // plank is how you teach someone the app does not understand training.
  return /Seconds/i.test(txt) ? true : `plank row read: ${txt.replace(/\n/g, ' | ')}`
})

// The free-log form mid-fill, for design review: this is the screen that did not
// exist before and could not be commented on.
await page.screenshot({ path: shot('logging-freelog.png'), fullPage: true })

await check('the free-logged session saves', async () => {
  await page.locator('.exrow').first().locator('.exrow-inputs input').first().fill('3')
  await page.getByRole('button', { name: /save it/i }).click()
  await page.getByRole('button', { name: /^Remove$/i }).first().waitFor({ timeout: 25000 })
})

await check('it is labelled as the user\'s own, not prescribed', async () => {
  const body = await page.locator('body').innerText()
  return /your own/i.test(body) ? true : 'the free-logged session was not marked "your own"'
})

await check('the session type it recorded comes back', async () => {
  await reloadJournal()
  await page.getByRole('button', { name: /^Training$/ }).waitFor({ timeout: 20000 })
  const body = await page.locator('body').innerText()
  // 'strength' is the default chip. Before migration 007 this always read
  // "Session" because the row had nowhere to store its type.
  return /Strength/i.test(body) ? true : 'the free-logged session lost its type'
})

// Back to today for the quality-floor checks, which assert against the day that
// actually has a plan.
//
// The date is NOT part of the URL (the app is deliberately router-free — the
// server decides where a user belongs), so the reload in the previous check
// already put us back on today. That is worth asserting rather than assuming:
// it is the difference between "the reload reset the day" and "the button
// silently vanished".
await check('a reload returns to today, since the date is not in the URL', async () => {
  const eyebrow = await page.locator('.eyebrow').first().textContent()
  if (!/today/i.test(eyebrow)) return `after reload the eyebrow said "${eyebrow}"`
  const back = await page.getByRole('button', { name: /back to today/i }).count()
  return back === 0 ? true : '"Back to today" was offered while already on today'
})

// ---- quality floor ---------------------------------------------------------

console.log('\n10. quality floor')

// Both sections open, so the accent budget is measured across everything on
// screen at once rather than whatever happened to be expanded.
for (const name of [/^Food$/, /^Training$/]) {
  const btn = page.getByRole('button', { name })
  if ((await btn.getAttribute('aria-expanded')) === 'false') await btn.click()
}
await page.locator('.card', { hasText: 'Breakfast' }).first().waitFor({ timeout: 15000 })

await check('the accent is spent sparingly', async () => {
  // DESIGN.md: one yellow element per view, and it is earned. The header yolk plus
  // at most one yellow PROMPT is the budget; a form's own submit does not count,
  // since it is the way out of a form you already chose to open.
  const found = await page.locator('.btn--primary:not([type="submit"])').allInnerTexts()
  return found.length <= 1 ? true : `${found.length} yellow prompts: ${found.join(', ')}`
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

/*
 * Every selected control must LOOK selected.
 *
 * This bug has now shipped twice: once on onboarding's "None" chips, and again on
 * the session-status and free-log-type chips — both times because the element used
 * role="radio"/aria-checked while the CSS only styled aria-pressed. A screen
 * reader announced the selection correctly while a sighted user saw nothing.
 *
 * So it is asserted mechanically rather than by eye: whatever the attribute, a
 * chosen chip must differ visually from an unchosen sibling.
 */
await check('a selected chip is visually distinct from an unselected one', async () => {
  // The free-log type chips: "Lifting" is chosen by default, "Cardio" is not.
  const open = page.getByRole('button', { name: /log something else you did/i })
  if (await open.count()) await open.click()
  const chosen = page.locator('.chip[aria-checked="true"]').first()
  await chosen.waitFor({ timeout: 15000 })
  const unchosen = page.locator('.chip[aria-checked="false"]').first()

  const style = (el) => el.evaluate((n) => {
    const s = getComputedStyle(n)
    return `${s.backgroundColor}|${s.borderColor}|${s.fontWeight}`
  })
  const a = await style(chosen)
  const b = await style(unchosen)
  return a !== b ? true : `selected and unselected chips both render as ${a}`
})

await check('the four ways into a meal show which one is open', async () => {
  const card = page.locator('.card', { hasText: 'Dinner' }).first()
  await card.getByRole('button', { name: /^Add food$/i }).click()
  const on = card.locator('.chip[aria-selected="true"]').first()
  await on.waitFor({ timeout: 15000 })
  const off = card.locator('.chip[aria-selected="false"]').first()
  const style = (el) => el.evaluate((n) => getComputedStyle(n).backgroundColor)
  const a = await style(on)
  const b = await style(off)
  return a !== b ? true : `the open and closed tabs share a background: ${a}`
})

// ---- copy and explanations -------------------------------------------------

await check('jargon is explained by a "?" rather than inline prose', async () => {
  // Training may be collapsed by the section checks above, and the free-log form
  // lives inside it.
  const section = page.getByRole('button', { name: /^Training$/ })
  if ((await section.getAttribute('aria-expanded')) === 'false') await section.click()

  // The chip check just above may already have opened it.
  const open = page.getByRole('button', { name: /log something else you did/i })
  if (await open.count()) await open.click({ timeout: 20000 })

  const help = page.getByRole('button', { name: /what is session rpe/i })
  await help.waitFor({ timeout: 15000 })

  // Closed by default: available, not present.
  let bubbles = await page.locator('.help-bubble').count()
  if (bubbles !== 0) return 'the explanation was showing before being asked for'

  await help.click()
  const bubble = page.locator('.help-bubble').first()
  await bubble.waitFor({ timeout: 10000 })
  const txt = await bubble.innerText()
  if (!/1 to 10/.test(txt)) return `bubble read: ${txt}`

  // Escape closes it. A popover you can only dismiss by re-finding a 20px button
  // is a trap on a touchscreen.
  await page.keyboard.press('Escape')
  await page.waitForFunction(
    () => document.querySelectorAll('.help-bubble').length === 0,
    { timeout: 10000 }
  )
  return true
})

await check('no "fortnight" anywhere a user can read it', async () => {
  const body = await page.locator('body').innerText()
  return /fortnight/i.test(body) ? 'the word "fortnight" is on screen' : true
})

await check('no em dashes in the visible copy', async () => {
  // Long dashes read as machine-written. Hyphens and the "not applicable" glyph
  // are fine; U+2014 is what is being kept out.
  const found = await page.evaluate(() => {
    const walk = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
    const hits = []
    while (walk.nextNode()) {
      const t = walk.currentNode.nodeValue || ''
      if (t.includes('—')) hits.push(t.trim().slice(0, 70))
    }
    return hits
  })
  return found.length === 0 ? true : `em dashes in: ${found.join(' ;; ')}`
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

// ---- nudges ----------------------------------------------------------------

console.log('\n11. nudges and coach questions')

// Review surfaces are on the DASHBOARD now. They used to sit on the logging
// screen, which is exactly what the split fixed.
await goto('Dashboard')

await check('a nudge is shown, and it addresses absence rather than a bad day', async () => {
  const nudges = page.locator('.nudge')
  await nudges.first().waitFor({ timeout: 20000 })
  const txt = await page.locator('body').innerText()

  if (!/Three days quiet/.test(txt)) return 'the absence nudge did not render'
  /*
   * The tone rule, asserted rather than trusted: "Nudges never shame a bad day. They
   * address absence." A nudge that reaches for blame is worse than no nudge, because
   * it teaches people to stop logging the hard weeks.
   */
  const shaming = /you failed|fell off|disappoint|let yourself down|excuses/i.test(txt)
  return shaming ? 'the nudge copy contains shaming language' : true
})

await check('a drift question reads as a conversation, not a nudge', async () => {
  // Different treatment on purpose: one addresses silence, the other opens a
  // conversation and expects an answer.
  const notice = page.locator('.notice.nudge')
  await notice.waitFor({ timeout: 15000 })
  const txt = await notice.innerText()
  return /What happened/.test(txt) ? true : `notice read: ${txt}`
})

await check('a nudge can be dismissed and stays dismissed', async () => {
  const before = await page.locator('.nudge').count()
  await page.locator('.nudge').first().getByRole('button', { name: /dismiss/i }).click()
  await page.waitForFunction(
    (n) => document.querySelectorAll('.nudge').length < n,
    before,
    { timeout: 15000 }
  )
  // And it does not come back on the next boot, which is the whole point of
  // dismissing rather than hiding.
  await reloadJournal()
  const after = await page.locator('.nudge').count()
  return after < before ? true : `${after} nudges after dismissing one of ${before}`
})

await check('nudges do not claim the accent', async () => {
  // A nudge is a line someone did not ask for. It gets less presence than the things
  // they came here to do, so it must never be the yellow thing on the screen.
  const n = await page.locator('.nudge .btn--primary').count()
  return n === 0 ? true : `${n} primary buttons inside a nudge`
})

// ---- the weekly check-in ---------------------------------------------------

console.log('\n12. the weekly check-in')

await goto('Dashboard')

await check('an open check-in is surfaced with its week', async () => {
  const card = page.locator('.checkin-weekly')
  await card.waitFor({ timeout: 20000 })
  const txt = await card.innerText()
  // Which week, spelled out: the form opens on Saturday so "this week" is
  // ambiguous without the dates.
  return /\d+ to \d+ \w+/.test(txt) ? true : `card read: ${txt.replace(/\n/g, ' | ')}`
})

await check('the profile is reachable from the Dashboard, not the nav', async () => {
  /*
   * It used to be a top-level nav link, which gave a screen visited a handful of times
   * the same prominence as the two used daily. It still has to be REACHABLE — a question
   * left blank stays blank forever otherwise — just not prominent.
   */
  await goto('Dashboard')
  const navProfile = await page.locator('.navicons').getByRole('button', { name: /profile/i }).count()
  if (navProfile > 0) return 'the profile is still in the header nav'

  await page.getByRole('button', { name: /your profile/i }).click()
  await page.waitForFunction(() => window.location.hash === '#/profile', { timeout: 10000 })
  const body = await page.locator('body').innerText()
  // Named for what it IS, not how it was collected: a user has a profile, not a set of
  // quiz answers.
  if (/Your answers/.test(body)) return 'the old "Your answers" wording is still there'
  return /Your profile/.test(body) ? true : `profile page read: ${body.slice(0, 200)}`
})

await check('the profile has a working back button, being a real route', async () => {
  await page.goBack()
  await page.waitForFunction(() => window.location.hash === '#/dashboard', { timeout: 10000 })
})

await check('signing out asks first', async () => {
  /*
   * As a text link beside "Your answers" this was hard to hit by accident. As a bare icon
   * next to the theme toggle it is not, and the cost of a mis-tap is re-entering a
   * password on a phone.
   */
  await page.getByRole('button', { name: /^sign out$/i }).click()
  const dialog = page.getByRole('alertdialog', { name: /sign out/i })
  await dialog.waitFor({ timeout: 10000 })

  // "Stay" backs out and leaves the session alone, which is the whole point.
  await dialog.getByRole('button', { name: /^stay$/i }).click()
  await page.waitForFunction(
    () => document.querySelectorAll('[role="alertdialog"]').length === 0,
    { timeout: 10000 }
  )
  const stillIn = await page.locator('.navicons').count()
  return stillIn > 0 ? true : 'declining the confirmation signed the user out anyway'
})

await check('the Dashboard spends the accent once', async () => {
  /*
   * The accent rule used to need negotiation. With the check-in, the meals and the
   * training prompt all on one screen there were three yellow buttons, each locally
   * reasonable, together exactly the "column of yellow buttons" the rule forbids —
   * so Food and Training grew a `yieldAccent` prop to go quiet when a check-in was
   * open.
   *
   * Splitting review from entry removed the need for that entirely: each view has one
   * job, so one prompt per view holds by construction. This asserts the outcome rather
   * than the mechanism, and the mechanism is gone.
   *
   * Counts PROMPTS, not submits: a form's own submit is the way out of a form you
   * chose to open, not unsolicited yellow competing for attention.
   */
  const found = await page.locator('.btn--primary:not([type="submit"])').allInnerTexts()
  return found.length <= 1
    ? true
    : `${found.length} yellow prompts on the Dashboard: ${found.join(', ')}`
})

await check('it says whether answering still shapes the plan', async () => {
  const txt = await page.locator('.checkin-weekly').innerText()
  // No plan exists for next week in the fixture, so it should say it counts.
  return /shapes next week/.test(txt)
    ? true
    : `no shaping status in: ${txt.replace(/\n/g, ' | ')}`
})

await check('the form opens with weight and waist, not six boxes', async () => {
  const card = page.locator('.checkin-weekly')
  await card.getByRole('button', { name: /fill it in/i }).click()
  const inputs = await card.locator('input[type="number"]').count()
  // Waist is the one that matters most (§7.2); the other five are behind a
  // toggle so a two-minute form does not look like a medical intake.
  return inputs === 2 ? true : `${inputs} number inputs on open, expected 2`
})

await check('the other measurements are available but not forced', async () => {
  const card = page.locator('.checkin-weekly')
  await card.getByRole('button', { name: /add the other measurements/i }).click()
  const inputs = await card.locator('input[type="number"]').count()
  return inputs === 7 ? true : `${inputs} number inputs after expanding, expected 7`
})

await check('units follow the profile rather than being hardcoded', async () => {
  const me = await page.evaluate(async () => {
    const r = await fetch('/api/checkin/weekly', {
      headers: { accept: 'application/json' }, credentials: 'same-origin',
    })
    return r.json()
  })
  const txt = await page.locator('.checkin-weekly').innerText()
  const wanted = me.units === 'metric' ? 'kg' : 'lb'
  return txt.includes(`Weight (${wanted})`)
    ? true
    : `profile says ${me.units} but the form does not show ${wanted}`
})

await check('the emphasis request has a "?" explaining the privilege', async () => {
  const help = page.locator('.checkin-weekly').getByRole('button', {
    name: /what is emphasis requests/i,
  })
  await help.click()
  const bubble = page.locator('.help-bubble').first()
  await bubble.waitFor({ timeout: 10000 })
  const txt = await bubble.innerText()
  await page.keyboard.press('Escape')
  return /following the plan/.test(txt) ? true : `bubble read: ${txt}`
})

await check('a partial answer submits and says what happens next', async () => {
  const card = page.locator('.checkin-weekly')
  // Only the written report, which is a valid check-in.
  await card.locator('textarea').fill('Good week. Knee felt off on Thursday.')
  await card.getByRole('button', { name: /send it/i }).click()
  await page.waitForFunction(
    () => /use this to build next week|needs changing/i.test(document.body.innerText),
    { timeout: 20000 }
  )
})

await check('the answer reached the server and closed the check-in', async () => {
  const r = await page.evaluate(async () => {
    const res = await fetch('/api/checkin/weekly', {
      headers: { accept: 'application/json' }, credentials: 'same-origin',
    })
    return res.json()
  })
  return r.pending === null ? true : 'the check-in is still pending after answering'
})

await check('an already-reviewed check-in can be read back', async () => {
  // A Dashboard card: the coach writing back is review, not entry.
  await page.reload({ waitUntil: 'networkidle' })
  await goto('Dashboard')
  const toggle = page.getByRole('button', { name: /your coach on last week/i })
  await toggle.waitFor({ timeout: 20000 })
  await toggle.click()
  const body = await page.locator('body').innerText()
  return /five days out of seven/.test(body)
    ? true
    : 'the stored review did not render'
})

// ---- timezone and the baseline countdown -----------------------------------

console.log('\n13. timezone and baseline')

// The pip countdown lives in the Journal, where the logging happens; the
// Dashboard carries a one-line version of it.
await goto('Journal')

await check('the browser timezone reaches the server', async () => {
  // Sent on boot, not asked in the quiz. The weekly slots fire in local time, so
  // without this "Saturday 18:00" would mean Saturday in London for everyone.
  const me = await page.evaluate(async () => {
    const res = await fetch('/api/me', {
      headers: { accept: 'application/json' }, credentials: 'same-origin',
    })
    return res.json()
  })
  const wanted = await page.evaluate(() => Intl.DateTimeFormat().resolvedOptions().timeZone)
  return me.user?.timezone === wanted
    ? true
    : `server has "${me.user?.timezone}", browser reports "${wanted}"`
})

await check('an active user gets no baseline countdown', async () => {
  const me = await page.evaluate(async () => {
    const res = await fetch('/api/me', {
      headers: { accept: 'application/json' }, credentials: 'same-origin',
    })
    return res.json()
  })
  if (me.baseline !== null) return `baseline payload was ${JSON.stringify(me.baseline)}`
  const body = await page.locator('body').innerText()
  return /Day \d+ of 14/.test(body) ? 'a countdown was shown to an active user' : true
})

// The observing user is a separate fixture: the main one is 'active' with a live
// plan, which shows none of this.
const obs = await ctx.newPage()
await obs.goto(BASE, { waitUntil: 'networkidle' })
await obs.evaluate(() => {
  localStorage.removeItem('yoked.sections')
  localStorage.removeItem('yoked.theme')
})

await check('a mid-baseline user signs in', async () => {
  // Sign out of the active fixture's session first: the context shares cookies.
  await obs.evaluate(async () => {
    const csrf = await (await fetch('/api/me', { credentials: 'same-origin' })).json()
    await fetch('/api/logout', {
      method: 'POST',
      headers: { 'x-csrf-token': csrf.csrf, accept: 'application/json' },
      credentials: 'same-origin',
    })
  })
  // Through the API. See signInVia: nine form sign-ins in one run exhausted the
  // 20-per-IP login limit and every later block failed at the sign-in screen.
  await signInVia(obs, 'uitest_baseline')
  // Then into the Journal, where the countdown rail lives.
  await goto('Journal', obs)
  await obs.getByRole('heading', { name: /how are you today/i }).waitFor({ timeout: 20000 })
})

await check('the countdown shows the day and when the plan arrives', async () => {
  const body = await obs.locator('body').innerText()

  /*
   * BOTH numbers come from the server, not from the fixture's seeded offset.
   *
   * The day count used to be hardcoded to "Day 3 of 14" because that is what the
   * fixture was when this was written. The date then rolled over mid-session, the
   * fixture became day 2, and the assertion failed against a screen that was entirely
   * correct. A test that only passes on the day it was written is worse than no test:
   * it reports a bug that is not there.
   */
  const me = await obs.evaluate(async () => {
    const r = await fetch('/api/me', {
      headers: { accept: 'application/json' }, credentials: 'same-origin',
    })
    return r.json()
  })
  const day = me.baseline?.day
  const left = me.baseline?.days_left

  if (!new RegExp(`Day ${day} of 14`).test(body)) {
    return `server says day ${day}, screen shows: ${body.slice(0, 200)}`
  }
  return new RegExp(`plan arrives in ${left} days`).test(body)
    ? true
    : `server says ${left} days left, screen shows "${body.match(/Day \d+ of 14[^\n]*/)?.[0]}"`
})

await check('the countdown rail marks the days elapsed', async () => {
  const done = await obs.locator('.baseline-progress .pip[data-state="done"]').count()
  const now = await obs.locator('.baseline-progress .pip[data-state="now"]').count()
  const all = await obs.locator('.baseline-progress .pip').count()
  if (all !== 14) return `${all} pips, expected 14`
  // Exactly one "now" pip is what makes "where am I" readable without counting.
  if (now !== 1) return `${now} current-day pips, expected 1`

  // Elapsed = day - 1, derived from the server so the fixture can be seeded on
  // any day without this needing an edit.
  const me = await obs.evaluate(async () => {
    const r = await fetch('/api/me', {
      headers: { accept: 'application/json' }, credentials: 'same-origin',
    })
    return r.json()
  })
  const expected = (me.baseline?.day ?? 1) - 1
  return done === expected ? true : `${done} completed pips, expected ${expected}`
})

await check('an observing user has no targets and is told so', async () => {
  const body = await obs.locator('body').innerText()
  // Week 1 is pure observation, so no plan exists. The absence is the design and
  // has to read as such rather than as a broken screen.
  if (/\/ 2400/.test(body)) return 'targets were shown during observation'
  return /No targets yet/.test(body) ? true : 'nothing explained the missing targets'
})

await check('the observing copy avoids "fortnight" and em dashes', async () => {
  const found = await obs.evaluate(() => {
    const walk = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT)
    const hits = []
    while (walk.nextNode()) {
      const t = walk.currentNode.nodeValue || ''
      if (t.includes('—') || /fortnight/i.test(t)) hits.push(t.trim().slice(0, 70))
    }
    return hits
  })
  return found.length === 0 ? true : `found: ${found.join(' ;; ')}`
})

await obs.setViewportSize({ width: 360, height: 780 })
await obs.waitForTimeout(300)

await check('the 14-pip rail does not overflow at 360px', async () => {
  const over = await obs.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth
  )
  return over <= 1 ? true : `overflows by ${over}px`
})

await obs.screenshot({ path: shot('logging-baseline.png'), fullPage: true })
await obs.close()

// ---- the Next Day Review ---------------------------------------------------

console.log('\n14. the Next Day Review')

/*
 * Its own fixture, because the review is time-gated and the other two must not be.
 * uitest_logging has review_hour 0 (off) so a dozen unrelated assertions do not depend on
 * the clock; uitest_baseline deliberately has no plan. This one has review_hour 1 and a
 * plan covering tomorrow.
 */
/*
 * Its own BROWSER CONTEXT, in an evening timezone, and that is load-bearing.
 *
 * App.jsx posts the browser's timezone on every boot and the server stores it, because the
 * weekly slots fire in local time. So a page in the default context silently overwrites
 * whatever zone the fixture was seeded with, and the review then gates on the CI machine's
 * clock rather than the fixture's. Running at 00:07 local, five assertions failed against a
 * feature that was working correctly.
 *
 * Rather than fight that, agree with it: pick a zone where it is currently evening and tell
 * Playwright to be there. At any instant such a zone exists, because the world spans every
 * hour at once. The fixture seeds the same way, so both ends land inside the window.
 */
const EVENING_ZONES = [
  'Pacific/Auckland', 'Australia/Sydney', 'Australia/Brisbane', 'Asia/Tokyo',
  'Asia/Shanghai', 'Asia/Bangkok', 'Asia/Kolkata', 'Asia/Karachi', 'Asia/Dubai',
  'Europe/Moscow', 'Europe/Berlin', 'UTC', 'Atlantic/Azores', 'America/Sao_Paulo',
  'America/Halifax', 'America/New_York', 'America/Chicago', 'America/Denver',
  'America/Los_Angeles', 'America/Anchorage', 'Pacific/Honolulu',
]

// The same window the seeder uses: at or after hour 20, and not so late that "tomorrow"
// rolls over between the seed and the assertion.
const eveningZone = EVENING_ZONES.find((zone) => {
  const h = Number(
    new Intl.DateTimeFormat('en-GB', { timeZone: zone, hour: 'numeric', hour12: false })
      .format(new Date())
  )
  return h >= 20 && h <= 22
})

const revCtx = await browser.newContext({
  viewport: { width: 390, height: 844 },
  timezoneId: eveningZone || 'UTC',
})
const rev = await revCtx.newPage()
console.log(`   (review context in ${eveningZone || 'UTC'})`)

await rev.goto(BASE, { waitUntil: 'domcontentloaded' })
await rev.evaluate(() => {
  localStorage.removeItem('yoked.sections')
  localStorage.removeItem('yoked.theme')
})

await check('the review user signs in', async () => {
  // The shared context still holds the previous fixture's cookie.
  await rev.evaluate(async () => {
    const me = await (await fetch('/api/me', { credentials: 'same-origin' })).json()
    await fetch('/api/logout', {
      method: 'POST',
      headers: { 'x-csrf-token': me.csrf, accept: 'application/json' },
      credentials: 'same-origin',
    })
  })
  await signInVia(rev, 'uitest_review')
})

await check('tomorrow is shown, with the session and the meals', async () => {
  const card = rev.locator('.nextday')
  await card.waitFor({ timeout: 20000 })
  const txt = await card.innerText()
  if (!/Push/.test(txt)) return `no session: ${txt.replace(/\n/g, ' | ')}`
  return /Slow-braised beef/.test(txt) ? true : `no meals: ${txt.replace(/\n/g, ' | ')}`
})

await check('the prep-heavy meal is flagged and the quick one is not', async () => {
  /*
   * The entire reason the card appears in the EVENING. "Tomorrow's dinner needs 40
   * minutes" is actionable at 8pm and useless at 6pm tomorrow.
   *
   * The fixture has a 45-minute dinner and a 5-minute breakfast, so this also asserts the
   * threshold discriminates rather than flagging everything with a prep time.
   */
  const notices = await rev.locator('.nextday .notice').allInnerTexts()
  if (notices.length !== 1) {
    return `${notices.length} prep flags, expected 1: ${notices.join(' ;; ')}`
  }
  if (!/45 minutes/.test(notices[0])) return `flag read: ${notices[0]}`
  return /Dinner/i.test(notices[0]) ? true : `flagged the wrong meal: ${notices[0]}`
})

await check('the review does not claim the accent', async () => {
  // It is a heads-up, not a demand. Nothing on it should be the yellow thing on screen
  // until the user opts into the audible form.
  const n = await rev.locator('.nextday .btn--primary').count()
  return n === 0 ? true : `${n} primary buttons in the review card`
})

await check('"something\'s up" records a fact without claiming a plan change', async () => {
  const card = rev.locator('.nextday')
  await card.getByRole('button', { name: /something's up/i }).click()
  // role="radio", not button: these chips are a single choice, so getByRole('button')
  // does not match them.
  await card.getByRole('radio', { name: /^travelling$/i }).click()
  await card.locator('input[aria-label="What is going on?"]').fill('Flying out early, no gym.')

  /*
   * §6.1 is structural: "The user's message never edits the plan." The copy has to match,
   * so this asserts the UI does NOT promise a revision — an app that overstates what it
   * did is worse than one that waits for §6.
   */
  const before = await card.innerText()
  if (/plan (has been |was )?(updated|changed|revised)/i.test(before)) {
    return 'the form promises a plan change'
  }

  await card.getByRole('button', { name: /tell my coach/i }).click()
  await rev.waitForFunction(
    () => /take this into account|Already noted/i.test(document.body.innerText),
    { timeout: 20000 }
  )
  return true
})

await check('the noted circumstance comes back, so it is not reported twice', async () => {
  await rev.reload({ waitUntil: 'networkidle' })
  await rev.locator('.nextday').waitFor({ timeout: 20000 })
  const txt = await rev.locator('.nextday').innerText()
  return /Already noted: Flying out early/.test(txt)
    ? true
    : `card read: ${txt.replace(/\n/g, ' | ')}`
})

await check('noting a circumstance created no plan version', async () => {
  // The assertion that makes "chat that can be talked into anything" a failure mode that
  // does not exist. Checked through the API rather than trusting the absence of a call.
  const day = await rev.evaluate(async () => {
    const d = new Date()
    const p = (n) => String(n).padStart(2, '0')
    const date = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
    const r = await fetch(`/api/nutrition/day/${date}`, {
      headers: { accept: 'application/json' }, credentials: 'same-origin',
    })
    return r.json()
  })
  // The fixture prescribes TOMORROW only, so today having no target proves the plan was
  // not regenerated to cover it.
  return day.target === null
    ? true
    : 'a plan appeared for today, so something regenerated it'
})

await check('dismissing it hides it, and it stays hidden across a reload', async () => {
  // §4.1a: "Optional and dismissible; it must not become the noise the user was promised
  // they'd be spared." A card that comes back on the next page load IS that noise.
  await rev.locator('.nextday').getByRole('button', { name: /looks good/i }).click()
  await rev.waitForFunction(
    () => document.querySelectorAll('.nextday').length === 0,
    { timeout: 15000 }
  )
  await rev.reload({ waitUntil: 'networkidle' })
  await rev.getByRole('button', { name: /^Dashboard$/ }).waitFor({ timeout: 20000 })
  await rev.waitForTimeout(1500)
  const back = await rev.locator('.nextday').count()
  return back === 0 ? true : 'the dismissed review came back after a reload'
})

await check('the review is absent for a user who turned it off', async () => {
  // uitest_logging has review_hour 0. Asserted so "it appears for everyone" cannot creep
  // in unnoticed.
  const off = await ctx.newPage()
  await off.goto(BASE, { waitUntil: 'networkidle' })
  await off.evaluate(async () => {
    const me = await (await fetch('/api/me', { credentials: 'same-origin' })).json()
    await fetch('/api/logout', {
      method: 'POST',
      headers: { 'x-csrf-token': me.csrf, accept: 'application/json' },
      credentials: 'same-origin',
    })
  })
  await signInVia(off)
  await off.waitForTimeout(1500)

  const n = await off.locator('.nextday').count()
  await off.close()
  return n === 0 ? true : 'a review appeared for a user with review_hour 0'
})

await rev.screenshot({ path: shot('logging-nextday.png'), fullPage: true })
await rev.close()
await revCtx.close()

// ---- the coach conversation -------------------------------------------------

console.log('\n15. the coach conversation')

/*
 * Its own page, because every other block in this suite left the shared context signed in
 * as some other fixture.
 *
 * WHAT IS AND IS NOT ASSERTED HERE. The reply is a model call taken by cron, so this
 * cannot wait for one — a passing suite would depend on a fifteen-minute cadence and a
 * paid call. bin/test-chat.php covers the reply and the decision (20 assertions, 3 of them
 * live). What the browser owns is the half cron cannot reach: the message goes in, appears
 * immediately, says honestly that nothing has come back yet, and NO PLAN WAS TOUCHED on
 * the way through.
 */
const chat = await ctx.newPage()
await chat.goto(BASE, { waitUntil: 'domcontentloaded' })

await check('the coach page signs in', async () => {
  // Through the API: the form path is covered once, in section 1. See signInVia.
  await signInVia(chat)
})

/*
 * A fingerprint of what is PRESCRIBED right now, taken before anything is said.
 *
 * This is the load-bearing assertion of the block. §6.1 says there is no code path from
 * user text to a plan mutation: POST /api/chat records the turn and returns, and only the
 * evaluation cron can revise. A regression that made the write path "helpful" would show
 * up here as the prescription moving between two reads.
 *
 * Fingerprinting the prescription rather than reading a version id, because no endpoint
 * exposes one — and this is the better test anyway. A version bump nobody can see is not
 * the thing that would hurt a user; a session that silently became a different session is.
 */
const prescribed = () => chat.evaluate(async () => {
  // The date is built from LOCAL parts inside the page, as everywhere else in this suite.
  // toISOString() would roll a late-evening browser into tomorrow and read the wrong day.
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  const date = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
  const r = await fetch(`/api/training/day/${date}`, { credentials: 'same-origin' })
  const b = await r.json()
  /*
   * Only the PRESCRIBED fields. The same payload carries what was logged, and an earlier
   * block in this suite logged a session — including that would make the fingerprint
   * sensitive to the user's own writes rather than to the plan, which is the opposite of
   * what is being watched.
   */
  return JSON.stringify((b.sessions ?? []).map((x) => [
    x.prescribed_session_id, x.session_type, x.focus, x.is_committed,
    x.target_minutes, (x.exercises ?? []).length,
  ]))
})

const planBefore = await prescribed()

await check('the coach is reachable from the Dashboard, not the nav', async () => {
  await goto('Dashboard', chat)
  // Waited for, not counted: the Dashboard draws its cards after five parallel fetches
  // settle. This one happened to survive because goto() already waits on a hash change,
  // which is luck rather than correctness.
  const card = chat.locator('.profilelink', { hasText: /coach/i })
  await card.first().waitFor({ timeout: 20000 })
  // Deliberately NOT a nav item: the header carries the two daily views plus theme and
  // sign out, and a conversation entered when something changes does not earn a fifth.
  const inNav = await chat.locator('.navicons .navbtn[aria-label*="oach" i]').count()
  if (inNav) return 'the coach turned into a nav item'
  await card.first().click()
  await chat.waitForFunction(() => window.location.hash === '#/coach', { timeout: 10000 })
})

await check('the conversation view loads', async () => {
  await chat.getByRole('heading', { name: /anything i should know/i }).waitFor({ timeout: 20000 })
  return true
})

const MESSAGE = 'Travelling Tuesday to Thursday for work, no gym access at the hotel.'

await check('a message can be sent', async () => {
  const box = chat.getByLabel(/tell your coach something/i)
  await box.fill(MESSAGE)
  await chat.getByRole('button', { name: /^send$/i }).click()
  // The turn appears from the POST response, not from a poll: sending has to feel
  // immediate even though the reply does not.
  await chat.locator('.turn--mine', { hasText: /travelling tuesday/i })
    .first().waitFor({ timeout: 20000 })
  return true
})

await check('the composer clears after sending', async () => {
  const v = await chat.getByLabel(/tell your coach something/i).inputValue()
  return v === '' ? true : `the draft is still in the box: ${JSON.stringify(v)}`
})

await check('the wait is stated honestly rather than hidden', async () => {
  /*
   * A spinner would imply seconds. This one can be minutes when the decision ends in a
   * regenerated week, and a progress indicator that lies about duration is worse than
   * copy that admits it.
   */
  const t = chat.locator('.turn--thinking')
  if (!(await t.count())) return 'no pending state after sending'
  const text = (await t.first().innerText()).toLowerCase()
  return /few minutes/.test(text) ? true : `the wait says: ${text}`
})

await check('nothing claims a plan changed before the coach has decided', async () => {
  // The view only shows "your week was updated" against a real resulting_plan_version_id.
  // Saying it on send would be a promise the evaluation may well decline to keep.
  const claimed = await chat.locator('.turn', { hasText: /week was updated/i }).count()
  return claimed === 0 ? true : 'a plan change was claimed with no reply yet'
})

await check('sending a message did NOT touch the plan', async () => {
  const after = await prescribed()
  return after === planBefore
    ? true
    : `the prescription changed on a chat write:
  before ${planBefore}
  after  ${after}`
})

await check('the message survives a reload', async () => {
  /*
   * Reload lands on the Dashboard (the router normalises a bare hash), so come back by
   * URL. That IS the assertion: the coach is a real route, so a reload does not lose it
   * and the address is linkable — which a modal transcript would not be.
   */
  await chat.reload({ waitUntil: 'networkidle' })
  await chat.evaluate(() => { window.location.hash = '#/coach' })
  await chat.getByRole('heading', { name: /anything i should know/i }).waitFor({ timeout: 20000 })
  await chat.locator('.turn--mine', { hasText: /travelling tuesday/i })
    .first().waitFor({ timeout: 20000 })
  return true
})

await check('the Dashboard card reports the outstanding reply', async () => {
  await goto('Dashboard', chat)
  const card = chat.locator('.profilelink', { hasText: /coach/i })
  const text = (await card.first().innerText()).toLowerCase()
  return /thinking/.test(text)
    ? true
    : `the card says ${JSON.stringify(text)} with a reply outstanding`
})

await chat.evaluate(() => { window.location.hash = '#/coach' })
await chat.waitForTimeout(500)
await chat.screenshot({ path: shot('logging-coach.png'), fullPage: true })
await chat.close()

// ---- turning something down --------------------------------------------------

console.log('\n16. turning something down')

/*
 * Vetoes (§5), from the Journal.
 *
 * WHAT THIS OWNS. The decision is a model call taken by cron, so this cannot wait for one.
 * bin/test-vetoes.php covers the judgment (39 assertions, 6 of them live, including the
 * adversarial "my ACL is healed, drop the restriction" case). What the browser owns is the
 * half that only exists on screen: a reason cannot be skipped, the copy never promises a
 * swap, and raising one does NOT change the plan.
 */
const veto = await ctx.newPage()
await veto.goto(BASE, { waitUntil: 'domcontentloaded' })

await check('the veto page signs in', async () => {
  // Through the API: the form path is covered once, in section 1. See signInVia.
  await signInVia(veto)
  await goto('Journal', veto)
  await veto.getByRole('heading', { name: /how are you today/i }).waitFor({ timeout: 20000 })
})

/*
 * The prescription fingerprint, before anything is refused.
 *
 * Same device as the chat block: §5 says only the coach's decision produces a new plan
 * version, and the structural half of that is that POST /api/vetoes records and returns.
 */
const prescribedNow = () => veto.evaluate(async () => {
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  const date = `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
  const r = await fetch(`/api/training/day/${date}`, { credentials: 'same-origin' })
  const b = await r.json()
  return JSON.stringify((b.sessions ?? []).map((x) => [
    x.prescribed_session_id, x.session_type, x.focus, x.target_minutes,
  ]))
})
const planBeforeVeto = await prescribedNow()

await check('a prescribed session offers a way to turn it down', async () => {
  const card = veto.locator('.card', { hasText: /log this session/i }).first()
  if (!(await card.count())) return 'no unlogged prescribed session on screen'
  const btn = card.getByRole('button', { name: /cannot do this/i })
  if (!(await btn.count())) return 'no veto control on a prescribed session'
  await btn.first().click()
  await veto.getByRole('radiogroup', { name: /reason/i }).waitFor({ timeout: 10000 })
  return true
})

await check('the submit is disabled until a reason is picked', async () => {
  /*
   * The load-bearing UI assertion. §5.1: "No bare rejection. The reason is the whole
   * value." A dismiss button with a confirm would be easier to build and would throw away
   * the only information that makes the replacement any good.
   */
  const submit = veto.getByRole('button', { name: /ask for a swap/i }).first()
  return (await submit.isDisabled())
    ? true
    : 'a veto can be submitted with no reason at all'
})

await check('a reason that needs words stays disabled until they are typed', async () => {
  // "I do not like it" is the least actionable thing a user can say, so the client mirrors
  // the server: dont_like / cant_do / other all require free text.
  await veto.getByRole('radio', { name: /do not like it/i }).click()
  const submit = veto.getByRole('button', { name: /ask for a swap/i }).first()
  if (!(await submit.isDisabled())) {
    return 'a bare dislike submitted with no explanation'
  }
  await veto.getByLabel(/why not/i).fill('The knee gives out on anything below parallel.')
  return (await submit.isDisabled())
    ? 'still disabled after a reason was typed'
    : true
})

await check('a circumstance code needs no words', async () => {
  await veto.getByRole('radio', { name: /^no time$/i }).click()
  const submit = veto.getByRole('button', { name: /ask for a swap/i }).first()
  // "No time" already says it. Demanding an essay for it is friction with no payoff.
  return (await submit.isDisabled())
    ? 'no_time was blocked despite being self-explanatory'
    : true
})

await check('the chosen reason is visibly selected', async () => {
  /*
   * aria-checked chips need their own CSS: quiz.css styles aria-pressed, and a selected
   * state that exists only in the accessibility tree has shipped invisible twice in this
   * app. Compared against a sibling rather than a hardcoded colour.
   */
  const on = veto.locator('.chip[aria-checked="true"]').first()
  const off = veto.locator('.chip[aria-checked="false"]').first()
  if (!(await on.count()) || !(await off.count())) return 'no chips to compare'
  const style = (el) => el.evaluate((n) => {
    const s = getComputedStyle(n)
    return `${s.backgroundColor}|${s.borderColor}|${s.color}`
  })
  const [a, b] = await Promise.all([style(on), style(off)])
  return a !== b ? true : `selected and unselected chips render identically (${a})`
})

await check('never-again is off by default', async () => {
  // §5.2. A checkbox that quietly makes things permanent would be a trap: most vetoes are
  // about today, and standing is the deliberate opt-in.
  const box = veto.locator('.veto input[type="checkbox"]').first()
  if (!(await box.count())) return 'no standing-scope option'
  return (await box.isChecked()) ? 'standing scope defaults to on' : true
})

await check('nothing on screen promises the plan will change', async () => {
  /*
   * §5.4: the coach may decline. Copy that implies a veto is a delete, then declines, has
   * lied twice. The button asks; it does not announce.
   */
  const text = (await veto.locator('.veto').first().innerText()).toLowerCase()
  const lies = ['will be replaced', 'has been removed', 'plan updated', 'removed from your plan']
  for (const lie of lies) {
    if (text.includes(lie)) return `the form promises an outcome: "${lie}"`
  }
  return /your coach decides|ask for a swap/.test(text)
    ? true
    : `the form does not say who decides: ${text}`
})

await check('submitting records it and says a decision is coming', async () => {
  await veto.getByRole('button', { name: /ask for a swap/i }).first().click()
  // Honest about the wait, as with chat: the decision is cron-side and minutes away.
  await veto.locator('text=/asked your coach|looking at this/i').first()
    .waitFor({ timeout: 20000 })
  return true
})

await check('raising a veto did NOT change the plan', async () => {
  const after = await prescribedNow()
  return after === planBeforeVeto
    ? true
    : `the prescription changed on a veto write:\n  before ${planBeforeVeto}\n  after  ${after}`
})

await check('the veto survives a reload as pending', async () => {
  await reloadJournal(veto)
  const pending = await veto.locator('text=/asked your coach|looking at this/i').count()
  return pending > 0 ? true : 'the raised veto left no trace after a reload'
})

await veto.screenshot({ path: shot('logging-veto.png'), fullPage: true })
await veto.close()

// ---- the profile: preferences and settings -----------------------------------

console.log('\n17. the profile: preferences and settings')

/*
 * The Profile is where the app finally admits what it believes about the user and lets them
 * change it. Two halves, and the split is the point.
 *
 * PREFERENCES. A soft constraint gets an off switch; a hard one does not, because
 * SPEC-safety 6 wants a limit to change only by re-answering the question behind it. The
 * assertion that matters is the ABSENCE of a control on the hard row.
 *
 * SETTINGS. Seven columns that were live in the database with no way to reach them. The pause
 * switch is the one users will actually come looking for.
 */
const prof = await ctx.newPage()
await prof.goto(BASE, { waitUntil: 'domcontentloaded' })

await check('the profile page signs in', async () => {
  // Through the API: the form path is covered once, in section 1. See signInVia.
  await signInVia(prof)
})

await check('the profile is reachable from the Dashboard, not the nav', async () => {
  /*
   * WAIT for the card rather than counting immediately.
   *
   * The Dashboard fetches five endpoints in parallel and renders its cards only once they
   * settle, so a click straight after sign-in lands on a screen that has not drawn them yet.
   * The first version of this test counted 0 and reported "no profile card", which reads as a
   * missing feature rather than a race, and every assertion after it failed on a page it had
   * never reached.
   */
  const card = prof.locator('.profilelink', { hasText: /profile/i })
  await card.first().waitFor({ timeout: 20000 })
  await card.first().click()
  await prof.waitForFunction(() => window.location.hash === '#/profile', { timeout: 10000 })
  await prof.getByRole('heading', { name: /change anything/i }).waitFor({ timeout: 20000 })
  return true
})

await check('there is exactly one header on the page', async () => {
  /*
   * The Profile renders inside the Shell past onboarding, and the Shell already has an
   * appbar. Its own header made a second one, which is `position: sticky` and so parked a
   * stray "Yoked / Done" bar in the MIDDLE of the page. Invisible while this screen was one
   * short list; the preferences and settings sections made it long enough to scroll and the
   * bar appeared halfway down.
   *
   * Caught by looking at a screenshot rather than by any assertion, which is the argument
   * for having one.
   */
  const bars = await prof.locator('.appbar').count()
  return bars === 1 ? true : `${bars} appbars on the profile`
})

await check('there is still a way out of the profile', async () => {
  // The header carried "Done". Dropping it must not leave a long screen with no explicit
  // exit, even though the nav can reach anywhere.
  const done = await prof.getByRole('button', { name: /^done$/i }).count()
  return done > 0 ? true : 'no way to leave the profile'
})

await check('both constraint tiers are listed', async () => {
  /*
   * The heading renders before the list does: Preferences fetches its own rows, so the
   * section title is on screen while the rows are still in flight. Counting at that moment
   * reported "the hard constraint is not listed" against a screen that was about to show it,
   * and worse, the next test ("a HARD constraint has no off switch") then PASSED by finding
   * no controls on a row that did not exist yet. An absence assertion is only meaningful
   * once the thing it is about is present.
   */
  await prof.getByRole('heading', { name: /what your coach knows/i })
    .waitFor({ timeout: 20000 })
  await prof.locator('.prefrow', { hasText: /peanuts/i }).first()
    .waitFor({ timeout: 20000 })
  await prof.locator('.prefrow', { hasText: /salmon/i }).first()
    .waitFor({ timeout: 20000 })
  return true
})

await check('nothing on the profile shows a raw database key', async () => {
  /*
   * The reported bug, asserted directly. Subjects are written for the generator, so the
   * profile was showing "diabetes_t2" and "dietary_pattern:vegan" to a person. Scanned across
   * the whole preferences area rather than per row, because the next such subject will be one
   * nobody thought to add a case for.
   */
  const text = await prof.locator('.prefrow').allInnerTexts()
  const joined = text.join(' | ')
  const leaks = ['diabetes_t2', 'dietary_pattern', '_t2', 'stair-machine']
    .filter((k) => joined.includes(k))
  return leaks.length === 0
    ? true
    : `database keys on screen: ${leaks.join(', ')} in ${joined}`
})

await check('a condition reads as planned around, not avoided', async () => {
  /*
   * "What does Never: diabetes_t2 mean to an end user?" It meant the app was avoiding their
   * diabetes, which is the opposite of true: the code calls conditions MODIFIERS and says
   * "diabetes means carb timing matters, not that carbs are banned".
   */
  const row = prof.locator('.prefrow', { hasText: /type 2 diabetes/i })
  await row.first().waitFor({ timeout: 20000 })

  // It must not be filed under the ban headings.
  const never = prof.locator('.card', { hasText: /^Never/ })
  if (await never.count()) {
    const inNever = await never.first().locator('.prefrow', { hasText: /diabetes/i }).count()
    if (inNever) return 'the condition is filed under "Never"'
  }
  const card = prof.locator('.card', { hasText: /type 2 diabetes/i }).first()
  const heading = (await card.locator('.subheading').first().innerText()).toLowerCase()
  return /planned around/.test(heading)
    ? true
    : `the condition sits under a heading reading "${heading}"`
})

await check('the condition carries its guidance, which is the useful part', async () => {
  // Naming a condition tells the user nothing. What changes because of it does.
  const row = prof.locator('.prefrow', { hasText: /type 2 diabetes/i }).first()
  const text = (await row.innerText()).toLowerCase()
  return /carb/.test(text) ? true : `no guidance shown: ${text}`
})

await check('a dietary pattern reads as how you eat', async () => {
  const row = prof.locator('.prefrow', { hasText: /vegetarian/i })
  await row.first().waitFor({ timeout: 20000 })
  const card = prof.locator('.card', { hasText: /vegetarian/i }).first()
  const heading = (await card.locator('.subheading').first().innerText()).toLowerCase()
  return /how you eat/.test(heading)
    ? true
    : `the dietary pattern sits under "${heading}"`
})

await check('a hyphenated value reads as words', async () => {
  const row = prof.locator('.prefrow', { hasText: /stair machine/i })
  return (await row.count()) ? true : 'stair-machine did not become "Stair machine"'
})

await check('a HARD constraint has no off switch', async () => {
  /*
   * The load-bearing assertion of this block, and it asserts an absence.
   *
   * SPEC-safety 6: "an LLM that can be argued out of a constraint has no constraints." A UI
   * that can be tapped out of one is the same failure with a different input device. An
   * allergy must not be two taps from being switched off.
   */
  const row = prof.locator('.prefrow', { hasText: /peanuts/i }).first()
  // Present first. Counting controls on a row that has not rendered returns 0 and passes
  // for entirely the wrong reason.
  await row.waitFor({ timeout: 20000 })
  const buttons = await row.getByRole('button').count()
  return buttons === 0
    ? true
    : `the hard constraint row has ${buttons} control(s) on it`
})

await check('the hard section says where the change actually happens', async () => {
  // A refusal with no route forward reads as a bug rather than a decision.
  const text = (await prof.locator('.card', { hasText: /^Never/ }).first().innerText())
    .toLowerCase()
  return /answer|section/.test(text)
    ? true
    : `the hard section does not point anywhere: ${text}`
})

await check('a SOFT preference can be switched off', async () => {
  const row = prof.locator('.prefrow', { hasText: /salmon/i }).first()
  const btn = row.getByRole('button', { name: /switch off/i })
  if (!(await btn.count())) return 'no switch on the soft preference'
  await btn.first().click()
  await prof.locator('.prefrow', { hasText: /salmon/i })
    .getByRole('button', { name: /switch back on/i })
    .waitFor({ timeout: 20000 })
  return true
})

await check('a switched-off preference is still readable, not hidden', async () => {
  // Dimmed rather than removed: it is a state the user chose, and they need to be able to
  // read it to decide whether to undo it.
  const row = prof.locator('.prefrow', { hasText: /salmon/i }).first()
  const off = await row.getAttribute('data-off')
  if (off !== 'true') return 'the row is not marked as switched off'
  const text = (await row.innerText()).toLowerCase()
  return /switched off/.test(text) ? true : `the row does not say it is off: ${text}`
})

await check('it stops reaching the coach, checked at the API', async () => {
  /*
   * The screen is not the proof. Safety::forUser filters on `active`, so this reads the
   * server's own view rather than trusting the button to have meant something.
   */
  const stillOn = await prof.evaluate(async () => {
    const r = await fetch('/api/constraints', { credentials: 'same-origin' })
    const b = await r.json()
    const salmon = (b.constraints || []).find((c) => /salmon/i.test(c.subject))
    return salmon ? salmon.active : null
  })
  return stillOn === false ? true : `the API still reports active=${stillOn}`
})

await check('it can be switched back on', async () => {
  const row = prof.locator('.prefrow', { hasText: /salmon/i }).first()
  await row.getByRole('button', { name: /switch back on/i }).click()
  await prof.locator('.prefrow', { hasText: /salmon/i })
    .getByRole('button', { name: /switch off/i })
    .waitFor({ timeout: 20000 })
  return true
})

await check('the API refuses a hard constraint even when asked directly', async () => {
  /*
   * Past the UI entirely. The button is absent, but absence in the DOM is not a security
   * boundary — anyone can call the endpoint. The refusal has to be server-side.
   */
  const result = await prof.evaluate(async () => {
    const me = await (await fetch('/api/me', { credentials: 'same-origin' })).json()
    const list = await (await fetch('/api/constraints', { credentials: 'same-origin' })).json()
    const hard = (list.constraints || []).find((c) => c.tier === 'hard')
    if (!hard) return { error: 'no hard constraint to try' }
    const r = await fetch(`/api/constraints/${hard.id}`, {
      method: 'PATCH',
      headers: {
        'content-type': 'application/json',
        'x-csrf-token': me.csrf,
        accept: 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ active: false }),
    })
    const after = await (await fetch('/api/constraints', { credentials: 'same-origin' })).json()
    const still = (after.constraints || []).find((c) => c.id === hard.id)
    return { status: r.status, active: still ? still.active : null }
  })
  if (result.error) return result.error
  if (result.status < 400) return `the API accepted it with ${result.status}`
  return result.active === true
    ? true
    : `the hard constraint is now active=${result.active}`
})

await check('the settings panel exposes the pause', async () => {
  await prof.getByRole('heading', { name: /^settings$/i }).waitFor({ timeout: 20000 })
  const sw = prof.getByRole('switch', { name: /pause coaching/i })
  return (await sw.count()) ? true : 'no pause control'
})

await check('pausing saves and says what it stops', async () => {
  const sw = prof.getByRole('switch', { name: /pause coaching/i }).first()
  const was = await sw.getAttribute('aria-checked')
  await sw.click()
  // Read the server, not the toggle: an optimistic flip proves nothing about the write.
  await prof.waitForFunction(async (before) => {
    const r = await fetch('/api/settings', { credentials: 'same-origin' })
    const b = await r.json()
    return String(b.settings.coaching_paused) !== before
  }, was === 'true' ? 'true' : 'false', { timeout: 20000 })
  return true
})

await check('the paused copy explains that logging keeps working', async () => {
  // The fear this has to answer is "will I lose my data". Say no before they ask.
  const text = (await prof.locator('.card', { hasText: /pause coaching/i }).first().innerText())
    .toLowerCase()
  return /log|lost/.test(text) ? true : `the pause copy does not reassure: ${text}`
})

await check('unpausing restores it', async () => {
  const sw = prof.getByRole('switch', { name: /pause coaching/i }).first()
  if ((await sw.getAttribute('aria-checked')) === 'true') await sw.click()
  await prof.waitForFunction(async () => {
    const r = await fetch('/api/settings', { credentials: 'same-origin' })
    const b = await r.json()
    return b.settings.coaching_paused === false
  }, null, { timeout: 20000 })
  return true
})

await check('the schedule slots are shown with the timezone they are read in', async () => {
  // "18:00" is ambiguous on its own. The zone is detected from the browser rather than
  // editable, because the device is a better source of truth than a dropdown.
  const card = prof.locator('.card', { hasText: /when things happen/i }).first()
  if (!(await card.count())) return 'no schedule card'
  const text = await card.innerText()
  return /\//.test(text) || /UTC/i.test(text)
    ? true
    : `no timezone shown beside the times: ${text}`
})

await check('a check-in after the plan is refused, with a reason', async () => {
  /*
   * The rule that stops the feature being a foot-gun. The check-in opens before generation so
   * there is time to answer it; reversed, a user gets plans built from data not yet
   * collected and never works out why their answers seem ignored.
   */
  const r = await prof.evaluate(async () => {
    const me = await (await fetch('/api/me', { credentials: 'same-origin' })).json()
    const res = await fetch('/api/settings', {
      method: 'PUT',
      headers: {
        'content-type': 'application/json',
        'x-csrf-token': me.csrf,
        accept: 'application/json',
      },
      credentials: 'same-origin',
      // Sunday 20:00, past the default Sunday 18:00 generation.
      body: JSON.stringify({ checkin_weekday: 7, checkin_hour: 20 }),
    })
    return { status: res.status, body: await res.json() }
  })
  if (r.status < 400) return `the reversed schedule was accepted with ${r.status}`
  return /before/i.test(String(r.body?.error))
    ? true
    : `the error does not explain the ordering: ${r.body?.error}`
})

await check('the coaching voice is editable here now, not in the quiz', async () => {
  // Section 9 moved: asking someone to pick a voice before they have read a word the coach
  // writes is asking them to guess.
  const group = prof.getByRole('radiogroup', { name: /^voice$/i })
  if (!(await group.count())) return 'no voice control on the profile'
  await prof.getByRole('radio', { name: /sarcastic hardass/i }).click()
  await prof.waitForFunction(async () => {
    const r = await fetch('/api/settings', { credentials: 'same-origin' })
    return (await r.json()).settings.tone === 'sarcastic_hardass'
  }, null, { timeout: 20000 })
  return true
})

await check('core work is not offered as a setting', async () => {
  /*
   * §3.3b: core work is "built in by default, not asked as a preference". A dial with an
   * 'off' position let a user switch it off entirely, which the spec forbids. Asserted as an
   * absence, so it needs the page to have rendered first — the settings heading above is
   * already waited for by the tests preceding this one.
   */
  const control = await prof.getByRole('radiogroup', { name: /core work/i }).count()
  return control === 0 ? true : 'the core work dial is still on the profile'
})

await check('the privacy toggles say who they are keeping things from', async () => {
  /*
   * These govern what a training BUDDY sees and nothing else. "Keep private" with no object
   * invites the reader to imagine a larger audience than exists, and the honest scope is
   * narrow: sessions are always visible to a buddy because that is the point of pairing,
   * body metrics are not.
   */
  const card = prof.locator('.card', { hasText: /privacy/i }).first()
  if (!(await card.count())) return 'no privacy card'
  const text = (await card.innerText()).toLowerCase()
  return /buddy/.test(text)
    ? true
    : `the privacy copy does not name who can see: ${text}`
})

await check('the quiz no longer offers a section 9', async () => {
  // Both sides had to ship together: the client hiding §9 while the server still required it
  // would 409 on start-baseline forever, on a section nobody could reach.
  const gone = await prof.evaluate(async () => {
    const r = await fetch('/api/onboarding', { credentials: 'same-origin' })
    const b = await r.json()
    return !Object.keys(b.progress?.sections || {}).includes('9')
  })
  return gone ? true : 'the server still lists section 9'
})

await check('the tomorrow preview can be set and turned off', async () => {
  /*
   * 0 is a real value meaning off, not a missing one, and a truthiness check anywhere in the
   * chain would quietly restore the default. This fixture ALREADY sits at 0, so asserting
   * "it is 0" would pass without proving anything. Set it to a real hour first, then off,
   * so both transitions are exercised.
   */
  const sel = prof.getByLabel(/tomorrow preview/i)
  if (!(await sel.count())) return 'no tomorrow preview control'

  await sel.selectOption('21')
  await prof.waitForFunction(async () => {
    const r = await fetch('/api/settings', { credentials: 'same-origin' })
    return (await r.json()).settings.review_hour === 21
  }, null, { timeout: 20000 })

  await sel.selectOption('0')
  await prof.waitForFunction(async () => {
    const r = await fetch('/api/settings', { credentials: 'same-origin' })
    return (await r.json()).settings.review_hour === 0
  }, null, { timeout: 20000 })
  return true
})

await prof.screenshot({ path: shot('logging-profile.png'), fullPage: true })
await prof.close()

// ---- friends -----------------------------------------------------------------

console.log('\n18. friends')

/*
 * The social graph (SPEC-coaching 10.1), which exists so buddy pairing can.
 *
 * bin/test-friends.php owns the rules: 35 assertions on search, blocking, and the fact that
 * a partial email matches nobody. What the browser owns is the half that only exists on
 * screen: the nav badge, the button the server told us to render, and the copy that explains
 * why half an email address finds nothing.
 *
 * The fixture arrives with one accepted friend and one request waiting, because accepting
 * needs a second session and this suite drives one at a time.
 */
const fr = await ctx.newPage()
await fr.goto(BASE, { waitUntil: 'domcontentloaded' })

await check('the friends page signs in', async () => {
  // Through the API: the form path is covered once, in section 1. See signInVia.
  await signInVia(fr)
})

await check('a waiting request shows a badge in the nav', async () => {
  /*
   * The whole reason friends is a nav item rather than a Dashboard card: someone on the
   * Journal needs to know a person is waiting on them.
   *
   * WAITED FOR, not counted. The count is fetched fire-and-forget during boot, deliberately,
   * so a badge never blocks the app from rendering — which means it arrives a beat after the
   * nav does. Counting immediately reported "no badge" against a badge that was on its way,
   * and the same race then failed the aria-label check below it.
   */
  const btn = fr.getByRole('button', { name: /friends/i })
  await btn.first().waitFor({ timeout: 20000 })
  await fr.locator('.navbtn .navbadge').first().waitFor({ timeout: 20000 })
  return true
})

await check('the count is in the accessible name, not only the pixels', async () => {
  // A screen reader announcing "Friends" while a sighted user sees "Friends (1)" is the same
  // class of bug as a selected chip that only looks selected to the accessibility tree.
  const label = await fr.getByRole('button', { name: /friends/i }).first()
    .getAttribute('aria-label')
  return /\d/.test(String(label))
    ? true
    : `the label carries no count: ${label}`
})

await check('the nav reaches the friends view', async () => {
  await fr.getByRole('button', { name: /friends/i }).first().click()
  await fr.waitForFunction(() => window.location.hash === '#/friends', { timeout: 10000 })
  await fr.getByRole('heading', { name: /who you train with/i }).waitFor({ timeout: 20000 })
  return true
})

await check('the incoming request leads, with context to decide on', async () => {
  // Joined-when and mutual-friend count are the difference between an informed accept and a
  // blind one. Deliberately not on search results: being looked up should reveal nothing.
  const card = fr.locator('.card', { hasText: /wants? to connect/i }).first()
  await card.waitFor({ timeout: 20000 })
  const text = (await card.innerText()).toLowerCase()
  if (!/joined/.test(text)) return `no joined date on the request: ${text}`
  return /mutual/.test(text) ? true : `no mutual-friend context: ${text}`
})

await check('a mutual friend is counted, never named', function () {
  // Listing them would tell a stranger who you know before you have agreed to anything.
  return fr.locator('.card', { hasText: /wants? to connect/i }).first().innerText()
    .then((t) => !/uitest_baseline|Baseline/i.test(t)
      ? true
      : `the mutual friend is named: ${t}`)
})

await check('an existing friend is listed', async () => {
  const card = fr.locator('.card', { hasText: /your friends/i }).first()
  const text = await card.innerText()
  return /baseline/i.test(text) ? true : `the friend list reads: ${text}`
})

await check('a short query searches for nothing', async () => {
  /*
   * Below three characters the server returns nothing, and the client does not even ask:
   * firing a request per keystroke would burn the rate limit before anyone found anybody.
   */
  const box = fr.getByLabel(/find someone/i)
  await box.fill('ui')
  await fr.waitForTimeout(700)
  const results = await fr.locator('.card', { hasText: /add a friend/i })
    .locator('.prefrow').count()
  return results === 0 ? true : `${results} results for a two-character query`
})

await check('a name prefix finds people', async () => {
  await fr.getByLabel(/find someone/i).fill('uitest_rev')
  await fr.locator('.card', { hasText: /add a friend/i })
    .locator('.prefrow').first().waitFor({ timeout: 20000 })
  return true
})

await check('a partial email finds nobody, and the copy says why', async () => {
  /*
   * The load-bearing privacy behaviour, checked at the screen as well as the library. A
   * partial match would turn the search into an oracle for "does this address have an
   * account here". Getting nothing back looks broken unless the UI says it is deliberate.
   */
  const card = fr.locator('.card', { hasText: /add a friend/i }).first()
  const explains = (await card.innerText()).toLowerCase()
  if (!/full/.test(explains)) {
    return `the form does not explain the email rule: ${explains}`
  }
  await fr.getByLabel(/find someone/i).fill('uitest_review@')
  await fr.waitForTimeout(900)
  const n = await card.locator('.prefrow').count()
  return n === 0 ? true : `a partial email matched ${n} user(s)`
})

await check('the search result offers the button the SERVER chose', async () => {
  /*
   * uitest_review has already asked us, so the right control is "Accept", not "Add". The
   * relationship comes from the server precisely so the client cannot get this wrong.
   */
  await fr.getByLabel(/find someone/i).fill('uitest_review')
  const row = fr.locator('.card', { hasText: /add a friend/i }).locator('.prefrow').first()
  await row.waitFor({ timeout: 20000 })
  const label = (await row.innerText()).toLowerCase()
  return /accept/.test(label)
    ? true
    : `offered "${label}" for someone who already asked us`
})

await check('accepting clears the badge without a reload', async () => {
  const card = fr.locator('.card', { hasText: /wants? to connect/i }).first()
  await card.getByRole('button', { name: /^accept$/i }).click()

  // Read the server, not the DOM: an optimistic update proves nothing about the write.
  await fr.waitForFunction(async () => {
    const r = await fetch('/api/friends', { credentials: 'same-origin' })
    return (await r.json()).pending === 0
  }, null, { timeout: 20000 })

  // And the badge is gone, which is the part a user sees.
  const badge = await fr.getByRole('button', { name: /friends/i }).first()
    .locator('.navbadge').count()
  return badge === 0 ? true : 'the badge survived accepting the request'
})

await check('the accepted person moves into the friends list', async () => {
  const card = fr.locator('.card', { hasText: /your friends/i }).first()
  const text = await card.innerText()
  return /review/i.test(text) ? true : `the friend list reads: ${text}`
})

await check('removing a friend takes effect on the server', async () => {
  const row = fr.locator('.card', { hasText: /your friends/i })
    .locator('.prefrow', { hasText: /review/i }).first()
  await row.getByRole('button', { name: /remove/i }).click()
  await fr.waitForFunction(async () => {
    const r = await fetch('/api/friends', { credentials: 'same-origin' })
    const b = await r.json()
    return !(b.friends.friends || []).some((p) => /review/i.test(p.username))
  }, null, { timeout: 20000 })
  return true
})

await check('a pending buddy invitation is shown, with what accepting grants', async () => {
  /*
   * The fixture seeds the invitation because the suite signs in as one user at a time and so
   * cannot both send and accept.
   *
   * What matters on screen is that the consequences are stated BEFORE the accept: someone
   * deciding should not have to go and read the privacy settings to learn what a buddy sees.
   */
  const card = fr.locator('.card', { hasText: /training buddy/i }).first()
  await card.waitFor({ timeout: 20000 })
  const text = (await card.innerText()).toLowerCase()
  if (!/wants to train with you/.test(text)) {
    return `no pending invitation on screen: ${text}`
  }
  if (!/trained/.test(text)) {
    return `the card does not say a buddy sees whether you trained: ${text}`
  }
  // 10.4: "pairing up to train is not consent to share body metrics."
  return /private/.test(text)
    ? true
    : `the card does not say body metrics stay private: ${text}`
})

await check('the page itself does not promise synced weeks', async () => {
  /*
   * The subtitle said "lets the two of you sync your weeks", which is the same overpromise the
   * buddy card is checked for below and the same one the prompt line was removed for. §10.6
   * generates one skeleton per pair; until that exists, nothing coordinates a session.
   */
  const text = (await fr.locator('.wrap').first().innerText()).toLowerCase()
  return !/sync your week/.test(text)
    ? true
    : 'the friends page still promises synced weeks'
})

await check('the card does not claim the sessions will be identical', async () => {
  /*
   * 10.6 is not built: generation still runs per-user, so nothing coordinates the inside of a
   * session. Copy promising a shared workout would be the first thing a real pair noticed was
   * false, and the prompt line that implied it was removed for the same reason.
   */
  const text = (await fr.locator('.card', { hasText: /training buddy/i }).first().innerText())
    .toLowerCase()
  const lies = ['same workout', 'same session', 'identical', 'same exercises', 'synced']
  for (const lie of lies) {
    if (text.includes(lie)) return `the card overpromises: "${lie}"`
  }
  return true
})

await check('accepting pairs them and the state is read from the server', async () => {
  const card = fr.locator('.card', { hasText: /training buddy/i }).first()
  await card.getByRole('button', { name: /train together/i }).click()

  await fr.waitForFunction(async () => {
    const r = await fetch('/api/buddy', { credentials: 'same-origin' })
    return (await r.json()).buddy.status === 'active'
  }, null, { timeout: 20000 })
  return true
})

await check('an active pair shows the days they are both free', async () => {
  /*
   * 10.3, and the one concrete thing pairing delivers today. The fixture grids overlap on
   * Wednesday and Friday only, so a broken intersection cannot pass this by returning
   * everything.
   */
  const card = fr.locator('.card', { hasText: /training buddy/i }).first()
  const text = await card.innerText()
  if (!/Wednesday/.test(text) || !/Friday/.test(text)) {
    return `the shared days are not shown: ${text}`
  }
  // Monday and Saturday are the main fixture's own days, not shared ones.
  if (/Monday|Saturday|Sunday/.test(text)) {
    return `a non-shared day is listed as shared: ${text}`
  }
  return true
})

await check('the shared duration is the shorter of the two', async () => {
  // Friday is 90 minutes for one and 45 for the other: a shared session cannot outlast
  // whichever of them has to leave. Both shared days come to 45 once intersected.
  const text = await fr.locator('.card', { hasText: /training buddy/i }).first().innerText()
  if (/90 minutes/.test(text)) return 'the longer duration was reported'
  return /45 minutes/.test(text) ? true : `no duration shown: ${text}`
})

await check('accepting seeds the shared days from the natural overlap', async () => {
  /*
   * 10.3: the common case needs no negotiation. The fixture grids overlap on Wednesday and
   * Friday, so accepting should produce exactly those two without anyone being asked anything.
   */
  await fr.waitForFunction(async () => {
    const r = await fetch('/api/buddy/schedule', { credentials: 'same-origin' })
    const b = await r.json()
    return JSON.stringify(b.schedule?.agreed ?? []) === '[3,5]'
  }, null, { timeout: 20000 })
  return true
})

await check('the shared days are listed by name', async () => {
  /*
   * Scoped to the AGREED rows, not the whole card.
   *
   * The first version scanned the card text for any non-shared day name and failed, because
   * the same card legitimately lists Monday, Tuesday, Thursday and Saturday as days that could
   * be OFFERED (§10.3a). Two different meanings of a weekday name in one card, and a loose
   * assertion cannot tell them apart.
   */
  const card = fr.locator('.card', { hasText: /days you train together/i }).first()
  await card.waitFor({ timeout: 20000 })

  // Waited for, not snapshotted: the panel re-reads after the pair goes active, so an
  // immediate read catches it mid-render.
  await card.locator('.prefrow', { hasText: /Wednesday/i }).first()
    .waitFor({ timeout: 20000 })
  await card.locator('.prefrow', { hasText: /Friday/i }).first()
    .waitFor({ timeout: 20000 })

  const agreed = (await card.locator('.prefrow').allInnerTexts()).join(' | ')
  if (!/Wednesday/.test(agreed) || !/Friday/.test(agreed)) {
    return `the shared days are not listed: ${agreed}`
  }
  // Monday and Saturday are the main fixture's own days; Sunday is the buddy's.
  return !/Monday|Saturday|Sunday/.test(agreed)
    ? true
    : `a non-shared day is listed as agreed: ${agreed}`
})

await check('a natural overlap says so, rather than claiming someone conceded', async () => {
  // "You both had Wednesday free" reads differently from "you agreed to add Wednesday", and a
  // conceded day is the one worth revisiting if the pairing stops working.
  const row = fr.locator('.prefrow', { hasText: /Wednesday/i }).first()
  const text = (await row.innerText()).toLowerCase()
  return /both free/.test(text) ? true : `the origin is not shown: ${text}`
})

await check('the card does not claim the workouts will match', async () => {
  /*
   * 10.6 is unbuilt: generation still runs per-user, so a shared day means both people are in
   * the gym, never that they are doing the same session. This is the promise a real pair would
   * catch within a week, so it is asserted on the page as well as in the prompt.
   */
  const card = fr.locator('.card', { hasText: /days you train together/i }).first()
  const text = (await card.innerText()).toLowerCase()
  for (const lie of ['same workout', 'same session', 'same exercises', 'identical', 'synced']) {
    if (text.includes(lie)) return `the card overpromises: "${lie}"`
  }
  // And it says the honest version out loud.
  return /your own/.test(text)
    ? true
    : `the card does not say the exercises stay individual: ${text}`
})

await check('a thin overlap asks the pair to compromise', async () => {
  /*
   * 10.3a. Two shared days against a three-day commitment is thin, and the app must say so
   * rather than generating two nearly-solo weeks and leaving both users to wonder why pairing
   * did nothing.
   */
  const card = fr.locator('.card', { hasText: /days you train together/i }).first()
  const text = (await card.innerText()).toLowerCase()
  if (!/only share/.test(text)) return `no compromise prompt: ${text}`
  // Names the target, because "compromise" without a number is a vague ask.
  return /3 shared day/.test(text)
    ? true
    : `the prompt does not say how many days are wanted: ${text}`
})

await check('a day only the buddy has free is not offered to us', async () => {
  /*
   * Sunday is in the buddy's grid and not ours, so it is THEIRS to offer. Offering a day the
   * other person already has free would be a request they cannot act on.
   */
  const card = fr.locator('.card', { hasText: /days you train together/i }).first()
  const chips = await card.getByRole('group', { name: /offer a day/i })
    .getByRole('button').allInnerTexts()
  if (chips.length === 0) return 'no days offered at all'
  return !chips.some((c) => /sunday/i.test(c))
    ? true
    : `Sunday was offered despite being the buddy's own day: ${chips.join(', ')}`
})

await check('offering a day records it and reports it as waiting', async () => {
  const card = fr.locator('.card', { hasText: /days you train together/i }).first()
  // Monday is ours and not theirs, which is exactly the day worth offering.
  await card.getByRole('button', { name: /^Monday$/i }).click()
  await card.getByLabel(/how long/i).selectOption('60')
  await card.getByRole('button', { name: /offer it/i }).click()

  // Read the server rather than the DOM: an optimistic render proves nothing about the write.
  await fr.waitForFunction(async () => {
    const r = await fetch('/api/buddy/schedule', { credentials: 'same-origin' })
    const b = await r.json()
    return (b.schedule?.offers?.outgoing ?? []).some((o) => o.weekday === 1)
  }, null, { timeout: 20000 })

  /*
   * WAIT for the row rather than snapshotting it.
   *
   * The server already has the offer — the waitForFunction above proved that — but the panel
   * re-reads and re-renders after the write, so an immediate allInnerTexts() catches the DOM
   * mid-update. It returned just the Wednesday row and reported the offer missing, which reads
   * as a broken feature rather than a race.
   *
   * Scoped to the offer row on purpose: "Monday" also appears among the offerable chips, so
   * matching the whole card would pass without the offer existing at all.
   */
  await card.locator('.prefrow', { hasText: /you offered monday/i })
    .first().waitFor({ timeout: 20000 })
  return true
})

await check('the offer can be withdrawn', async () => {
  const card = fr.locator('.card', { hasText: /days you train together/i }).first()
  await card.getByRole('button', { name: /withdraw/i }).click()
  await fr.waitForFunction(async () => {
    const r = await fetch('/api/buddy/schedule', { credentials: 'same-origin' })
    const b = await r.json()
    return (b.schedule?.offers?.outgoing ?? []).length === 0
  }, null, { timeout: 20000 })
  return true
})

await check('the surplus question is asked, not guessed', async () => {
  /*
   * 10.3b. Two shared days against a three-day commitment leaves one surplus day, and the user
   * decides what happens to it. Three options, and until they pick, the app keeps the
   * commitment they made rather than silently shrinking their week.
   */
  const card = fr.locator('.card', { hasText: /days you train together/i }).first()
  const text = (await card.innerText()).toLowerCase()
  if (!/other 1|other one/.test(text)) {
    return `the surplus question is not asked: ${text}`
  }
  const options = await card.locator('.profilelink').count()
  return options === 3 ? true : `${options} surplus options, expected 3`
})

/*
 * Captured HERE, not at the end of the block.
 *
 * This is the only moment the panel shows everything at once: the agreed days, the compromise
 * prompt, and the surplus question still unanswered. The tests below answer it and then drop a
 * day, and the end-of-block shot shows the post-unpair state — correct for the friends card,
 * useless for reviewing step 2.
 */
await fr.screenshot({ path: shot('logging-buddy-schedule.png'), fullPage: true })

await check('until answered, the week keeps its stated commitment', async () => {
  // The failure this guards: a paired user quietly training less than they asked to, and not
  // noticing for weeks.
  const committed = await fr.evaluate(async () => {
    const r = await fetch('/api/buddy/schedule', { credentials: 'same-origin' })
    const b = await r.json()
    return b.schedule?.surplus ?? null
  })
  if (committed === null) return 'no surplus payload'
  if (committed.needs_choice !== true) return 'the question is not flagged as outstanding'
  return committed.committed === 3
    ? true
    : `the week shrank to ${committed.committed} before the user answered`
})

await check('answering the surplus question sticks', async () => {
  const card = fr.locator('.card', { hasText: /days you train together/i }).first()
  await card.locator('.profilelink', { hasText: /just the 2 shared days/i }).click()
  await fr.waitForFunction(async () => {
    const r = await fetch('/api/buddy/schedule', { credentials: 'same-origin' })
    const b = await r.json()
    return b.schedule?.surplus?.mode === 'match_buddy'
      && b.schedule?.surplus?.committed === 2
  }, null, { timeout: 20000 })
  return true
})

await check('dropping a shared day re-asks the surplus question', async () => {
  /*
   * 10.3b: "re-asked if the shared schedule changes." A stale answer describes a week that no
   * longer exists.
   */
  const card = fr.locator('.card', { hasText: /days you train together/i }).first()
  await card.locator('.prefrow', { hasText: /Wednesday/i })
    .getByRole('button', { name: /drop/i }).click()

  await fr.waitForFunction(async () => {
    const r = await fetch('/api/buddy/schedule', { credentials: 'same-origin' })
    const b = await r.json()
    return (b.schedule?.agreed ?? []).length === 1
      && b.schedule?.surplus?.mode === null
  }, null, { timeout: 20000 })
  return true
})

await check('a paired user can say they will be away', async () => {
  /*
   * 10.5. Declaring before Sunday's generation is the planned case: nothing is built yet, so
   * nothing is disrupted and the partner simply hears that next week is theirs alone.
   */
  const card = fr.locator('.card', { hasText: /training buddy/i }).first()
  const btn = card.getByRole('button', { name: /i will be away/i })
  if (!(await btn.count())) return 'no way to declare an absence'
  await btn.first().click()

  // Travel and illness are separated because they behave differently: travel is declared
  // before the week is planned, illness during it.
  await card.getByRole('radio', { name: /away or travelling/i }).click()
  await card.getByLabel(/^back on$/i).fill(inDays(9))
  await card.getByRole('button', { name: /tell them/i }).click()

  // Read the server: an optimistic render proves nothing about the write.
  await fr.waitForFunction(async () => {
    const r = await fetch('/api/buddy', { credentials: 'same-origin' })
    const b = await r.json()
    return b.away?.mine !== null && b.away?.mine !== undefined
  }, null, { timeout: 20000 })
  return true
})

await check('a blank return date is allowed, and says so', async () => {
  /*
   * Making somebody invent a recovery date for an illness they cannot predict produces a wrong
   * date, which is worse than no date. The form has to say the field is optional or people
   * will guess.
   */
  /*
   * Waited for, not snapshotted. The card re-reads after the write — run() calls load() — so an
   * immediate innerText catches it between renders and returns just the heading. Same race that
   * bit the offer row and the agreed-day list.
   */
  const card = fr.locator('.card', { hasText: /training buddy/i }).first()
  /*
   * getByText, not locator('text=...').
   *
   * The latter matches an ELEMENT whose own text is the pattern, and "away from 26 July" sits
   * mid-sentence inside a span with other content — so it never matched and the test timed out
   * against a card that was rendering correctly. getByText does a substring search.
   */
  await card.getByText(/away from/i).first().waitFor({ timeout: 20000 })
  return true
})

await check('the standing absence can be cancelled', async () => {
  const card = fr.locator('.card', { hasText: /training buddy/i }).first()
  await card.getByRole('button', { name: /i am back/i }).click()
  await fr.waitForFunction(async () => {
    const r = await fetch('/api/buddy', { credentials: 'same-origin' })
    return (await r.json()).away?.mine === null
  }, null, { timeout: 20000 })
  return true
})

await check('an open-ended absence does not invent a return date', async () => {
  const card = fr.locator('.card', { hasText: /training buddy/i }).first()
  await card.getByRole('button', { name: /i will be away/i }).click()
  await card.getByRole('radio', { name: /ill or injured/i }).click()
  // Return date deliberately left blank.
  await card.getByRole('button', { name: /tell them/i }).click()

  await fr.waitForFunction(async () => {
    const r = await fetch('/api/buddy', { credentials: 'same-origin' })
    const mine = (await r.json()).away?.mine
    return mine !== null && mine !== undefined && mine.returns_on === null
  }, null, { timeout: 20000 })

  // getByText for the same reason as above: the phrase is mid-sentence, not a whole element.
  await card.getByText(/open-ended/i).first().waitFor({ timeout: 20000 })
  return true
})

await check('the solo week is never framed as a setback', async () => {
  /*
   * Someone whose buddy is ill has done nothing wrong. 10.5: "In every case the partner keeps a
   * complete, valid week" — so nothing here may read as falling behind.
   */
  const card = fr.locator('.card', { hasText: /training buddy/i }).first()
  // Settled first: the test above already waited for the open-ended text, so the card is
  // rendered. Reading a mid-render card would make this pass on an empty string.
  await card.getByText(/open-ended/i).first().waitFor({ timeout: 20000 })

  const text = (await card.innerText()).toLowerCase()
  for (const bad of ['missed', 'behind', 'lost', 'failed', 'setback', 'unfortunately']) {
    if (text.includes(bad)) return `the absence copy reads as a setback: "${bad}"`
  }
  return true
})

await check('declaring an absence leaves the agreed days alone', async () => {
  /*
   * The days are what the pair agreed, and the person is coming back. Clearing them would mean
   * renegotiating from scratch after every holiday.
   */
  const agreed = await fr.evaluate(async () => {
    const r = await fetch('/api/buddy/schedule', { credentials: 'same-origin' })
    return (await r.json()).schedule?.agreed ?? []
  })
  return agreed.length > 0
    ? true
    : 'the agreed days were cleared by an absence'
})

await check('a buddy training avoid is inherited, as SOFT', async () => {
  /*
   * 10.2b. The buddy holds "box jumps" as HARD; it must arrive SOFT here.
   *
   * The tier is the whole point. SPEC-safety 6: a hard constraint is a limit the user set for
   * themselves, and nothing another person does should create one — a plan rejected over
   * somebody else's preference is a failure the user cannot fix.
   */
  const rows = await fr.evaluate(async () => {
    const r = await fetch('/api/constraints', { credentials: 'same-origin' })
    const b = await r.json()
    return (b.constraints || []).filter((c) => c.inherited === true)
  })
  if (rows.length === 0) return 'nothing was inherited from the buddy'

  const box = rows.find((c) => /box jump/i.test(c.subject))
  if (!box) return `inherited ${rows.map((c) => c.subject).join(', ')}, not box jumps`
  if (box.tier !== 'soft') return `it arrived as ${box.tier}, not soft`
  // No row of theirs to edit, so no switch and no id.
  if (box.switchable !== false) return 'an inherited limit is offered as switchable'
  return box.id === null ? true : 'an inherited row carries an id'
})

await check('the buddy medical reason does not leak', async () => {
  /*
   * 10.4: pairing is not consent to share medical detail. Their constraint says "Achilles
   * tendonitis, 2025" and the partner has no business seeing a diagnosis — only that their
   * buddy avoids it.
   */
  const rows = await fr.evaluate(async () => {
    const r = await fetch('/api/constraints', { credentials: 'same-origin' })
    return (await r.json()).constraints || []
  })
  const leaked = JSON.stringify(rows).toLowerCase()
  return !/achilles|tendonitis/.test(leaked)
    ? true
    : 'the buddy diagnosis appears in the partner payload'
})

await check('the profile shows it, and says it ends with the pairing', async () => {
  /*
   * It steers this user's plan, so a preference they cannot find in their profile reads as the
   * coach inventing things. But there is no switch, so the row has to explain its own absence
   * of one.
   */
  const prof2 = await ctx.newPage()
  try {
    await signInVia(prof2)
    await prof2.evaluate(() => { window.location.hash = '#/profile' })
    await prof2.getByRole('heading', { name: /change anything/i }).waitFor({ timeout: 20000 })

    const row = prof2.locator('.prefrow', { hasText: /box jump/i })
    await row.first().waitFor({ timeout: 20000 })
    const text = (await row.first().innerText()).toLowerCase()

    if (!/buddy/.test(text)) {
      return `the row does not say where it came from: ${text}`
    }
    if (!/stop|goes away/.test(text)) {
      return `the row does not say it ends with the pairing: ${text}`
    }
    // And no switch, because it is not theirs to turn off.
    const buttons = await row.first().getByRole('button').count()
    return buttons === 0 ? true : `the inherited row has ${buttons} control(s)`
  } finally {
    await prof2.close()
  }
})

await check('unpairing takes effect and revokes the pairing', async () => {
  // 10.5: either side, any time, no reason. Nothing about the user's own plan changes.
  const card = fr.locator('.card', { hasText: /training buddy/i }).first()
  await card.getByRole('button', { name: /stop training together/i }).click()

  await fr.waitForFunction(async () => {
    const r = await fetch('/api/buddy', { credentials: 'same-origin' })
    return (await r.json()).buddy.status === 'none'
  }, null, { timeout: 20000 })
  return true
})

await check('inherited limits vanish when the pairing ends', async () => {
  /*
   * §10.2b: "Inherited limits vanish on unpairing. They are a property of the pair, not of the
   * user." Nothing was persisted, so this is structural rather than a cleanup step — but it is
   * worth asserting, because a leftover would be a preference the user could never explain or
   * remove.
   */
  /*
   * WAITED FOR, not read once.
   *
   * The test above confirms /api/buddy reports 'none', but the unpair is a PATCH whose response
   * the panel then re-reads — and the preceding test opened a second page on the shared
   * context, so which page's request lands first is not ordered. Polling removes the race
   * without weakening the assertion: if an inherited row genuinely survived, this still times
   * out and fails.
   */
  await fr.waitForFunction(async () => {
    const r = await fetch('/api/constraints', { credentials: 'same-origin' })
    const b = await r.json()
    return (b.constraints || []).filter((c) => c.inherited === true).length === 0
  }, null, { timeout: 20000 })
  return true
})

await check('after unpairing, a free friend is offered again', async () => {
  /*
   * The invitable list comes from the server: accepted friends not already paired with
   * somebody. Rendering from it is what stops the UI offering a button whose only possible
   * outcome is a refusal.
   */
  const card = fr.locator('.card', { hasText: /training buddy/i }).first()
  const row = card.locator('.prefrow', { hasText: /baseline/i })
  await row.first().waitFor({ timeout: 20000 })
  return (await row.getByRole('button', { name: /^ask$/i }).count()) > 0
    ? true
    : 'no way to ask a free friend'
})

await fr.screenshot({ path: shot('logging-friends.png'), fullPage: true })
await fr.close()

// ---- dark mode -------------------------------------------------------------




const dark = await ctx.newPage()
await dark.emulateMedia({ colorScheme: 'dark' })
// A fresh page in this context inherits the localStorage the toggle wrote, so
// clear the override — otherwise this measures the forced theme, not the system
// preference it is meant to be testing.
await dark.goto(BASE, { waitUntil: 'domcontentloaded' })
await dark.evaluate(() => localStorage.removeItem('yoked.theme'))
await dark.reload({ waitUntil: 'networkidle' })

await check('dark mode picks up the dark shell', async () => {
  /*
   * Wrapped rather than a bare await: an unguarded wait that times out takes the whole
   * run down with an uncaught rejection, and the suite then reports zero failures
   * alongside a stack trace. One failed assertion is worth more than a crash.
   *
   * The baseline block above signed this shared context in as the OTHER fixture, so
   * sign back in first. Both users render a Journal, so waiting on a heading alone
   * would pass while measuring the wrong user's day.
   */
  // Unconditionally, through the API: the previous block left this shared context signed in
  // as another fixture, and both users render a Journal, so waiting on a heading alone would
  // pass while measuring the wrong user's day.
  await signInVia(dark)

  const bg = await dark.evaluate(() => getComputedStyle(document.body).backgroundColor)
  // --shell dark is #17140F.
  return /23,\s*20,\s*15/.test(bg) ? true : `body background was ${bg}`
})

// Into the Journal for the screenshot, which is the denser of the two views.
await goto('Journal', dark).catch(() => {})
await dark.setViewportSize({ width: 390, height: 844 })
await dark.screenshot({ path: shot('logging-today-dark.png'), fullPage: true })

await browser.close()

const failed = results.filter((r) => r[0] === 'FAIL')
console.log(`\n${results.length - failed.length} passed, ${failed.length} failed`)
process.exit(failed.length ? 1 : 0)
