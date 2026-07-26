import { useEffect, useState } from 'react'
import { api } from '../api'

/**
 * What the coach knows and works around, and a switch for the parts that are preferences.
 *
 * THE FIRST VERSION GROUPED EVERYTHING UNDER "WHAT YOUR COACH AVOIDS", which was wrong for
 * half of it. A user saw "Never: diabetes_t2" and reasonably asked what that was supposed to
 * mean. Four genuinely different things live in one table:
 *
 *   avoid    food, movement, cardio. A ban, and the only one the old heading fitted.
 *   manage   a condition. A MODIFIER carrying guidance, explicitly not a ban: the code
 *            says "diabetes means carb timing matters, not that carbs are banned". Filing it
 *            under "never" told the user their diabetes was being avoided.
 *   eating   a dietary pattern. How the user eats, not something kept away from them.
 *   floor    a target floor. A MINIMUM, the exact opposite of avoidance.
 *
 * The server sends the facet, so the grouping cannot drift from the semantics.
 *
 * TWO TIERS, TWO TREATMENTS. A hard constraint is a limit the user set: an allergy, an
 * injury. It has no switch here, because SPEC-safety §6 wants it to change only by
 * re-answering the question behind it, and a limit two taps can remove is not a limit. A
 * soft one is a preference, including every veto-promoted one, and gets a switch.
 *
 * The client does not decide which is which: each row arrives with `switchable`, so this
 * cannot render a control the API refuses.
 */

/** Why the app believes this, in the user's terms rather than the schema's. */
const SOURCE_LABEL = {
  onboarding: 'from your answers',
  user_edit: 'you changed this',
  veto_promotion: 'you turned it down',
  claude_proposed: 'your coach suggested this',
}

/*
 * One group per facet, in the order they matter to a reader.
 *
 * Conditions first: they are the most consequential and the most alarming to see mislabelled.
 * Avoidance last, because it is the longest list and the least surprising.
 */
const GROUPS = [
  {
    facet: 'manage',
    title: 'Planned around',
    blurb: 'Health conditions your coach takes into account. These are not things being kept '
         + 'off your plan, they change how the plan is built.',
  },
  {
    facet: 'eating',
    title: 'How you eat',
    blurb: 'Your dietary pattern. Every meal you are given fits it.',
  },
  {
    facet: 'floor',
    title: 'Minimums',
    blurb: 'Numbers your coach will not go below.',
  },
  {
    facet: 'avoid',
    title: 'Kept off your plan',
    blurb: null,   // split by tier below, which needs its own wording each way
  },
]

export default function Preferences() {
  const [rows, setRows] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(0)

  const load = () => api.constraints.load()
    .then((r) => setRows(r.constraints))
    .catch((e) => setError(e.message || 'Could not load these.'))

  useEffect(() => { load() }, [])

  async function toggle(id, active) {
    setError(null)
    setBusy(id)
    try {
      const r = await api.constraints.setActive(id, active)
      setRows(r.constraints)
    } catch (e) {
      // Includes the 409 for a hard tier, which this UI should never trigger but which is
      // shown rather than swallowed if the two ever disagree.
      setError(e.message || 'That did not change.')
      load()
    } finally {
      setBusy(0)
    }
  }

  if (error && rows === null) return <p className="error">{error}</p>
  if (rows === null) return <p className="small muted">Loading…</p>
  if (rows.length === 0) {
    return (
      <p className="small muted prose" style={{ margin: 0 }}>
        Nothing on file yet. Allergies, conditions and injuries come from your answers, and
        anything you turn down for good shows up here.
      </p>
    )
  }

  const of = (facet) => rows.filter((r) => r.facet === facet)
  const avoidHard = of('avoid').filter((r) => r.tier === 'hard')
  const avoidSoft = of('avoid').filter((r) => r.tier === 'soft')

  return (
    <div className="stack">
      {GROUPS.filter((g) => g.facet !== 'avoid').map((g) => {
        const items = of(g.facet)
        if (items.length === 0) return null
        return (
          <div className="card stack-sm" key={g.facet}>
            <h3 className="subheading">{g.title}</h3>
            <p className="tiny muted prose" style={{ margin: 0 }}>{g.blurb}</p>
            {items.map((c) => (
              <Row
                key={c.id}
                c={c}
                busy={busy === c.id}
                onToggle={c.switchable ? () => toggle(c.id, !c.active) : null}
              />
            ))}
          </div>
        )
      })}

      {avoidHard.length > 0 && (
        <div className="card stack-sm">
          <h3 className="subheading">Never</h3>
          <p className="tiny muted prose" style={{ margin: 0 }}>
            Hard limits. Your coach cannot write a plan that breaks one, and a plan that
            tries is rejected before you ever see it.
          </p>
          {avoidHard.map((c) => <Row key={c.id} c={c} />)}
          {/* Where the door actually is. A refusal with no route forward reads as a bug. */}
          <p className="tiny muted prose" style={{ margin: 0 }}>
            These change by editing the answer they came from, in the sections above. That is
            deliberate: a limit you can switch off in two taps is not much of a limit.
          </p>
        </div>
      )}

      {avoidSoft.length > 0 && (
        <div className="card stack-sm">
          <h3 className="subheading">Strongly avoided</h3>
          <p className="tiny muted prose" style={{ margin: 0 }}>
            Preferences. Your coach steers around these, and can still suggest one with a
            reason.
          </p>
          {avoidSoft.map((c) => (
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
          {/* The server's readable label, not the stored subject. Subjects are written for
              the generator: diabetes_t2, dietary_pattern:vegan, stair-machine. */}
          {c.label || c.subject}
          {c.floor !== null && c.floor !== undefined && (
            <span className="num"> · {c.floor}</span>
          )}
        </span>
        <span className="tiny muted">
          {[SOURCE_LABEL[c.source] || c.source, c.reason].filter(Boolean).join(' · ')}
        </span>
        {/* The guidance is the useful part of a condition: it says what actually changes. */}
        {c.guidance && (
          <span className="tiny muted prose">{c.guidance}</span>
        )}
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
