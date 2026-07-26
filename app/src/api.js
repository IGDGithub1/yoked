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

  /**
   * Tell the server where the user is.
   *
   * Sent on boot rather than asked in the quiz: the browser already knows this
   * accurately, and it self-corrects when someone travels. It is the only place a
   * timezone changes behaviour rather than presentation — the weekly slots fire in
   * local time, so "Saturday 18:00" means Saturday evening where the user is.
   */
  setTimezone: (timezone) => put('timezone', { timezone }),

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

  /*
   * The WEEKLY check-in, which is a different thing from the daily one above:
   * weight, measurements, and the user's read on the week that just ended.
   */
  /*
   * Nudges and coach messages. In-app only: §9 weighed web push and declined it,
   * since VAPID keys plus iOS home-screen installation is real work for a handful of
   * users who open the app anyway.
   */
  notifications: {
    load: () => get('notifications'),
    read: (ids) => post('notifications/read', { ids }),
    readAll: () => post('notifications/read', { all: true }),
  },

  /*
   * Interjections (§6). POST records and returns; the reply arrives later, because the
   * evaluation is a model call and can be minutes when it ends in a plan revision.
   *
   * That split is also the structural half of §6.1 — the write path cannot touch a plan.
   */
  chat: {
    load: () => get('chat'),
    send: (message) => post('chat', { message }),
  },

  /*
   * Vetoes (§5). Turning down one prescribed thing, with a reason.
   *
   * Same shape as chat and for the same reason: raising a veto records it and returns 202,
   * because an accepted one regenerates the week and that takes minutes. Nothing here
   * changes a plan — only the coach's decision does (§5.4 means it may decline).
   */
  vetoes: {
    load: () => get('vetoes'),
    raise: (fields) => post('vetoes', fields),
  },

  /*
   * The settings the quiz never asked: schedule slots, core emphasis, and the pause switch.
   * Tone, nudges and units are §9 quiz answers and are edited there, not here.
   *
   * PUT is partial — send only what changed. An absent key means "leave it alone", so
   * toggling one thing cannot reset another.
   */
  settings: {
    load: () => get('settings'),
    save: (fields) => put('settings', fields),
  },

  /*
   * Constraints as an EDITOR, distinct from onboarding.constraints() above, which is the
   * read-only post-quiz list. This one returns ids, includes switched-off rows, and can
   * change one.
   *
   * Soft only. The server sends `switchable` per row and refuses a hard tier with a 409, so
   * the client renders the control from that flag rather than deciding for itself.
   */
  /*
   * Friends (§10.1). A prerequisite for buddy pairing rather than a social feature.
   *
   * Search is deliberately narrow: username and display name match on a prefix, email only
   * in full. The server decides that, and every result carries a `relationship` so the UI
   * renders its button from the server's view rather than guessing.
   */
  friends: {
    load: () => get('friends'),
    search: (q) => get(`friends/search?q=${encodeURIComponent(q)}`),
    request: (userId) => post('friends', { user_id: userId }),
    /* accept | decline | remove | block | unblock */
    act: (userId, action) => patch(`friends/${userId}`, { action }),
  },

  /*
   * Buddy pairing (§10). Sits alongside friends because it is reached from that screen, but
   * its own endpoint: the friends list is read on every boot for the nav badge, and the
   * pairing state carries an availability intersection nothing else needs.
   *
   * No id on the PATCH, because a user has at most one pair — the spec describes a pair
   * rather than a group and the schema has no notion of a set.
   */
  buddy: {
    load: () => get('buddy'),
    invite: (userId) => post('buddy', { user_id: userId }),
    /* accept | decline | unpair */
    act: (action) => patch('buddy', { action }),
  },

  constraints: {
    load: () => get('constraints'),
    setActive: (id, active) => patch(`constraints/${id}`, { active }),
  },

  /*
   * The Next Day Review (§4.1a). Whether it should appear at all is the SERVER's
   * decision: the evening hour is per-user in their own timezone, and a client computing
   * it would be a second implementation differing exactly at the boundary that matters.
   */
  tomorrow: {
    load: () => get('tomorrow'),
    dismiss: () => post('tomorrow/dismiss'),
    /* Records a fact. It never edits the plan (§6.1). */
    note: (fields) => post('tomorrow/circumstance', fields),
  },

  weekly: {
    load: () => get('checkin/weekly'),
    answer: (id, fields) => put(`checkin/weekly/${id}`, fields),
    /* A deliberate pass, which stops the nudges. Ignoring it does not. */
    skip: (id) => post(`checkin/weekly/${id}/skip`),
  },
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
