import { useEffect, useState } from 'react'

/**
 * The daily check-in (SPEC-coaching §4.1) — four taps and a number.
 *
 * Every rating saves on tap. There is no "save" button because a check-in is
 * not a form: the whole promise is that it is cheap, and a four-tap interaction
 * that then needs a fifth tap to commit is a five-tap interaction. Partial
 * check-ins are valid server-side, so each rating stands on its own.
 *
 * Ratings are read as a DELTA against the profile baselines, not as absolute
 * values — a 3 from someone who always sleeps badly means something different.
 * That is the server's job; this component only collects them.
 */

const SCALES = [
  // Labels for 1 and 5 only. Labelling all five turns a glanceable row into a
  // paragraph, and the endpoints are what the number means.
  { key: 'energy', label: 'Energy', low: 'Drained', high: 'Full of it' },
  { key: 'sleep_quality', label: 'Sleep quality', low: 'Terrible', high: 'Excellent' },
  // Soreness inverts: 5 is BAD here, unlike every other row. The endpoint
  // labels carry that, which is why they are not optional.
  { key: 'soreness', label: 'Soreness', low: 'None', high: 'Wrecked' },
  { key: 'mood', label: 'Mood', low: 'Low', high: 'Great' },
]

export default function CheckIn({ checkin, onSave }) {
  // Server state is the truth, but a tap has to feel instant — so ratings render
  // from a local mirror that re-syncs whenever the server's day changes.
  const [local, setLocal] = useState(() => fromServer(checkin))
  const [hours, setHours] = useState(() => hoursOf(checkin))
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState(null)

  // Stepping to another date replaces `checkin` wholesale; without this the
  // previous day's ratings would linger on screen.
  useEffect(() => {
    setLocal(fromServer(checkin))
    setHours(hoursOf(checkin))
  }, [checkin])

  async function save(field, value) {
    setError(null)
    setBusy(field)
    // Optimistic, and deliberately not rolled back on failure: the error tells
    // the user it did not save, and silently reverting their tap is the more
    // confusing outcome. They can tap again.
    setLocal((s) => ({ ...s, [field]: value }))
    try {
      await onSave({ [field]: value })
    } catch (e) {
      setError(e.message || 'That did not save. Try again.')
    } finally {
      setBusy(null)
    }
  }

  const answered = SCALES.filter((s) => local[s.key] != null).length
    + (local.sleep_hours != null ? 1 : 0)

  return (
    <section className="card stack" aria-labelledby="checkin-h">
      <div className="row">
        <h2 className="subheading" id="checkin-h">How are you today?</h2>
        {/* A receipt, not a nag: it says what is saved, and never asks for the
            rest. Four taps is the ceiling, not a quota. */}
        <span className="tiny muted push num">
          {checkin ? `${answered} of 5 answered` : 'Not yet'}
        </span>
      </div>

      {SCALES.map((s) => (
        <Scale
          key={s.key}
          scale={s}
          value={local[s.key]}
          busy={busy === s.key}
          onPick={(n) => save(s.key, n)}
        />
      ))}

      <div className="field">
        <label className="label" htmlFor="sleep-hours">Hours slept</label>
        <div className="row">
          <input
            id="sleep-hours"
            className="input num"
            type="number"
            inputMode="decimal"
            min="0"
            max="24"
            step="0.5"
            style={{ maxWidth: '7em' }}
            value={hours}
            onChange={(e) => setHours(e.target.value)}
            /* Saves on blur rather than per keystroke: "7.5" passes through
               "7." mid-typing, and a PUT per character is both wasteful and
               briefly wrong. */
            onBlur={() => {
              const v = hours.trim()
              if (v === '') return
              const n = Number(v)
              if (!Number.isFinite(n) || n < 0 || n > 24) {
                setError('Hours slept has to be between 0 and 24.')
                return
              }
              if (n === local.sleep_hours) return
              save('sleep_hours', n)
            }}
          />
          <span className="small muted">hours</span>
        </div>
      </div>

      {error && <p className="error">{error}</p>}
    </section>
  )
}

function Scale({ scale, value, busy, onPick }) {
  return (
    <div className="field">
      <div className="row">
        <span className="label" id={`sc-${scale.key}`}>{scale.label}</span>
        {/* The endpoints, shown once next to the label rather than under every
            button — five captions under five buttons is noise. */}
        <span className="tiny muted push">{scale.low} → {scale.high}</span>
      </div>
      <div className="scale" role="radiogroup" aria-labelledby={`sc-${scale.key}`}>
        {[1, 2, 3, 4, 5].map((n) => (
          <button
            key={n}
            type="button"
            role="radio"
            aria-checked={value === n}
            className="scale-dot num"
            disabled={busy}
            onClick={() => onPick(n)}
          >
            {n}
          </button>
        ))}
      </div>
    </div>
  )
}

/** null-safe: a day with no check-in yet has no ratings, not zeroed ones. */
function fromServer(checkin) {
  return {
    energy: checkin?.energy ?? null,
    sleep_quality: checkin?.sleep_quality ?? null,
    soreness: checkin?.soreness ?? null,
    mood: checkin?.mood ?? null,
    sleep_hours: checkin?.sleep_hours ?? null,
  }
}

function hoursOf(checkin) {
  return checkin?.sleep_hours == null ? '' : String(checkin.sleep_hours)
}
