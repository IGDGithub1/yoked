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
await page.reload({ waitUntil: 'networkidle' })
await page.getByRole('heading', { name: /how are you today/i }).waitFor({ timeout: 20000 })

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
      `\nThe fixture already has food logged (${logged.join(', ')}). Re-seed first:\n` +
      "  ssh … \"cd … && php bin/seed-uitest.php\"\n"
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

await page.reload({ waitUntil: 'networkidle' })
await page.getByRole('heading', { name: /how are you today/i }).waitFor({ timeout: 20000 })

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
  await page.reload({ waitUntil: 'networkidle' })
  await page.getByRole('heading', { name: /^Food$/ }).waitFor({ timeout: 20000 })
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
  await page.reload({ waitUntil: 'networkidle' })
  await page.getByRole('button', { name: /^Food$/ }).waitFor({ timeout: 20000 })
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
  await page.reload({ waitUntil: 'networkidle' })
  await page.getByRole('button', { name: /how are you today/i }).waitFor({ timeout: 20000 })
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
  await page.reload({ waitUntil: 'networkidle' })
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
  await page.reload({ waitUntil: 'networkidle' })
  await page.getByRole('heading', { name: /^Food$/ }).waitFor({ timeout: 20000 })
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

await check('an open check-in is surfaced with its week', async () => {
  const card = page.locator('.checkin-weekly')
  await card.waitFor({ timeout: 20000 })
  const txt = await card.innerText()
  // Which week, spelled out: the form opens on Saturday so "this week" is
  // ambiguous without the dates.
  return /\d+ to \d+ \w+/.test(txt) ? true : `card read: ${txt.replace(/\n/g, ' | ')}`
})

await check('an open check-in claims the only accent on the screen', async () => {
  /*
   * The accent rule has to hold ACROSS sections, not within each one.
   *
   * With both sections expanded and a check-in open there were briefly three
   * yellow buttons: "Fill it in", the next meal's "Ate as planned", and
   * Training's "Log a workout". Each was locally reasonable and together they
   * were exactly the "column of yellow buttons" the rule exists to prevent.
   * Food and Training now yield to the check-in.
   */
  for (const name of [/^Food$/, /^Training$/]) {
    const btn = page.getByRole('button', { name })
    if ((await btn.getAttribute('aria-expanded')) === 'false') await btn.click()
  }
  await page.locator('.card', { hasText: 'Breakfast' }).first().waitFor({ timeout: 15000 })

  /*
   * Counts PROMPTS, not submits.
   *
   * A form's submit button is legitimately the primary action while you are inside
   * that form: "Save it" in an open session form is not competing for attention,
   * it is the way out. What the rule is about is unsolicited yellow — buttons
   * offering to start something, several at once, down a scrolling page.
   */
  const found = await page.locator('.btn--primary:not([type="submit"])').allInnerTexts()
  if (found.length !== 1) {
    return `${found.length} yellow prompts with a check-in open: ${found.join(', ')}`
  }
  return /fill it in/i.test(found[0]) ? true : `the accent went to "${found[0]}"`
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
  await page.reload({ waitUntil: 'networkidle' })
  await page.getByRole('heading', { name: /^Food$/ }).waitFor({ timeout: 20000 })
  const toggle = page.getByRole('button', { name: /your coach on last week/i })
  await toggle.waitFor({ timeout: 20000 })
  await toggle.click()
  const body = await page.locator('body').innerText()
  return /five days out of seven/.test(body)
    ? true
    : 'the stored review did not render'
})

// ---- timezone and the baseline countdown -----------------------------------

console.log('\n12. timezone and baseline')

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
  await obs.reload({ waitUntil: 'networkidle' })

  const form = obs.locator('form.card')
  await form.waitFor({ timeout: 20000 })
  await form.locator('input[type="text"]').first().fill('uitest_baseline')
  await form.locator('input[type="password"]').first().fill(PASS)
  await form.getByRole('button', { name: /^sign in$/i }).click()
  await obs.getByRole('heading', { name: /how are you today/i }).waitFor({ timeout: 20000 })
})

await check('the countdown shows the day and when the plan arrives', async () => {
  const body = await obs.locator('body').innerText()
  if (!/Day 3 of 14/.test(body)) return `no day count: ${body.slice(0, 300)}`

  // Days remaining is asserted against the server rather than hardcoded: "day 3
  // of 14" leaves 12 days, not 11, because day 3 has not finished yet. Deriving
  // it here keeps the test honest whichever day the fixture is seeded on.
  const me = await obs.evaluate(async () => {
    const r = await fetch('/api/me', {
      headers: { accept: 'application/json' }, credentials: 'same-origin',
    })
    return r.json()
  })
  const left = me.baseline?.days_left
  return new RegExp(`plan arrives in ${left} days`).test(body)
    ? true
    : `server says ${left} days left, screen says "${body.match(/Day 3 of 14[^\n]*/)?.[0]}"`
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

// ---- dark mode -------------------------------------------------------------

const dark = await ctx.newPage()
await dark.emulateMedia({ colorScheme: 'dark' })
// A fresh page in this context inherits the localStorage the toggle wrote, so
// clear the override — otherwise this measures the forced theme, not the system
// preference it is meant to be testing.
await dark.goto(BASE, { waitUntil: 'domcontentloaded' })
await dark.evaluate(() => localStorage.removeItem('yoked.theme'))
await dark.reload({ waitUntil: 'networkidle' })

// The baseline block above signed this shared context in as the OTHER fixture, and
// both users render a Food section — so waiting on the heading alone would pass
// while screenshotting the wrong user's day. Sign back in explicitly.
{
  const form = dark.locator('form.card')
  if (await form.count()) {
    await form.locator('input[type="text"]').first().fill(USER)
    await form.locator('input[type="password"]').first().fill(PASS)
    await form.getByRole('button', { name: /^sign in$/i }).click()
  }
}
await dark.getByRole('heading', { name: /^Food$/ }).waitFor({ timeout: 20000 })
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
