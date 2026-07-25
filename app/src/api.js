/**
 * API client.
 *
 * One place that knows about CSRF, because the token rotates on every auth
 * change — Auth::login() and logout both regenerate it as session-fixation
 * defense. A component that cached a token would break on the request after
 * login, so nothing outside this module touches it.
 */

let csrf = null

/** Adopt a token from any response that carries one. */
function absorb(body) {
  if (body && typeof body.csrf === 'string') csrf = body.csrf
  return body
}

/**
 * Thrown for any non-2xx. Carries the parsed body so callers can read
 * field-level detail (`errors`, `progress`, `csrf_failed`) rather than
 * re-parsing a message string.
 */
export class ApiError extends Error {
  constructor(status, body) {
    super(body?.error || `Request failed (${status})`)
    this.name = 'ApiError'
    this.status = status
    this.body = body || {}
  }
}

async function request(method, path, payload, { retryOnCsrf = true } = {}) {
  const headers = { accept: 'application/json' }
  if (payload !== undefined) headers['content-type'] = 'application/json'
  if (csrf && method !== 'GET') headers['x-csrf-token'] = csrf

  const res = await fetch(`/api/${path.replace(/^\//, '')}`, {
    method,
    headers,
    // Cookies ARE the session. Without this the API sees every request as
    // anonymous and nothing works.
    credentials: 'same-origin',
    body: payload !== undefined ? JSON.stringify(payload) : undefined,
  })

  let body = null
  try {
    body = await res.json()
  } catch {
    // A non-JSON body means something upstream of PHP answered — a proxy error
    // page, or a 502. Surface it as a real failure rather than a null.
    if (!res.ok) throw new ApiError(res.status, { error: 'The server returned an unexpected response.' })
  }

  absorb(body)

  if (!res.ok) {
    // A stale token is recoverable and not worth showing anyone: fetch a fresh
    // one and replay once. Guarded so a genuinely rejected request cannot loop.
    if (res.status === 403 && body?.csrf_failed && retryOnCsrf) {
      await get('csrf')
      return request(method, path, payload, { retryOnCsrf: false })
    }
    throw new ApiError(res.status, body)
  }

  return body
}

export const get = (path) => request('GET', path)
export const post = (path, payload) => request('POST', path, payload)
export const put = (path, payload) => request('PUT', path, payload)
export const patch = (path, payload) => request('PATCH', path, payload)
// DELETE carries no body, but it IS a mutating method, so it still needs the
// CSRF header — which `request` attaches to everything except GET.
export const del = (path) => request('DELETE', path)

/* ---- endpoints -------------------------------------------------------- */

export const api = {
  /** Session state on boot. Always 200 — "not logged in" is an answer, not an error. */
  me: () => get('me'),

  health: () => get('health'),

  register: (fields) => post('register', fields),
  login: (identifier, password) => post('login', { identifier, password }),
  logout: () => post('logout'),

  onboarding: {
    load: () => get('onboarding'),
    save: (answers) => put('onboarding', { answers }),
    constraints: () => get('onboarding/constraints'),
    confirmTier: (subject, tier) => post('onboarding/confirm-tier', { subject, tier }),
    startBaseline: () => post('onboarding/start-baseline'),
  },

  /*
   * Logging. Every mutating call here resolves to the whole day — the server
   * recomputes totals and the verdict on write, so callers replace their day
   * state from the response rather than patching it locally. That is what keeps
   * the goal vocabulary server-side.
   */
  nutrition: {
    day: (date) => get(`nutrition/day/${date}`),
    week: (start) => get(`nutrition/week/${start}`),

    addEntry: (date, slot, food) => post('nutrition/entries', { date, slot, ...food }),
    updateEntry: (id, fields) => patch(`nutrition/entries/${id}`, fields),
    deleteEntry: (id) => del(`nutrition/entries/${id}`),

    asPlanned: (date, slot) => post('nutrition/as-planned', { date, slot }),

    /* The delta is additive against entries but ABSOLUTE against itself: send
       the running total, not an increment. */
    setDelta: (date, slot, delta) => put(`nutrition/meals/${date}/${slot}`, { delta }),
    setAdherence: (date, slot, adherence) =>
      put(`nutrition/meals/${date}/${slot}`, { adherence }),
    setMealNotes: (date, slot, notes) => put(`nutrition/meals/${date}/${slot}`, { notes }),

    search: (query) => post('nutrition/search', { query }),
    barcode: (upc) => get(`nutrition/barcode/${upc}`),

    favorites: () => get('nutrition/favorites'),
    addFavorite: (food) => post('nutrition/favorites', food),
    renameFavorite: (id, fields) => patch(`nutrition/favorites/${id}`, fields),
    deleteFavorite: (id) => del(`nutrition/favorites/${id}`),
  },

  training: {
    day: (date) => get(`training/day/${date}`),
    /* Typeahead for free-logging. Not rate limited — one indexed LIKE against
       90 rows, unlike food search which is a paid model call. */
    exercises: (q) => get(`training/exercises?q=${encodeURIComponent(q)}`),
    /* A session and its exercises in ONE request: the user taps "done" once,
       and a half-written session after a dropped connection is worse than none. */
    logSession: (session) => post('training/sessions', session),
    deleteSession: (id) => del(`training/sessions/${id}`),
    history: (exercise) => get(`training/history/${exercise}`),
  },

  /** Partial saves are fine — one rating is a valid check-in. */
  checkin: (date, fields) => put(`checkin/${date}`, fields),
}

/**
 * Today's date in the USER's timezone, as YYYY-MM-DD.
 *
 * Storage is UTC throughout, but a log_date is a calendar day as the person
 * lived it — `toISOString()` would roll a 9pm meal in New York into tomorrow.
 * So the date is built from local parts, and every date in the logging UI comes
 * from here or from stepping this value.
 */
export function today() {
  return localDate(new Date())
}

export function localDate(d) {
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

/** Step a YYYY-MM-DD by whole days, staying in local time. */
export function shiftDate(date, days) {
  const [y, m, d] = date.split('-').map(Number)
  // New Date(y, m-1, d) is local midnight, so DST transitions move the clock
  // but never the calendar day — which is the thing being stepped.
  return localDate(new Date(y, m - 1, d + days))
}
