import { useEffect, useState } from 'react'

/**
 * The daily check-in (SPEC-coaching §4.1) — four taps and a number.
 *
 * Every rating saves on tap. There is no "save" button because a check-in is not
 * a form: the whole promise is that it is cheap, and a four-tap interaction that
 * needs a fifth tap to commit is a five-tap interaction. Partial check-ins are
 * valid server-side, so each rating stands on its own.
 *
 * Three things this card learned the hard way:
 *
 *   - It COLLAPSES once answered. It is answered once, early, and then sits
 *     between the user and the food and training sections for the rest of the
 *     day. The summary line keeps the answers visible while giving back the
 *     screen. Unlike the other sections that state is not persisted — see the
 *     comment on `open` for why remembering it is the wrong behaviour here.
 *   - A selected rating can be CLEARED by tapping it again. A value you can
 *     change but never undo is a trap, and a mis-tap otherwise becomes data the
 *     coach reads as fact.
 *   - Unanswered numbers are DIMMED. Rendered at full contrast they look like
 *     five equally valid answers rather than five empty slots.
 *
 * Ratings are read as a DELTA against the profile baselines, not as absolute
 * values — a 3 from someone who always sleeps badly means something different.
 * That is the server's job; this only collects them.
 */

const SCALES = [
  // Labels for 1 and 5 only. Labelling all five turns a glanceable row into a
  // paragraph, and the endpoints are what the number means.
  { key: 'energy', label: 'Energy', low: 'Drained', high: 'Full of it' },
  { key: 'sleep_quality', label: 'Sleep quality', low: 'Terrible', high: 'Excellent' },
  // Soreness inverts: 5 is BAD here, unlike every other row. The endpoint labels
  // carry that, which is why they are not optional.
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

  const answeredAny = SCALES.some((s) => local[s.key] != null) || local.sleep_hours != null

  /*
   * Open until answered, then shut — and deliberately NOT persisted, unlike Food
   * and Training.
   *
   * Those two are stable preferences ("I keep training closed"). This one is not:
   * whether the card should be open depends on whether TODAY is answered, and
   * that changes every day. Remembering the choice means someone who opened it on
   * an unanswered Tuesday has it pinned open every morning afterwards, which is
   * the exact obstruction collapsing was meant to remove.
   *
   * So a manual toggle applies to the day in front of you and nothing more.
   */
  const [open, setOpen] = useState(!answeredAny)
  const toggle = () => setOpen((v) => !v)

  /*
   * Re-sync the ratings when the server's copy changes — but NOT `open`.
   *
   * `checkin` is a fresh object on every save response, and an earlier version
   * re-derived open/shut here. That collapsed the card the instant the first
   * rating saved: you tapped energy, the response arrived, "answered" flipped
   * true, and the remaining four rows vanished under your thumb.
   *
   * The card should shut when you ARRIVE at an answered day, not the moment you
   * answer one — so that decision happens once, in useState above, and Today
   * remounts this component per date (via key) so "once" means once per day.
   */
  useEffect(() => {
    setLocal(fromServer(checkin))
    setHours(hoursOf(checkin))
  }, [checkin])

  async function save(field, value) {
    setError(null)
    setBusy(field)
    // Optimistic, and deliberately not rolled back on failure: the error says it
    // did not save, and silently reverting the tap is the more confusing
    // outcome. They can tap again.
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
      <div className="row sec-head">
        <button
          type="button"
          className="sec-toggle"
          aria-expanded={open}
          aria-controls="checkin-body"
          onClick={toggle}
        >
          <span className="sec-caret" aria-hidden="true">›</span>
          {/* A real heading, not a styled span — wrapping it in a toggle does not
              stop it being this card's title in the document outline. */}
          <span className="subheading" role="heading" aria-level="2" id="checkin-h">
            How are you today?
          </span>
        </button>
        {/* A receipt, not a nag: it says what is saved and never asks for the
            rest. Four taps is the ceiling, not a quota. */}
        <span className="tiny muted push num">
          {answered > 0 ? `${answered} of 5` : 'Not yet'}
        </span>
      </div>

      {/* Closed, the answers are still legible — that is what makes collapsing
          it safe rather than hiding the day's state. */}
      {!open && answeredAny && (
        <p className="tiny muted" style={{ margin: 0 }}>
          {summarise(local)}
        </p>
      )}

      {open && (
        <div id="checkin-body" className="stack">
          {SCALES.map((s) => (
            <Scale
              key={s.key}
              scale={s}
              value={local[s.key]}
              busy={busy === s.key}
              /* Tapping the selected number clears it. */
              onPick={(n) => save(s.key, local[s.key] === n ? null : n)}
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
                  // Emptying the box clears it, which is the undo for a number
                  // you cannot tap twice.
                  if (v === '') {
                    if (local.sleep_hours != null) save('sleep_hours', null)
                    return
                  }
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
              {local.sleep_hours != null && (
                <button
                  type="button"
                  className="btn btn--quiet"
                  onClick={() => {
                    setHours('')
                    save('sleep_hours', null)
                  }}
                >
                  Clear
                </button>
              )}
            </div>
          </div>

          {answeredAny && (
            <p className="tiny muted" style={{ margin: 0 }}>
              Tap a number again to unset it.
            </p>
          )}
        </div>
      )}

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
      <div
        className="scale"
        role="radiogroup"
        aria-labelledby={`sc-${scale.key}`}
        /* Dims the whole row until something is chosen: five full-contrast
           numbers read as five answers rather than an empty question. */
        data-answered={value != null ? 'true' : 'false'}
      >
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

/** The collapsed line. Only what was answered — a row of dashes is not a summary. */
function summarise(local) {
  const bits = []
  for (const s of SCALES) {
    if (local[s.key] != null) bits.push(`${s.label.toLowerCase()} ${local[s.key]}/5`)
  }
  if (local.sleep_hours != null) bits.push(`${local.sleep_hours}h sleep`)
  return bits.join(' · ')
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
