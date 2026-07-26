import { useEffect, useState } from 'react'
import { api } from '../api'
import BuddySchedule from './BuddySchedule'

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
  const [away, setAway] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  const load = () => api.buddy.load()
    .then((r) => {
      setB(r.buddy)
      // §10.5. Both directions: mine drives the control, theirs explains a solo week.
      setAway(r.away ?? null)
    })
    .catch((e) => setError(e.message || 'Could not load this.'))

  useEffect(() => { load() }, [])

  async function run(fn) {
    setError(null)
    setBusy(true)
    try {
      const r = await fn()
      if (r?.buddy) {
        setB(r.buddy)
      }
      // Absence endpoints return no buddy payload, and declaring one changes what the card
      // should say, so re-read rather than patching from the response.
      await load()
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
    <>
      <div className="card stack-sm">
        <h3 className="subheading">Training buddy</h3>

        {b.status === 'active' && <Active b={b} away={away} busy={busy} run={run} />}
        {b.status === 'pending_in' && <Incoming b={b} busy={busy} run={run} />}
        {b.status === 'pending_out' && <Outgoing b={b} busy={busy} run={run} />}
        {b.status === 'none' && <Invite b={b} busy={busy} run={run} />}

        {error && <p className="error tiny">{error}</p>}
      </div>

      {/*
        Its own card, below the pairing one. The schedule is a bigger thing than the pairing
        state — a day list, sometimes a negotiation, sometimes a question — and nesting it
        would make one card that does two jobs.

        Keyed on the pairing status so it re-reads when a pair goes active: the schedule is
        seeded at that moment and would otherwise show nothing until a reload.
      */}
      <BuddySchedule
        key={b.status}
        paired={b.status === 'active'}
        onChanged={load}
      />
    </>
  )
}

function Active({ b, away, busy, run }) {
  return (
    <>
      <p className="small" style={{ margin: 0 }}>
        You are training with <strong>{b.buddy?.display_name}</strong>.
      </p>

      {/*
        A one-line summary only. The day list, the compromise prompt and the surplus question
        all live in the schedule card below — saying the days twice on one screen makes the
        second telling read as a different fact.
      */}
      {b.shared_days.length > 0 && (
        <p className="tiny muted prose" style={{ margin: 0 }}>
          You train together on {joinDays(b.shared_days.map((d) => d.name))}
          {sharedMinutes(b.shared_days)}.
        </p>
      )}

      <p className="tiny muted prose" style={{ margin: 0 }}>
        They can see whether you trained, and you can see the same about them. Your weight,
        measurements and photos stay private.
      </p>

      {/* §10.5, above the unpair control: being away is the common case and stopping is not. */}
      <Away away={away} busy={busy} run={run} />

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

/**
 * Telling your buddy you will be away, and hearing that they are (§10.5).
 *
 * "A buddy who travels, gets ill, or unpairs must never leave the other waiting."
 *
 * Two halves, and the asymmetry is the point. THEIRS is information: your next week will be
 * solo, and here is when they are back. MINE is an action: say you are away so your partner
 * finds out from the app rather than from an empty rack.
 *
 * The copy never treats a solo week as a setback. §10.5: "In every case the partner keeps a
 * complete, valid week." Someone whose buddy is ill has done nothing wrong and should not be
 * made to feel they are behind.
 */
function Away({ away, busy, run }) {
  const [open, setOpen] = useState(false)
  const [kind, setKind] = useState('travel')
  const [starts, setStarts] = useState(today())
  const [returns, setReturns] = useState('')

  const theirs = away?.theirs
  const mine = away?.mine

  return (
    <>
      {/*
        Their absence, when there is one. Read from the server's answer for the COMING week,
        which is the week it affects — a Sunday generation is about next Monday.
      */}
      {theirs && theirs.available === false && theirs.reason !== 'unpaired' && (
        <p className="tiny muted prose" style={{ margin: 0 }}>
          {theirs.reason === 'silent'
            /*
             * The undeclared case. Deliberately gentle and non-accusatory: this is inferred
             * from silence, not declared, so it may simply be wrong — and telling someone
             * their friend has quit on the strength of a few quiet days would be worse than
             * saying nothing.
             */
            ? `${theirs.buddy_name} has been quiet for a while, so your next week will be `
              + 'planned for you alone. It will pick back up when they log again.'
            : `${theirs.buddy_name} is away, so your next week will be yours alone.`
            + (theirs.returns_on ? ` Back on ${prettyDay(theirs.returns_on)}.` : '')}
        </p>
      )}

      {/* Mine: either the standing declaration, or the way to make one. */}
      {mine ? (
        <div className="row" style={{ flexWrap: 'wrap' }}>
          <span className="tiny muted" style={{ flex: '1 1 auto' }}>
            You are away from {prettyDay(mine.starts_on)}
            {mine.returns_on ? ` until ${prettyDay(mine.returns_on)}` : ', open-ended'}.
          </span>
          <button
            type="button"
            className="btn btn--quiet btn--small"
            disabled={busy}
            onClick={() => run(() => api.buddy.back())}
          >
            I am back
          </button>
        </div>
      ) : open ? (
        <div className="veto stack-sm">
          <p className="tiny" style={{ margin: 0, fontWeight: 600 }}>
            Let your buddy know
          </p>
          {/*
            Travel and illness are separated because they behave differently: travel is usually
            declared before the week is planned, illness during it. The app uses that to decide
            what the partner is told.
          */}
          <div className="chips" role="radiogroup" aria-label="Why">
            {[
              { value: 'travel', label: 'Away or travelling' },
              { value: 'illness', label: 'Ill or injured' },
              { value: 'other', label: 'Something else' },
            ].map((o) => (
              <button
                key={o.value}
                type="button"
                role="radio"
                aria-checked={kind === o.value}
                className="chip"
                disabled={busy}
                onClick={() => setKind(o.value)}
              >
                {o.label}
              </button>
            ))}
          </div>

          <label className="field">
            <span className="label">From</span>
            <input
              className="input"
              type="date"
              value={starts}
              aria-label="Away from"
              onChange={(e) => setStarts(e.target.value)}
            />
          </label>
          <label className="field">
            <span className="label">Back on</span>
            <input
              className="input"
              type="date"
              value={returns}
              aria-label="Back on"
              onChange={(e) => setReturns(e.target.value)}
            />
          </label>
          {/*
            Blank is allowed and says so. Making somebody invent a recovery date for an illness
            they cannot predict produces a wrong date, which is worse than no date.
          */}
          <p className="tiny muted prose" style={{ margin: 0 }}>
            Leave the return date blank if you do not know yet. Your own plan carries on either
            way, and so does theirs.
          </p>

          <div className="row" style={{ gap: 6 }}>
            <button
              type="button"
              className="btn btn--primary btn--small"
              disabled={busy || starts === ''}
              onClick={() => run(async () => {
                await api.buddy.away({
                  kind,
                  starts_on: starts,
                  returns_on: returns === '' ? undefined : returns,
                })
                setOpen(false)
              })}
            >
              Tell them
            </button>
            <button
              type="button"
              className="btn btn--quiet btn--small"
              disabled={busy}
              onClick={() => setOpen(false)}
            >
              Cancel
            </button>
          </div>
        </div>
      ) : (
        <button
          type="button"
          className="btn btn--quiet btn--small"
          disabled={busy}
          onClick={() => {
            /*
             * Reset on OPEN, not on close.
             *
             * The fields are component state and survive a close, so reopening the form showed
             * whatever was typed last time — including a return date from a previous, now
             * cancelled absence. Somebody declaring an open-ended illness would silently send a
             * stale recovery date they never chose, which is the exact thing the blank field is
             * there to avoid.
             *
             * Resetting here rather than on close covers cancel, submit and unmount in one
             * place: whatever happened last, the form opens clean.
             */
            setKind('travel')
            setStarts(today())
            setReturns('')
            setOpen(true)
          }}
        >
          I will be away
        </button>
      )}
    </>
  )
}

/** Today, as YYYY-MM-DD in local time. */
function today() {
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

/** "2026-08-04" -> "4 August". A date, not a timestamp. */
function prettyDay(iso) {
  const [y, m, d] = String(iso).split('-').map(Number)
  const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
    'August', 'September', 'October', 'November', 'December']
  return `${d} ${months[m - 1] || ''}`.trim() || String(iso)
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
