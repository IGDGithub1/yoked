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
  const r = await fetch('/api/login', {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      'x-csrf-token': fresh.csrf,
      accept: 'application/json',
    },
    credentials: 'same-origin',
    body: JSON.stringify({ identifier: 'uitest_logging', password: 'a-long-enough-passphrase' }),
  })
  if (r.status !== 200) throw new Error('login ' + r.status)
})
await p.reload({ waitUntil: 'networkidle' })
await p.evaluate(() => { window.location.hash = '#/friends' })
await p.getByRole('heading', { name: /who you train with/i }).waitFor({ timeout: 20000 })
await p.waitForTimeout(1500)

const inv = p.getByRole('button', { name: /train together/i })
if (await inv.count()) { await inv.first().click(); await p.waitForTimeout(2500) }

const card = p.locator('.card', { hasText: /training buddy/i }).first()
await card.waitFor({ timeout: 20000 })

// Open the form and declare an ILLNESS with no return date, as the failing test does.
await card.getByRole('button', { name: /i will be away/i }).click()
await p.waitForTimeout(400)
await card.getByRole('radio', { name: /ill or injured/i }).click()

console.log('--- the From field value before submitting:')
console.log(JSON.stringify(await card.getByLabel(/away from/i).inputValue()))

// Capture what the POST actually returns.
const [resp] = await Promise.all([
  p.waitForResponse((r) => r.url().includes('/api/buddy/away'), { timeout: 20000 }),
  card.getByRole('button', { name: /tell them/i }).click(),
])
console.log('\n--- POST /api/buddy/away ->', resp.status())
console.log(JSON.stringify(await resp.json()))

await p.waitForTimeout(2000)
console.log('\n--- away payload now:')
console.log(JSON.stringify(await p.evaluate(async () => {
  const r = await fetch('/api/buddy', { credentials: 'same-origin' })
  return (await r.json()).away
})))

console.log('\n--- card text:')
console.log(JSON.stringify(await card.innerText()))

await b.close()
