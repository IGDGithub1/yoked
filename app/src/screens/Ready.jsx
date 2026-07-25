import { useEffect, useState } from 'react'
import { api, ApiError } from '../api'
import Yolk from '../components/Yolk'

/**
 * The quiz is done; the baseline has not started.
 *
 * A deliberate act rather than a side-effect of answering the last question —
 * the user is told what the fortnight is for and agrees to start it. Starting it
 * silently would mean two weeks of logging with no explanation of why.
 */
export default function Ready({ onStarted, onReview }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [constraints, setConstraints] = useState(null)

  // Show what the answers produced. The safety model only works if the user can
  // see what the app believes about them — a mis-tiered constraint is far easier
  // to spot in a list than by recalling a form.
  useEffect(() => {
    api.onboarding
      .constraints()
      .then((r) => setConstraints(r.constraints || []))
      .catch(() => setConstraints([]))
  }, [])

  async function start() {
    setBusy(true)
    setError(null)
    try {
      await api.onboarding.startBaseline()
      onStarted()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not start. Try again.')
      setBusy(false)
    }
  }

  const hard = (constraints || []).filter((c) => c.tier === 'hard')
  const soft = (constraints || []).filter((c) => c.tier === 'soft')

  return (
    <div className="wrap stack-lg">
      <div className="row" style={{ justifyContent: 'center' }}>
        <Yolk pct={100} size={56} />
      </div>

      <div style={{ textAlign: 'center' }}>
        <h1 className="title">That is everything.</h1>
        <p className="muted prose" style={{ margin: '10px auto 0' }}>
          Next is two weeks of logging what you normally eat and do. Week one your
          coach only watches — no plan, no targets. A provisional plan arrives after
          it, and the real one once the fortnight is done.
        </p>
      </div>

      <div className="card stack">
        <h2 className="subheading">Why two weeks</h2>
        <p className="small muted prose" style={{ margin: 0 }}>
          What you told us is a starting point. What you actually do is the thing worth
          building on, and one week can be a strange week. You will get a plan after
          seven days so there is something to follow while the second week sharpens it.
        </p>
      </div>

      {constraints && constraints.length > 0 && (
        <div className="card stack">
          <h2 className="subheading">What your coach will never do</h2>
          <p className="small muted" style={{ margin: 0 }}>
            Built from your answers. You can change any of these later.
          </p>

          {hard.length > 0 && (
            <ul className="clist">
              {hard.map((c, i) => (
                <li key={i}>
                  <span className="clist-tier clist-tier--hard">Never</span>
                  <span>
                    <strong>{c.subject.replace(/_/g, ' ')}</strong>
                    {c.reason && <span className="muted"> — {c.reason}</span>}
                  </span>
                </li>
              ))}
            </ul>
          )}

          {soft.length > 0 && (
            <>
              <p className="small" style={{ margin: '4px 0 0', fontWeight: 600 }}>
                Strongly avoided
              </p>
              <ul className="clist">
                {soft.map((c, i) => (
                  <li key={i}>
                    <span className="clist-tier">Avoid</span>
                    <span>
                      <strong>{c.subject.replace(/_/g, ' ')}</strong>
                      {c.progression?.status === 'working_toward' && (
                        <span className="muted"> — working back up to it</span>
                      )}
                    </span>
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>
      )}

      {error && (
        <div role="alert">
          <p className="error">{error}</p>
        </div>
      )}

      <button className="btn btn--primary btn--wide" onClick={start} disabled={busy}>
        {busy ? 'Starting…' : 'Start logging'}
      </button>

      {/* This screen is where a wrong answer is most likely to be noticed — it
          shows what the answers produced — so the way back has to be here. */}
      {onReview && (
        <button
          type="button"
          className="btn btn--quiet btn--wide"
          onClick={onReview}
          disabled={busy}
        >
          Change an answer first
        </button>
      )}
    </div>
  )
}
