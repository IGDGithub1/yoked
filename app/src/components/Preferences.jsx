import { useEffect, useState } from 'react'
import { api } from '../api'

/**
 * What the coach works around, and the switch for the parts that are preferences.
 *
 * Until now nothing in the app could retire a constraint. The Veto form told users a
 * standing veto could be undone in their profile, which was not true of anything that
 * existed. This is the screen that makes it true.
 *
 * TWO TIERS, TWO TREATMENTS, and the difference is the point.
 *
 * A HARD constraint is a limit the user set: an allergy, an injury. It has no switch here.
 * SPEC-safety §6 wants it to change only by re-answering the question behind it, and the
 * reasoning is that a limit two taps can remove is not a limit. So the row says where to go
 * instead of offering a control that would undercut it.
 *
 * A SOFT one is a preference, including every veto-promoted one, and gets a switch.
 *
 * The client does not decide which is which. Each row arrives with `switchable` from the
 * server, so the rule lives in one place and this cannot render a control the API refuses.
 */

/** Why the app believes this, in the user's terms rather than the schema's. */
const SOURCE_LABEL = {
  onboarding: 'from your answers',
  user_edit: 'you changed this',
  veto_promotion: 'you turned it down',
  claude_proposed: 'your coach suggested this',
}

export default function Preferences() {
  const [rows, setRows] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(0)

  const load = () => api.constraints.load()
    .then((r) => setRows(r.constraints))
    .catch((e) => setError(e.message || 'Could not load your preferences.'))

  useEffect(() => { load() }, [])

  async function toggle(id, active) {
    setError(null)
    setBusy(id)
    try {
      const r = await api.constraints.setActive(id, active)
      setRows(r.constraints)
    } catch (e) {
      // Includes the 409 for a hard tier, which should be unreachable from this UI but is
      // shown rather than swallowed if the two ever disagree.
      setError(e.message || 'That did not change.')
      load()
    } finally {
      setBusy(0)
    }
  }

  if (error && rows === null) return <p className="error">{error}</p>
  if (rows === null) return <p className="small muted">Loading your preferences…</p>
  if (rows.length === 0) {
    return (
      <p className="small muted prose" style={{ margin: 0 }}>
        Nothing on file yet. Allergies and injuries come from your answers, and anything you
        turn down for good shows up here.
      </p>
    )
  }

  const hard = rows.filter((r) => r.tier === 'hard')
  const soft = rows.filter((r) => r.tier === 'soft')

  return (
    <div className="stack">
      {hard.length > 0 && (
        <div className="card stack-sm">
          <h3 className="subheading">Never</h3>
          <p className="tiny muted prose" style={{ margin: 0 }}>
            Hard limits. Your coach cannot write a plan that breaks one, and a plan that
            tries is rejected before you ever see it.
          </p>
          {hard.map((c) => <Row key={c.id} c={c} />)}
          {/* Where the door actually is. A refusal with no route forward reads as a bug. */}
          <p className="tiny muted prose" style={{ margin: 0 }}>
            These change by editing the answer they came from, in the sections above. That is
            deliberate: a limit you can switch off in two taps is not much of a limit.
          </p>
        </div>
      )}

      {soft.length > 0 && (
        <div className="card stack-sm">
          <h3 className="subheading">Strongly avoided</h3>
          <p className="tiny muted prose" style={{ margin: 0 }}>
            Preferences. Your coach steers around these, and can still suggest one with a
            reason.
          </p>
          {soft.map((c) => (
            <Row
              key={c.id}
              c={c}
              busy={busy === c.id}
              onToggle={c.switchable ? () => toggle(c.id, !c.active) : null}
            />
          ))}
        </div>
      )}

      {error && <p className="error">{error}</p>}
    </div>
  )
}

function Row({ c, busy, onToggle }) {
  return (
    <div className="prefrow" data-off={c.active ? undefined : 'true'}>
      <span className="prefrow-body">
        <span className="small" style={{ fontWeight: 600 }}>
          {c.subject}
          {c.floor !== null && <span className="num"> · {c.floor}</span>}
        </span>
        <span className="tiny muted">
          {[SOURCE_LABEL[c.source] || c.source, c.reason].filter(Boolean).join(' · ')}
        </span>
        {/* Only when it is off. An active preference needs no explanation of its state. */}
        {!c.active && (
          <span className="tiny muted">Switched off. Your coach may suggest it again.</span>
        )}
      </span>

      {onToggle && (
        <button
          type="button"
          className="btn btn--quiet btn--small"
          disabled={busy}
          onClick={onToggle}
        >
          {/* "Switch back on" rather than "undo": this is a state, not a mistake. */}
          {busy ? '…' : c.active ? 'Switch off' : 'Switch back on'}
        </button>
      )}
    </div>
  )
}
