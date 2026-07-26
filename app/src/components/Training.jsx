import { useEffect, useRef, useState } from 'react'
import { api } from '../api'
import Help from './Help'

/**
 * Training logs for one day.
 *
 * Per-EXERCISE, not per-set (SPEC-coaching §4.4): actual weight, actual reps, one
 * RPE. Per-set logging is more accurate and gets abandoned by week three, and
 * abandoned logging ends the app.
 *
 * The whole session posts in ONE request. The user taps "done" once, and a
 * half-written session after a dropped connection is worse than no session at
 * all — so nothing is sent until submit, and the draft lives here until then.
 *
 * FREE-LOGGING is not a secondary feature here, it is the baseline fortnight's
 * only path. Weeks one and two are pure observation with no prescription at all
 * (§9 "Week 1: log food, activity, daily check-ins. No prescription."), so a
 * screen that can only log against a plan captures nothing during exactly the
 * period the first plan is built from.
 */

const STATUS_OPTIONS = [
  { value: 'completed', label: 'Did it' },
  // 'partial' counts as showing up server-side. The label says that, because
  // someone who did a short session needs to know it is not a failure.
  { value: 'partial', label: 'Did some' },
  { value: 'substituted', label: 'Did something else' },
  { value: 'skipped', label: 'Skipped' },
]

/** What a free-logged session can be. Mirrors Training::TYPES (007). */
const TYPE_OPTIONS = [
  { value: 'strength', label: 'Lifting' },
  { value: 'cardio', label: 'Cardio' },
  { value: 'hybrid', label: 'Both' },
  { value: 'mobility', label: 'Mobility' },
  { value: 'active_recovery', label: 'Easy / recovery' },
]

export default function Training({ day, date, onDay }) {
  const sessions = day.sessions || []
  const [freeLogging, setFreeLogging] = useState(false)

  return (
    <>
      {sessions.length === 0 && !freeLogging && (
        <div className="card stack-sm">
          {/*
            An empty state is an invitation (DESIGN.md), and during the baseline
            this is the normal state and the only way data gets in. But it does not
            need to explain how plan generation works: the user needs to know they
            CAN log, not why it helps.
          */}
          <p className="small muted" style={{ margin: 0 }}>
            Nothing prescribed today. If you trained anyway, log it.
          </p>
          <button type="button" className="btn btn--primary"
            onClick={() => setFreeLogging(true)}>
            Log a workout
          </button>
        </div>
      )}

      {sessions.map((s) => (
        <Session
          key={s.prescribed_session_id ?? `logged-${s.logged?.logged_session_id}`}
          session={s}
          date={date}
          onDay={onDay}
        />
      ))}

      {freeLogging && (
        <div className="card stack-sm">
          <h3 className="subheading">What did you do?</h3>
          <FreeSessionForm
            date={date}
            onCancel={() => setFreeLogging(false)}
            onLogged={(payload) => {
              onDay(payload)
              setFreeLogging(false)
            }}
          />
        </div>
      )}

      {/* Always available, not just when the day is empty: an extra session on a
          prescribed day is ordinary, and §3.3a is explicit that extras are never
          held against anyone. */}
      {sessions.length > 0 && !freeLogging && (
        <button type="button" className="btn btn--quiet" onClick={() => setFreeLogging(true)}>
          Log something else you did
        </button>
      )}
    </>
  )
}

/** The section heading's live count, shown whether the body is open or shut. */
export function TrainingSummary({ day }) {
  const sessions = day.sessions || []
  // Committed sessions only, on both sides of the ratio (§3.3a) — an optional
  // session is a bonus and never a debt, so it must not inflate this either.
  const committed = sessions.filter((s) => s.is_committed)
  if (committed.length === 0) {
    const logged = sessions.filter((s) => s.logged).length
    return logged > 0
      ? <span className="tiny muted num">{logged} logged</span>
      : <span className="tiny muted">nothing yet</span>
  }
  const done = committed.filter((s) => s.logged && s.logged.status !== 'skipped').length
  return <span className="tiny muted num">{done} of {committed.length} done</span>
}

function Session({ session, date, onDay }) {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const logged = session.logged
  const isRest = session.session_type === 'rest'
  const isPrescribed = session.prescribed_session_id != null

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
        <h3 className="subheading">{sessionTitle(session)}</h3>
        {/* "Optional" is worth saying out loud: it is the difference between a
            bonus and a debt, and the user should know which this is. */}
        {isPrescribed && !session.is_committed && <span className="tag">optional</span>}
        {!isPrescribed && <span className="tag">your own</span>}
        {logged && <span className="tag push">{statusLabel(logged.status)}</span>}
      </div>

      <p className="tiny muted" style={{ margin: 0 }}>
        {[
          // Only when the heading is the focus — otherwise the heading already IS
          // the session type and this would say it twice.
          FOCUS_LABELS[session.focus] ? labelFor(session.session_type) : null,
          session.target_minutes ? `${session.target_minutes} min` : null,
          LOCATION_LABELS[session.location] || null,
        ].filter(Boolean).join(' · ')}
      </p>

      {session.focus_detail && (
        <p className="small" style={{ margin: 0 }}>{session.focus_detail}</p>
      )}

      {/* The coach's "why". Shown because a prescription without a reason reads as
          arbitrary (§3.3), and this is where the user decides to trust it. */}
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
        // bonus, and painting it as primary next to a committed one tells the
        // user the wrong thing about which matters (§3.3a).
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

/* ---- logging a prescribed session ---------------------------------------- */

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
      name: e.name,
      target: e,
      // A prescribed exercise carries no load_type, so infer the input set from
      // what it actually prescribes — a timed hold gets seconds, not kilos.
      load_type: e.target_seconds ? 'time' : e.target_distance_m ? 'distance' : 'weight',
      sets_completed: e.sets != null ? String(e.sets) : '',
      actual_reps: e.target_reps ?? '',
      actual_weight_kg: e.target_weight_kg != null ? String(e.target_weight_kg) : '',
      actual_seconds: e.target_seconds != null ? String(e.target_seconds) : '',
      actual_distance_m: e.target_distance_m != null ? String(e.target_distance_m) : '',
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
        exercises: rows.map(toPayload),
      })
      if (r?.day) onLogged(r.day)
    } catch (err) {
      setError(err.message || 'That did not save, so nothing was logged. Try again.')
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

      <SessionMeta
        idPrefix={`ps-${session.prescribed_session_id}`}
        minutes={minutes} setMinutes={setMinutes}
        rpe={rpe} setRpe={setRpe}
        buddy={buddy} setBuddy={setBuddy}
        notes={notes} setNotes={setNotes}
      />

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

/* ---- logging a workout nobody prescribed --------------------------------- */

/**
 * Free-logging: pick a type, add exercises by name, save.
 *
 * Exercises are OPTIONAL here. "I walked for 40 minutes" is a complete and useful
 * log, and demanding an exercise list would mean the walk goes unrecorded — which
 * during the baseline fortnight means the first plan never learns it happened.
 */
function FreeSessionForm({ date, onCancel, onLogged }) {
  const [type, setType] = useState('strength')
  const [minutes, setMinutes] = useState('')
  const [rpe, setRpe] = useState('')
  const [notes, setNotes] = useState('')
  const [buddy, setBuddy] = useState(false)
  const [rows, setRows] = useState([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const setRow = (i, patch) =>
    setRows((rs) => rs.map((r, j) => (j === i ? { ...r, ...patch } : r)))

  function addExercise(ex) {
    setRows((rs) => [
      ...rs,
      {
        exercise_id: ex.exercise_id,
        name: ex.name,
        // From the exercises table: a plank asks for seconds, a run for
        // distance, a press for kilos. This is why the endpoint returns it.
        load_type: ex.load_type,
        target: null,
        sets_completed: '',
        actual_reps: '',
        actual_weight_kg: '',
        actual_seconds: '',
        actual_distance_m: '',
        rpe: '',
        skipped: false,
      },
    ])
  }

  async function submit(e) {
    e.preventDefault()
    setError(null)
    setBusy(true)
    try {
      const r = await api.training.logSession({
        date,
        // Always 'completed': you cannot partially do a session nobody asked for.
        // Status is measured against a prescription, and there is none here.
        status: 'completed',
        session_type: type,
        actual_minutes: minutes === '' ? null : Number(minutes),
        session_rpe: rpe === '' ? null : Number(rpe),
        notes: notes.trim() || null,
        trained_with_buddy: buddy,
        exercises: rows.map(toPayload),
      })
      if (r?.day) onLogged(r.day)
    } catch (err) {
      setError(err.message || 'That did not save, so nothing was logged. Try again.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="stack-sm" onSubmit={submit}>
      <div className="chips" role="radiogroup" aria-label="What kind of session?">
        {TYPE_OPTIONS.map((o) => (
          <button
            key={o.value}
            type="button"
            role="radio"
            aria-checked={type === o.value}
            className="chip"
            onClick={() => setType(o.value)}
          >
            {o.label}
          </button>
        ))}
      </div>

      {rows.map((row, i) => (
        <ExerciseRow
          key={`${row.exercise_id}-${i}`}
          row={row}
          onChange={(patch) => setRow(i, patch)}
          onRemove={() => setRows((rs) => rs.filter((_, j) => j !== i))}
        />
      ))}

      <ExercisePicker onPick={addExercise} />

      {rows.length === 0 && (
        <p className="tiny muted" style={{ margin: 0 }}>
          Exercises are optional. "Walked 40 minutes" is a fine log on its own.
        </p>
      )}

      <SessionMeta
        idPrefix="free"
        minutes={minutes} setMinutes={setMinutes}
        rpe={rpe} setRpe={setRpe}
        buddy={buddy} setBuddy={setBuddy}
        notes={notes} setNotes={setNotes}
      />

      {error && <p className="error">{error}</p>}

      <div className="row">
        <button type="submit" className="btn btn--primary" disabled={busy}>
          {busy ? 'Saving…' : 'Save it'}
        </button>
        <button type="button" className="btn btn--quiet" disabled={busy} onClick={onCancel}>
          Cancel
        </button>
      </div>
    </form>
  )
}

/**
 * Typeahead over the exercise library.
 *
 * Server-side search, not a client-side filter over a downloaded library: it
 * covers the 53 aliases too, so "bench" finds "Barbell Bench Press" and the log
 * row still references the canonical id. Free text would fork "leg press" and
 * "Leg Press" into two exercises and break load history.
 */
function ExercisePicker({ onPick }) {
  const [q, setQ] = useState('')
  const [results, setResults] = useState([])
  const [open, setOpen] = useState(false)
  // Guards against a slow response for "be" landing after the one for "bench" —
  // out-of-order replies would show results for what you already stopped typing.
  const seq = useRef(0)

  useEffect(() => {
    const term = q.trim()
    if (term.length < 2) {
      setResults([])
      return
    }
    const mine = ++seq.current
    // Debounced: this fires per keystroke otherwise, and the endpoint is cheap
    // but not free.
    const t = setTimeout(() => {
      api.training.exercises(term)
        .then((r) => { if (mine === seq.current) setResults(r.exercises || []) })
        .catch(() => { if (mine === seq.current) setResults([]) })
    }, 200)
    return () => clearTimeout(t)
  }, [q])

  return (
    <div className="stack-sm">
      <input
        className="input"
        placeholder="Add an exercise, try &ldquo;bench&rdquo; or &ldquo;squat&rdquo;"
        aria-label="Search exercises"
        value={q}
        onChange={(e) => { setQ(e.target.value); setOpen(true) }}
        onFocus={() => setOpen(true)}
      />
      {open && results.map((ex) => (
        <button
          key={ex.exercise_id}
          type="button"
          className="result"
          onClick={() => {
            onPick(ex)
            setQ('')
            setResults([])
          }}
        >
          <span className="small">{ex.name}</span>
          <span className="tiny muted">{ex.category} · {loadLabel(ex.load_type)}</span>
        </button>
      ))}
      {open && q.trim().length >= 2 && results.length === 0 && (
        <p className="tiny muted" style={{ margin: 0 }}>
          Nothing matches that. Put it in the notes instead and log the session.
        </p>
      )}
    </div>
  )
}

/* ---- shared bits --------------------------------------------------------- */

function SessionMeta({
  idPrefix, minutes, setMinutes, rpe, setRpe, buddy, setBuddy, notes, setNotes,
}) {
  return (
    <>
      <div className="row" style={{ flexWrap: 'wrap', gap: 10 }}>
        <label className="field" style={{ maxWidth: '8em' }}>
          <span className="tiny muted">Minutes</span>
          <input className="input num" type="number" inputMode="numeric" min="1" max="600"
            value={minutes} onChange={(e) => setMinutes(e.target.value)} />
        </label>
        <div className="field" style={{ maxWidth: '10em' }}>
          {/* Not a <label>: it contains a button, and clicking the help would
              focus the input instead of opening the explanation. Wired up with
              aria-labelledby instead. The id is scoped because both session
              forms render this. */}
          <span className="tiny muted" id={`${idPrefix}-rpe`}>
            Session RPE
            {/* Standard in training, meaningless outside it. */}
            <Help label="Session RPE">
              How hard the whole session felt, 1 to 10. 1 is barely any effort,
              10 is all you had. Around 7 or 8 is a solid working session.
            </Help>
          </span>
          <input className="input num" type="number" inputMode="numeric" min="1" max="10"
            aria-labelledby={`${idPrefix}-rpe`}
            value={rpe} onChange={(e) => setRpe(e.target.value)} />
        </div>
      </div>

      <label className="row" style={{ gap: 8 }}>
        <input type="checkbox" checked={buddy} onChange={(e) => setBuddy(e.target.checked)} />
        <span className="small">Trained with my buddy</span>
      </label>

      <input className="input" placeholder="Anything worth noting?" aria-label="Session notes"
        value={notes} onChange={(e) => setNotes(e.target.value)} />
    </>
  )
}

/**
 * One exercise: the inputs its load type actually needs, and a skip.
 *
 * The prescription sits above the inputs rather than inside them as a placeholder
 * — a placeholder disappears the moment you type, and "what was I asked for" is
 * exactly what you want to see while typing.
 */
function ExerciseRow({ row, onChange, onRemove }) {
  const t = row.target
  const timed = row.load_type === 'time'
  const dist = row.load_type === 'distance'
  // Bodyweight and assisted work still take reps and sets; they just do not take
  // a load. Showing a kg box on a pull-up invites a wrong number.
  const loaded = row.load_type === 'weight' || row.load_type === 'assisted'

  return (
    <div className="exrow" data-skipped={row.skipped ? 'true' : 'false'}>
      <div className="row">
        <span className="small" style={{ fontWeight: 600 }}>{row.name}</span>
        {onRemove ? (
          <button type="button" className="btn btn--quiet push" aria-label={`Remove ${row.name}`}
            onClick={onRemove}>×</button>
        ) : (
          <button
            type="button"
            className="btn btn--quiet push tiny"
            aria-pressed={row.skipped}
            onClick={() => onChange({ skipped: !row.skipped })}
          >
            {row.skipped ? 'Skipped, undo' : 'Skip'}
          </button>
        )}
      </div>

      {t && (
        <p className="tiny muted num" style={{ margin: 0 }}>{describeTarget(t)}</p>
      )}

      {!row.skipped && (
        <div className="exrow-inputs">
          <label className="field">
            <span className="tiny muted">Sets</span>
            <input className="input num" type="number" inputMode="numeric" min="0" max="50"
              value={row.sets_completed}
              onChange={(e) => onChange({ sets_completed: e.target.value })} />
          </label>

          {!timed && !dist && (
            <label className="field">
              <span className="tiny muted">Reps</span>
              {/* Text, not number: "8-10" and "to failure" are real answers, and
                  the column is a string server-side for that reason. */}
              <input className="input num" type="text" inputMode="numeric"
                value={row.actual_reps}
                onChange={(e) => onChange({ actual_reps: e.target.value })} />
            </label>
          )}

          {loaded && (
            <label className="field">
              <span className="tiny muted">kg{t?.is_per_side ? '/side' : ''}</span>
              <input className="input num" type="number" inputMode="decimal" min="0" step="0.5"
                value={row.actual_weight_kg}
                onChange={(e) => onChange({ actual_weight_kg: e.target.value })} />
            </label>
          )}

          {timed && (
            <label className="field">
              <span className="tiny muted">Seconds</span>
              <input className="input num" type="number" inputMode="numeric" min="0"
                value={row.actual_seconds}
                onChange={(e) => onChange({ actual_seconds: e.target.value })} />
            </label>
          )}

          {dist && (
            <label className="field">
              <span className="tiny muted">Metres</span>
              <input className="input num" type="number" inputMode="numeric" min="0"
                value={row.actual_distance_m}
                onChange={(e) => onChange({ actual_distance_m: e.target.value })} />
            </label>
          )}

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

/** One row → the API's exercise shape. Empty stays null, never 0. */
function toPayload(row) {
  return {
    // The id, not the slug: the slug path exists for clients that only have the
    // user's wording, and this one has the real id.
    exercise_id: row.exercise_id,
    prescribed_exercise_id: row.prescribed_exercise_id ?? null,
    sets_completed: row.sets_completed === '' ? null : Number(row.sets_completed),
    actual_reps: String(row.actual_reps || '').trim() || null,
    actual_weight_kg: row.actual_weight_kg === '' ? null : Number(row.actual_weight_kg),
    actual_seconds: row.actual_seconds === '' ? null : Number(row.actual_seconds),
    actual_distance_m: row.actual_distance_m === '' ? null : Number(row.actual_distance_m),
    rpe: row.rpe === '' ? null : Number(row.rpe),
    skipped: row.skipped,
  }
}

/* ---- labels -------------------------------------------------------------- */

/** ENUM. "full_gym" is a column value, not something to show a user. */
const LOCATION_LABELS = {
  full_gym: 'Full gym',
  home_gym: 'Home gym',
  bodyweight: 'Bodyweight',
  outdoors: 'Outdoors',
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
  }
  // A free-logged row from before 007 has no type at all. "Session" is honest;
  // guessing 'strength' would be a fabrication the coach then reads as fact.
  return map[type] || 'Session'
}

function loadLabel(loadType) {
  const map = {
    weight: 'weighted',
    bodyweight: 'bodyweight',
    assisted: 'assisted',
    time: 'timed',
    distance: 'distance',
  }
  return map[loadType] || loadType
}

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
  if (e.actual_distance_m) bits.push(`${e.actual_distance_m}m`)
  if (e.rpe) bits.push(`RPE ${e.rpe}`)
  return bits.join(' · ') || 'logged'
}

function statusLabel(status) {
  const map = {
    completed: 'done', partial: 'did some',
    substituted: 'swapped', skipped: 'skipped',
  }
  return map[status] || status
}
