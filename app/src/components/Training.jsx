import { useState } from 'react'
import { api } from '../api'

/**
 * Training logs for one day.
 *
 * Per-EXERCISE, not per-set (SPEC-coaching §4.4): actual weight, actual reps,
 * one RPE. Per-set logging is more accurate and gets abandoned by week three,
 * and abandoned logging ends the app.
 *
 * The whole session posts in ONE request. The user taps "done" once, and a
 * half-written session after a dropped connection is worse than no session at
 * all — so nothing is sent until the form is submitted, and the draft lives here
 * in local state until then.
 *
 * Prescribed numbers are pre-filled. Most sets are done as prescribed, and
 * making the common case zero typing is the difference between a log that
 * happens and one that does not.
 */

const STATUS_OPTIONS = [
  { value: 'completed', label: 'Did it' },
  // 'partial' counts as showing up server-side. The label says that, because
  // people who did a short session need to know it is not a failure.
  { value: 'partial', label: 'Did some' },
  { value: 'substituted', label: 'Did something else' },
  { value: 'skipped', label: 'Skipped' },
]

export default function Training({ day, date, onDay }) {
  const sessions = day.sessions || []

  if (sessions.length === 0) {
    return (
      <section className="stack" aria-labelledby="train-h">
        <h2 className="heading" id="train-h">Training</h2>
        <div className="card">
          <p className="small muted" style={{ margin: 0 }}>
            Nothing prescribed today. Rest is part of the plan.
          </p>
        </div>
      </section>
    )
  }

  // Committed sessions only, on both sides of the ratio (§3.3a) — an optional
  // session is a bonus and never a debt, so it must not inflate this either.
  const committed = sessions.filter((s) => s.is_committed)
  const done = committed.filter((s) => s.logged && s.logged.status !== 'skipped').length

  return (
    <section className="stack" aria-labelledby="train-h">
      <div className="row">
        <h2 className="heading" id="train-h">Training</h2>
        {committed.length > 0 && (
          <span className="tiny muted push num">{done} of {committed.length} done</span>
        )}
      </div>

      {sessions.map((s) => (
        <Session
          key={s.prescribed_session_id ?? `logged-${s.logged?.logged_session_id}`}
          session={s}
          date={date}
          onDay={onDay}
        />
      ))}
    </section>
  )
}

function Session({ session, date, onDay }) {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const logged = session.logged
  const isRest = session.session_type === 'rest'

  async function remove() {
    setError(null)
    setBusy(true)
    try {
      const r = await api.training.deleteSession(logged.logged_session_id)
      if (r?.day) onDay(r.day)
    } catch (e) {
      setError(e.message || 'Could not remove that.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="card stack-sm">
      <div className="row" style={{ flexWrap: 'wrap' }}>
        <h3 className="subheading">
          {sessionTitle(session)}
        </h3>
        {/* "Optional" is worth saying out loud: it is the difference between a
            bonus and a debt, and the user should know which this is. */}
        {!session.is_committed && <span className="tag">optional</span>}
        {logged && <span className="tag push">{statusLabel(logged.status)}</span>}
      </div>

      <p className="tiny muted" style={{ margin: 0 }}>
        {[
          // Only when the heading is the focus — otherwise the heading already
          // IS the session type and this would say it twice.
          FOCUS_LABELS[session.focus] ? labelFor(session.session_type) : null,
          session.target_minutes ? `${session.target_minutes} min` : null,
          LOCATION_LABELS[session.location] || null,
        ].filter(Boolean).join(' · ')}
      </p>

      {session.focus_detail && (
        <p className="small" style={{ margin: 0 }}>{session.focus_detail}</p>
      )}

      {/* The coach's "why". Shown because a prescription without a reason reads
          as arbitrary (§3.3), and this is where the user decides to trust it. */}
      {session.rationale && (
        <p className="tiny muted prose" style={{ margin: 0 }}>{session.rationale}</p>
      )}

      {session.warmup_detail && (
        <p className="tiny muted" style={{ margin: 0 }}>
          Warm-up{session.warmup_required ? ' (required)' : ''}
          {session.warmup_minutes ? `, ${session.warmup_minutes} min` : ''}: {session.warmup_detail}
        </p>
      )}

      {logged ? (
        <LoggedSession logged={logged} busy={busy} onRemove={remove} />
      ) : isRest ? (
        <p className="small muted" style={{ margin: 0 }}>Rest day. Nothing to log.</p>
      ) : open ? (
        <SessionForm
          session={session}
          date={date}
          onCancel={() => setOpen(false)}
          onLogged={(dayPayload) => {
            onDay(dayPayload)
            setOpen(false)
          }}
        />
      ) : (
        // Only a COMMITTED session earns the accent. An optional session is a
        // bonus, and painting it as the primary action next to a committed one
        // tells the user the wrong thing about which matters (§3.3a).
        <button
          type="button"
          className={session.is_committed ? 'btn btn--primary' : 'btn btn--ghost'}
          onClick={() => setOpen(true)}
        >
          Log this session
        </button>
      )}

      {error && <p className="error">{error}</p>}
    </div>
  )
}

function LoggedSession({ logged, busy, onRemove }) {
  return (
    <div className="stack-sm">
      {logged.exercises.map((e) => (
        <div className="row entry" key={e.id}>
          <span className="small" style={{ textDecoration: e.skipped ? 'line-through' : 'none' }}>
            {e.name}
          </span>
          <span className="tiny muted num push">
            {e.skipped ? 'skipped' : describeActual(e)}
          </span>
        </div>
      ))}
      <div className="row">
        <span className="tiny muted num">
          {[
            logged.actual_minutes ? `${logged.actual_minutes} min` : null,
            logged.session_rpe ? `RPE ${logged.session_rpe}` : null,
            logged.trained_with_buddy ? 'with your buddy' : null,
          ].filter(Boolean).join(' · ')}
        </span>
        {/* Re-logging replaces server-side, so this is for "I logged the wrong
            day", not for editing. Editing is: log it again. */}
        <button type="button" className="btn btn--quiet push" disabled={busy} onClick={onRemove}>
          Remove
        </button>
      </div>
      {logged.notes && <p className="tiny muted" style={{ margin: 0 }}>{logged.notes}</p>}
    </div>
  )
}

/* ---- logging a session --------------------------------------------------- */

function SessionForm({ session, date, onCancel, onLogged }) {
  const [status, setStatus] = useState('completed')
  const [minutes, setMinutes] = useState(session.target_minutes ? String(session.target_minutes) : '')
  const [rpe, setRpe] = useState('')
  const [notes, setNotes] = useState('')
  const [buddy, setBuddy] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  // Pre-filled from the prescription: the common case is "I did what it said",
  // and that case should cost zero typing.
  const [rows, setRows] = useState(() =>
    session.exercises.map((e) => ({
      prescribed_exercise_id: e.prescribed_exercise_id,
      exercise_id: e.exercise_id,
      slug: e.slug,
      name: e.name,
      target: e,
      sets_completed: e.sets != null ? String(e.sets) : '',
      actual_reps: e.target_reps ?? '',
      actual_weight_kg: e.target_weight_kg != null ? String(e.target_weight_kg) : '',
      rpe: '',
      skipped: false,
    }))
  )

  const setRow = (i, patch) =>
    setRows((rs) => rs.map((r, j) => (j === i ? { ...r, ...patch } : r)))

  async function submit(e) {
    e.preventDefault()
    setError(null)
    setBusy(true)
    try {
      const r = await api.training.logSession({
        date,
        status,
        prescribed_session_id: session.prescribed_session_id,
        actual_minutes: minutes === '' ? null : Number(minutes),
        session_rpe: rpe === '' ? null : Number(rpe),
        notes: notes.trim() || null,
        trained_with_buddy: buddy,
        // A skipped session still posts its exercise rows, flagged — "what did
        // you not do" is signal, and the coach reads it.
        exercises: rows.map((row) => ({
          // Send the id, not the slug: the slug path exists for clients that
          // only have the user's wording, and this one has the real id.
          exercise_id: row.exercise_id,
          prescribed_exercise_id: row.prescribed_exercise_id,
          sets_completed: row.sets_completed === '' ? null : Number(row.sets_completed),
          actual_reps: String(row.actual_reps || '').trim() || null,
          actual_weight_kg: row.actual_weight_kg === '' ? null : Number(row.actual_weight_kg),
          rpe: row.rpe === '' ? null : Number(row.rpe),
          skipped: row.skipped,
        })),
      })
      if (r?.day) onLogged(r.day)
    } catch (err) {
      setError(err.message || 'That did not save. Nothing was logged — try again.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="stack-sm" onSubmit={submit}>
      <div className="chips" role="radiogroup" aria-label="How did it go?">
        {STATUS_OPTIONS.map((o) => (
          <button
            key={o.value}
            type="button"
            role="radio"
            aria-checked={status === o.value}
            className="chip"
            onClick={() => setStatus(o.value)}
          >
            {o.label}
          </button>
        ))}
      </div>

      {rows.map((row, i) => (
        <ExerciseRow key={row.prescribed_exercise_id ?? row.exercise_id} row={row}
          onChange={(patch) => setRow(i, patch)} />
      ))}

      <div className="row" style={{ flexWrap: 'wrap', gap: 10 }}>
        <label className="field" style={{ maxWidth: '8em' }}>
          <span className="tiny muted">Minutes</span>
          <input className="input num" type="number" inputMode="numeric" min="1" max="600"
            value={minutes} onChange={(e) => setMinutes(e.target.value)} />
        </label>
        <label className="field" style={{ maxWidth: '8em' }}>
          <span className="tiny muted">Session RPE</span>
          <input className="input num" type="number" inputMode="numeric" min="1" max="10"
            value={rpe} onChange={(e) => setRpe(e.target.value)} />
        </label>
      </div>

      <label className="row" style={{ gap: 8 }}>
        <input type="checkbox" checked={buddy} onChange={(e) => setBuddy(e.target.checked)} />
        <span className="small">Trained with my buddy</span>
      </label>

      <input className="input" placeholder="Anything worth noting?" aria-label="Session notes"
        value={notes} onChange={(e) => setNotes(e.target.value)} />

      {error && <p className="error">{error}</p>}

      <div className="row">
        <button type="submit" className="btn btn--primary" disabled={busy}>
          {busy ? 'Saving…' : 'Done'}
        </button>
        <button type="button" className="btn btn--quiet" disabled={busy} onClick={onCancel}>
          Cancel
        </button>
      </div>
    </form>
  )
}

/**
 * One exercise: three inputs and a skip.
 *
 * The prescription sits above the inputs rather than inside them as a
 * placeholder — a placeholder disappears the moment you type, and "what was I
 * asked for" is exactly what you want to see while typing.
 */
function ExerciseRow({ row, onChange }) {
  const t = row.target
  return (
    <div className="exrow" data-skipped={row.skipped ? 'true' : 'false'}>
      <div className="row">
        <span className="small" style={{ fontWeight: 600 }}>{row.name}</span>
        <button
          type="button"
          className="btn btn--quiet push tiny"
          aria-pressed={row.skipped}
          onClick={() => onChange({ skipped: !row.skipped })}
        >
          {row.skipped ? 'Skipped — undo' : 'Skip'}
        </button>
      </div>

      <p className="tiny muted num" style={{ margin: 0 }}>
        {describeTarget(t)}
      </p>

      {!row.skipped && (
        <div className="exrow-inputs">
          <label className="field">
            <span className="tiny muted">Sets</span>
            <input className="input num" type="number" inputMode="numeric" min="0" max="50"
              value={row.sets_completed}
              onChange={(e) => onChange({ sets_completed: e.target.value })} />
          </label>
          <label className="field">
            <span className="tiny muted">Reps</span>
            {/* Text, not number: "8-10" and "to failure" are real answers, and
                the column is a string server-side for that reason. */}
            <input className="input num" type="text" inputMode="numeric"
              value={row.actual_reps}
              onChange={(e) => onChange({ actual_reps: e.target.value })} />
          </label>
          <label className="field">
            <span className="tiny muted">kg{t?.is_per_side ? '/side' : ''}</span>
            <input className="input num" type="number" inputMode="decimal" min="0" step="0.5"
              value={row.actual_weight_kg}
              onChange={(e) => onChange({ actual_weight_kg: e.target.value })} />
          </label>
          <label className="field">
            <span className="tiny muted">RPE</span>
            <input className="input num" type="number" inputMode="numeric" min="1" max="10"
              value={row.rpe}
              onChange={(e) => onChange({ rpe: e.target.value })} />
          </label>
        </div>
      )}
    </div>
  )
}

/* ---- labels -------------------------------------------------------------- */

function describeTarget(t) {
  if (!t) return ''
  const bits = []
  if (t.sets && t.target_reps) bits.push(`${t.sets} × ${t.target_reps}`)
  else if (t.sets) bits.push(`${t.sets} sets`)
  if (t.target_weight_kg) bits.push(`${t.target_weight_kg} kg${t.is_per_side ? '/side' : ''}`)
  if (t.target_seconds) bits.push(`${t.target_seconds}s`)
  if (t.target_distance_m) bits.push(`${t.target_distance_m}m`)
  if (t.target_rpe) bits.push(`RPE ${t.target_rpe}`)
  if (t.rest_seconds) bits.push(`${t.rest_seconds}s rest`)
  return bits.length ? `Asked for: ${bits.join(' · ')}` : 'No target set'
}

function describeActual(e) {
  const bits = []
  if (e.sets_completed && e.actual_reps) bits.push(`${e.sets_completed} × ${e.actual_reps}`)
  else if (e.sets_completed) bits.push(`${e.sets_completed} sets`)
  if (e.actual_weight_kg) bits.push(`${e.actual_weight_kg} kg`)
  if (e.actual_seconds) bits.push(`${e.actual_seconds}s`)
  if (e.rpe) bits.push(`RPE ${e.rpe}`)
  return bits.join(' · ') || 'logged'
}

/**
 * `focus` is an ENUM — 'full', 'push', 'conditioning' — not prose.
 *
 * Rendering it raw would put "conditioning" in a heading, and DESIGN.md is
 * explicit that things are named by what the person controls. 'none' and '' both
 * mean "no focus recorded", so both fall through to the session type: MySQL
 * coerces an out-of-range enum to '' silently, so the empty string is a state
 * that reaches the client in practice, not just in theory.
 */
/** Also an ENUM. "full_gym" is a column value, not something to show a user. */
const LOCATION_LABELS = {
  full_gym: 'Full gym',
  home_gym: 'Home gym',
  bodyweight: 'Bodyweight',
  outdoors: 'Outdoors',
}

const FOCUS_LABELS = {
  upper: 'Upper body',
  lower: 'Lower body',
  full: 'Full body',
  push: 'Push',
  pull: 'Pull',
  core: 'Core',
  conditioning: 'Conditioning',
}

function sessionTitle(session) {
  return FOCUS_LABELS[session.focus] || labelFor(session.session_type)
}

function labelFor(type) {
  const map = {
    strength: 'Strength',
    cardio: 'Cardio',
    hybrid: 'Strength and cardio',
    mobility: 'Mobility',
    active_recovery: 'Active recovery',
    rest: 'Rest',
    other: 'Session',
  }
  return map[type] || 'Session'
}

function statusLabel(status) {
  const map = {
    completed: 'done', partial: 'did some',
    substituted: 'swapped', skipped: 'skipped',
  }
  return map[status] || status
}
