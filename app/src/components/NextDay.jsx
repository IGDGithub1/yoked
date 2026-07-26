import { useState } from 'react'
import { api } from '../api'

/**
 * The Next Day Review (SPEC-coaching §4.1a).
 *
 * Late each evening: tomorrow's session and meals, with a chance to say something before
 * the day arrives. It exists because "the Sunday plan cannot know everything. Travel
 * discovered on Wednesday for a Friday session has no other path into the plan."
 *
 * The sentence that governs every choice in here is the last bullet of §4.1a: "Optional
 * and dismissible; it must not become the noise the user was promised they'd be spared."
 * So:
 *
 *   - It renders NOTHING unless the server says the window is open. The client does not
 *     compute the hour; the server owns the schedule and answers null the rest of the
 *     time, which is most of the time.
 *   - Prep flags come FIRST, above the plan itself. "Tomorrow's dinner needs 40 minutes"
 *     is the only part that is actionable tonight, and burying it under a meal list would
 *     waste the whole reason for showing this in the evening.
 *   - Dismissing is one tap and lasts the day.
 *
 * "Something's up" records a FACT and stops there (§6.1: the user's message never edits
 * the plan). The copy says exactly that rather than implying tomorrow was rewritten,
 * because the full §6 evaluate-and-revise loop does not exist yet and an app that
 * overstates what it did is worse than one that waits.
 */

const SLOT_LABELS = {
  breakfast: 'Breakfast',
  lunch: 'Lunch',
  dinner: 'Dinner',
  snack_am: 'Morning snack',
  snack_pm: 'Afternoon snack',
  snack_eve: 'Evening snack',
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

const TYPE_LABELS = {
  strength: 'Strength',
  cardio: 'Cardio',
  hybrid: 'Strength and cardio',
  mobility: 'Mobility',
  active_recovery: 'Active recovery',
  rest: 'Rest',
}

/** What kinds of thing a user can report. Mirrors the circumstances enum. */
const KINDS = [
  { value: 'travel', label: 'Travelling' },
  { value: 'schedule', label: 'Busy' },
  { value: 'illness', label: 'Unwell' },
  { value: 'injury', label: 'Something hurts' },
  { value: 'equipment', label: 'No gym or kitchen' },
  { value: 'other', label: 'Something else' },
]

export default function NextDay({ review, onChanged }) {
  const [noting, setNoting] = useState(false)
  const [kind, setKind] = useState('travel')
  const [detail, setDetail] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [done, setDone] = useState(null)

  // The server said the window is shut, or there is nothing to show. Most evenings and
  // every daytime.
  if (!review) return null

  async function dismiss() {
    setBusy(true)
    try {
      await api.tomorrow.dismiss()
      onChanged?.()
    } catch {
      // A card that fails to dismiss is a nuisance, not an error worth a banner. It will
      // still be there next load and the tap can be repeated.
    } finally {
      setBusy(false)
    }
  }

  async function note(e) {
    e.preventDefault()
    if (!detail.trim()) return
    setError(null)
    setBusy(true)
    try {
      const r = await api.tomorrow.note({ kind, detail: detail.trim() })
      setDone(r.message || 'Noted.')
      setNoting(false)
      setDetail('')
      onChanged?.()
    } catch (err) {
      setError(err.message || 'That did not save. Try again.')
    } finally {
      setBusy(false)
    }
  }

  const { sessions = [], meals = [], prep_flags: prep = [], circumstances = [] } = review

  return (
    <section className="card stack-sm nextday">
      <div className="row">
        <h2 className="subheading">Tomorrow</h2>
        <span className="tiny muted push">{prettyDay(review.date)}</span>
      </div>

      {/*
        Prep first. It is the only part of this card that is actionable tonight, which is
        the entire argument for showing the card in the evening rather than in the morning.
      */}
      {prep.map((p) => (
        <p className="notice small" style={{ margin: 0 }} key={p.slot}>
          {SLOT_LABELS[p.slot] || p.slot} needs about {p.prep_minutes} minutes.
          {' '}{p.name}.
        </p>
      ))}

      {sessions.map((s) => (
        <div key={s.prescribed_session_id}>
          <p className="small" style={{ margin: 0, fontWeight: 600 }}>
            {FOCUS_LABELS[s.focus] || TYPE_LABELS[s.session_type] || 'Session'}
            {!s.is_committed && <span className="tag" style={{ marginLeft: 8 }}>optional</span>}
          </p>
          <p className="tiny muted" style={{ margin: 0 }}>
            {[
              s.target_minutes ? `${s.target_minutes} min` : null,
              LOCATION_LABELS[s.location] || null,
              s.exercises?.length ? s.exercises.slice(0, 3).join(', ') : null,
            ].filter(Boolean).join(' · ')}
          </p>
        </div>
      ))}

      {meals.length > 0 && (
        <p className="tiny muted" style={{ margin: 0 }}>
          {meals.map((m) => `${SLOT_LABELS[m.slot] || m.slot}: ${m.name}`).join(' · ')}
        </p>
      )}

      {/* What they already told us, so the card does not invite it twice. */}
      {circumstances.map((c) => (
        <p className="tiny muted" style={{ margin: 0 }} key={c.id}>
          Already noted: {c.detail}
        </p>
      ))}

      {done && <p className="small muted" style={{ margin: 0 }}>{done}</p>}

      {error && <p className="error">{error}</p>}

      {!noting ? (
        <div className="row" style={{ flexWrap: 'wrap' }}>
          <button type="button" className="btn btn--ghost" disabled={busy}
            onClick={() => setNoting(true)}>
            Something's up
          </button>
          <button type="button" className="btn btn--quiet" disabled={busy} onClick={dismiss}>
            Looks good
          </button>
        </div>
      ) : (
        <form className="stack-sm" onSubmit={note}>
          <div className="chips" role="radiogroup" aria-label="What kind of thing?">
            {KINDS.map((k) => (
              <button
                key={k.value}
                type="button"
                role="radio"
                aria-checked={kind === k.value}
                className="chip"
                onClick={() => setKind(k.value)}
              >
                {k.label}
              </button>
            ))}
          </div>

          <input
            className="input"
            placeholder="Flying out early, no gym"
            aria-label="What is going on?"
            value={detail}
            onChange={(e) => setDetail(e.target.value)}
          />

          {/* Honest about what happens next. §6.1 means this records a fact and the coach
              reads it; claiming the plan changed would be a lie until §6 lands. */}
          <p className="tiny muted" style={{ margin: 0 }}>
            Your coach reads this before writing your next week.
          </p>

          <div className="row">
            <button type="submit" className="btn btn--primary" disabled={busy || !detail.trim()}>
              {busy ? 'Saving…' : 'Tell my coach'}
            </button>
            <button type="button" className="btn btn--quiet" disabled={busy}
              onClick={() => setNoting(false)}>
              Never mind
            </button>
          </div>
        </form>
      )}
    </section>
  )
}

/** ENUM values, not display strings. */
const LOCATION_LABELS = {
  full_gym: 'Full gym',
  home_gym: 'Home gym',
  bodyweight: 'Bodyweight',
  outdoors: 'Outdoors',
}

/** "Sunday" is more useful than a date for something one sleep away. */
function prettyDay(date) {
  const [y, m, d] = date.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString(undefined, {
    weekday: 'long', day: 'numeric', month: 'short',
  })
}
