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
}
