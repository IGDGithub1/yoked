import { chromium } from 'playwright'

const BASE = 'https://yoked.lil-boxes.com'
const b = await chromium.launch()
const p = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage()

await p.goto(BASE, { waitUntil: 'domcontentloaded' })
await p.evaluate(async () => {
  const me = await (await fetch('/api/me', { credentials: 'same-origin' })).json()
  if (me.authenticated) {
    await fetch('/api/logout', {
      method: 'POST',
      headers: { 'x-csrf-token': me.csrf, accept: 'application/json' },
      credentials: 'same-origin',
    })
  }
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
await p.waitForTimeout(1500)

// Accept the buddy invite if pending.
const inv = p.getByRole('button', { name: /train together/i })
if (await inv.count()) { await inv.first().click(); await p.waitForTimeout(2500) }

const card = p.locator('.card', { hasText: /training buddy/i }).first()
await card.waitFor({ timeout: 20000 })
console.log('--- card BEFORE declaring:')
console.log(JSON.stringify(await card.innerText()))

// Declare an open-ended illness, exactly as the test does.
await card.getByRole('button', { name: /i will be away/i }).click()
await card.getByRole('radio', { name: /ill or injured/i }).click()
await card.getByRole('button', { name: /tell them/i }).click()
await p.waitForTimeout(3000)

console.log('\n--- API /api/buddy away payload:')
console.log(JSON.stringify(await p.evaluate(async () => {
  const r = await fetch('/api/buddy', { credentials: 'same-origin' })
  return (await r.json()).away
}), null, 1))

console.log('\n--- card AFTER declaring:')
console.log(JSON.stringify(await card.innerText()))

await b.close()
