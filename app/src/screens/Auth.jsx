import { useState } from 'react'
import { api, ApiError } from '../api'
import Yolk from '../components/Yolk'

/**
 * Sign in and sign up.
 *
 * Invite-only, so the sign-up form leads with the code — asking for a name and
 * password first and then rejecting the invite wastes the effort.
 */
export default function Auth({ onSignedIn }) {
  const [mode, setMode] = useState('signin')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [fields, setFields] = useState({
    identifier: '', password: '',
    invite: '', username: '', display_name: '', email: '',
  })

  const set = (k) => (e) => setFields((f) => ({ ...f, [k]: e.target.value }))

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const body =
        mode === 'signin'
          ? await api.login(fields.identifier, fields.password)
          : await api.register({
              invite: fields.invite.trim(),
              username: fields.username.trim(),
              display_name: fields.display_name.trim() || fields.username.trim(),
              email: fields.email.trim(),
              password: fields.password,
            })
      onSignedIn(body)
    } catch (err) {
      // The server writes user-facing copy for these; repeating it here would
      // mean two places to keep in sync and two chances to disagree.
      setError(err instanceof ApiError ? err.message : 'Could not reach the server.')
      setBusy(false)
    }
  }

  return (
    <div className="centred">
      <div className="stack-lg">
        <div className="row" style={{ justifyContent: 'center' }}>
          <Yolk pct={100} size={52} />
        </div>

        <div style={{ textAlign: 'center' }}>
          <h1 className="title">Yoked</h1>
          <p className="muted small" style={{ margin: '6px 0 0' }}>
            A coach that writes your week and adapts it as you go.
          </p>
        </div>

        <form className="card stack" onSubmit={submit}>
          {mode === 'signup' && (
            <>
              <label className="field">
                <span className="label">Invite code</span>
                <input
                  className="input"
                  type="text"
                  name="invite"
                  value={fields.invite}
                  onChange={set('invite')}
                  autoComplete="off"
                  autoCapitalize="characters"
                  spellCheck="false"
                  required
                />
                <p className="hint">Yoked is invite-only. Ask whoever sent you here.</p>
              </label>

              <label className="field">
                <span className="label">Your name</span>
                <input
                  className="input"
                  type="text"
                  name="display_name"
                  value={fields.display_name}
                  onChange={set('display_name')}
                  autoComplete="name"
                />
              </label>

              <label className="field">
                <span className="label">Username</span>
                <input
                  className="input"
                  type="text"
                  name="username"
                  value={fields.username}
                  onChange={set('username')}
                  autoComplete="username"
                  autoCapitalize="none"
                  spellCheck="false"
                  required
                />
                <p className="hint">Letters, numbers and underscores. Starts with a letter.</p>
              </label>

              <label className="field">
                <span className="label">Email</span>
                <input
                  className="input"
                  type="email"
                  name="email"
                  value={fields.email}
                  onChange={set('email')}
                  autoComplete="email"
                  autoCapitalize="none"
                  spellCheck="false"
                  required
                />
              </label>
            </>
          )}

          {mode === 'signin' && (
            <label className="field">
              <span className="label">Username or email</span>
              <input
                className="input"
                type="text"
                name="identifier"
                value={fields.identifier}
                onChange={set('identifier')}
                autoComplete="username"
                autoCapitalize="none"
                spellCheck="false"
                required
              />
            </label>
          )}

          <label className="field">
            <span className="label">Password</span>
            <input
              className="input"
              type="password"
              name="password"
              value={fields.password}
              onChange={set('password')}
              autoComplete={mode === 'signup' ? 'new-password' : 'current-password'}
              required
            />
            {mode === 'signup' && (
              // Length, not composition. Composition rules push people toward
              // Password1! and measurably weaken real choices.
              <p className="hint">At least 10 characters. A short sentence works well.</p>
            )}
          </label>

          {error && (
            <div role="alert">
              <p className="error">{error}</p>
            </div>
          )}

          <button className="btn btn--primary btn--wide" disabled={busy}>
            {busy ? 'One moment…' : mode === 'signin' ? 'Sign in' : 'Create account'}
          </button>
        </form>

        <p className="small muted" style={{ textAlign: 'center', margin: 0 }}>
          {mode === 'signin' ? 'Got an invite code?' : 'Already have an account?'}{' '}
          <button
            type="button"
            className="btn btn--quiet"
            onClick={() => {
              setMode(mode === 'signin' ? 'signup' : 'signin')
              setError(null)
            }}
          >
            {mode === 'signin' ? 'Create an account' : 'Sign in'}
          </button>
        </p>
      </div>
    </div>
  )
}
