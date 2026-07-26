import { chromium } from 'playwright'

const BASE = 'https://yoked.lil-boxes.com'
const b = await chromium.launch()
const p = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage()

// Sign in through the API, as the suite now does.
await p.goto(BASE, { waitUntil: 'domcontentloaded' })
await p.evaluate(async () => {
  const fresh = await (await fetch('/api/csrf', { credentials: 'same-origin' })).json()
  await fetch('/api/login', {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      'x-csrf-token': fresh.csrf,
      accept: 'application/json',
    },
    credentials: 'same-origin',
    body: JSON.stringify({ identifier: 'uitest_logging', password: 'a-long-enough-passphrase' }),
  })
})
await p.reload({ waitUntil: 'networkidle' })
await p.evaluate(() => { window.location.hash = '#/friends' })
await p.getByRole('heading', { name: /who you train with/i }).waitFor({ timeout: 20000 })
await p.waitForTimeout(1200)

// Accept the buddy invite if it is still pending.
const inv = p.getByRole('button', { name: /train together/i })
if (await inv.count()) { await inv.first().click(); await p.waitForTimeout(2000) }

const card = p.locator('.card', { hasText: /days you train together/i }).first()
await card.waitFor({ timeout: 20000 })

console.log('--- rows BEFORE offering:')
console.log(JSON.stringify(await card.locator('.prefrow').allInnerTexts(), null, 1))

// Offer Monday exactly as the test does.
await card.getByRole('button', { name: /^Monday$/i }).click()
await card.getByLabel(/how long/i).selectOption('60')
await card.getByRole('button', { name: /offer it/i }).click()
await p.waitForTimeout(2500)

console.log('\n--- API offers:')
console.log(JSON.stringify(await p.evaluate(async () => {
  const r = await fetch('/api/buddy/schedule', { credentials: 'same-origin' })
  const b = await r.json()
  return { agreed: b.schedule?.agreed, offers: b.schedule?.offers }
}), null, 1))

console.log('\n--- rows AFTER offering:')
console.log(JSON.stringify(await card.locator('.prefrow').allInnerTexts(), null, 1))

await b.close()
