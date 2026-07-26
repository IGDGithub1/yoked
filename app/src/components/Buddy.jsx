import { useEffect, useState } from 'react'
import { api } from '../api'

/**
 * Your training buddy (SPEC-coaching §10).
 *
 * "Unpaid accountability is the most effective adherence mechanism the app has." One person
 * needs a reason to show up; the other needs someone watching so she does not coast.
 *
 * WHAT THIS PROMISES, AND ONLY THIS. Pairing means your buddy can see whether you trained,
 * and shows you the days you are both free. It does NOT yet mean you get the same session:
 * §10.6 wants one skeleton generated per pair and each person's prescriptions written against
 * it, which is a change to how generation works rather than a flag on it. So the copy says
 * "you both train Tuesday", never "you are doing the same workout". Overstating that would be
 * the first thing a real pair noticed was false.
 *
 * Body metrics stay private regardless (§10.4): "pairing up to train is not consent to share
 * body metrics." Said out loud here, because someone deciding whether to accept should not
 * have to go and read the privacy settings to find out.
 */
export default function Buddy({ onChanged }) {
  const [b, setB] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  const load = () => api.buddy.load()
    .then((r) => setB(r.buddy))
    .catch((e) => setError(e.message || 'Could not load this.'))

  useEffect(() => { load() }, [])

  async function run(fn) {
    setError(null)
    setBusy(true)
    try {
      const r = await fn()
      setB(r.buddy)
      // Pairing changes who can see what, and the friends list shows who is invitable.
      onChanged?.()
    } catch (e) {
      setError(e.message || 'That did not work.')
      load()
    } finally {
      setBusy(false)
    }
  }

  if (error && b === null) return <p className="error">{error}</p>
  if (b === null) return null

  return (
    <div className="card stack-sm">
      <h3 className="subheading">Training buddy</h3>

      {b.status === 'active' && <Active b={b} busy={busy} run={run} />}
      {b.status === 'pending_in' && <Incoming b={b} busy={busy} run={run} />}
      {b.status === 'pending_out' && <Outgoing b={b} busy={busy} run={run} />}
      {b.status === 'none' && <Invite b={b} busy={busy} run={run} />}

      {error && <p className="error tiny">{error}</p>}
    </div>
  )
}

function Active({ b, busy, run }) {
  return (
    <>
      <p className="small" style={{ margin: 0 }}>
        You are training with <strong>{b.buddy?.display_name}</strong>.
      </p>

      {/*
        The days you are both free (§10.3), which is the one concrete thing pairing gives you
        today. Named days rather than a calendar: this is a weekly rhythm, not an appointment.
      */}
      {b.shared_days.length > 0 ? (
        <p className="tiny muted prose" style={{ margin: 0 }}>
          You are both free on {joinDays(b.shared_days.map((d) => d.name))}
          {sharedMinutes(b.shared_days)}.
        </p>
      ) : (
        /*
          §10.3: where availability does not overlap, the surplus days generate solo. So no
          overlap is a normal state to be explained, not an error.
        */
        <p className="tiny muted prose" style={{ margin: 0 }}>
          Your available days do not overlap at the moment, so you are both training on your
          own. Change a day in your week and this will pick it up.
        </p>
      )}

      <p className="tiny muted prose" style={{ margin: 0 }}>
        They can see whether you trained, and you can see the same about them. Your weight,
        measurements and photos stay private.
      </p>

      <div className="row">
        <button
          type="button"
          className="btn btn--quiet btn--small"
          disabled={busy}
          onClick={() => run(() => api.buddy.act('unpair'))}
        >
          Stop training together
        </button>
      </div>
    </>
  )
}

function Incoming({ b, busy, run }) {
  return (
    <>
      <p className="small" style={{ margin: 0 }}>
        <strong>{b.buddy?.display_name}</strong> wants to train with you.
      </p>
      {/* What accepting actually grants, before they accept it. */}
      <p className="tiny muted prose" style={{ margin: 0 }}>
        They would see whether you trained each day, and you would see the same about them.
        Your weight, measurements and photos stay private either way. You can stop at any
        time.
      </p>
      <div className="row" style={{ gap: 6 }}>
        <button
          type="button"
          className="btn btn--primary btn--small"
          disabled={busy}
          onClick={() => run(() => api.buddy.act('accept'))}
        >
          Train together
        </button>
        <button
          type="button"
          className="btn btn--quiet btn--small"
          disabled={busy}
          onClick={() => run(() => api.buddy.act('decline'))}
        >
          Not now
        </button>
      </div>
    </>
  )
}

function Outgoing({ b, busy, run }) {
  return (
    <>
      <p className="small" style={{ margin: 0 }}>
        Waiting on <strong>{b.buddy?.display_name}</strong>.
      </p>
      <p className="tiny muted" style={{ margin: 0 }}>Asked, no answer yet.</p>
      <div className="row">
        <button
          type="button"
          className="btn btn--quiet btn--small"
          disabled={busy}
          onClick={() => run(() => api.buddy.act('unpair'))}
        >
          Cancel
        </button>
      </div>
    </>
  )
}

/**
 * Nobody yet.
 *
 * `invitable` comes from the server: accepted friends who are not already paired with
 * somebody. Rendering from that rather than from the friends list means this cannot offer a
 * button whose only possible outcome is a refusal.
 */
function Invite({ b, busy, run }) {
  if (b.invitable.length === 0) {
    return (
      <p className="tiny muted prose" style={{ margin: 0 }}>
        Nobody to pair with yet. Add a friend below, and you can ask them to train with you.
      </p>
    )
  }

  return (
    <>
      <p className="tiny muted prose" style={{ margin: 0 }}>
        Pick someone to train with. You will each see whether the other showed up, which is
        most of the point.
      </p>
      {b.invitable.map((p) => (
        <div className="prefrow" key={p.id}>
          <span className="prefrow-body">
            <span className="small" style={{ fontWeight: 600 }}>{p.display_name}</span>
            <span className="tiny muted">{p.username}</span>
          </span>
          <button
            type="button"
            className="btn btn--ghost btn--small"
            disabled={busy}
            onClick={() => run(() => api.buddy.invite(p.id))}
          >
            Ask
          </button>
        </div>
      ))}
    </>
  )
}

/** "Tuesday, Thursday and Saturday" — an Oxford-comma-free list, as the copy voice prefers. */
function joinDays(names) {
  if (names.length <= 1) return names[0] || ''
  return `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`
}

/**
 * "(about 45 minutes each)" when every shared day agrees, nothing when they differ.
 *
 * A single figure is useful; listing a duration per day is noise, and averaging would be
 * inventing a number neither user gave.
 */
function sharedMinutes(days) {
  const mins = days.map((d) => d.minutes).filter((m) => typeof m === 'number')
  if (mins.length !== days.length || mins.length === 0) return ''
  return new Set(mins).size === 1 ? `, about ${mins[0]} minutes each` : ''
}
