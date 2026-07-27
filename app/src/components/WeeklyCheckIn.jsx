import { useState } from 'react'
import { api } from '../api'
import Help from './Help'

/**
 * The weekly check-in (SPEC-coaching §7.2).
 *
 * Opens Saturday evening and shapes the plan that generates Sunday evening, so the
 * form's job is to be answerable in two minutes on a phone. Everything is optional:
 * a check-in that says only "knee felt off Thursday" is worth more than one nobody
 * filled in, and demanding six measurements weekly is how you get zero check-ins by
 * week four.
 *
 * Three things it has to be honest about:
 *
 *   - WHICH WEEK it covers. The form opens on Saturday, so "this week" is ambiguous
 *     without the dates.
 *   - WHETHER IT STILL COUNTS. Answer before Sunday evening and it shapes the plan.
 *     Answer after and the plan already exists, so the coach reads it and changes
 *     the plan only if something in it needs changing. The user should not have to
 *     guess which case they are in.
 *   - THAT SKIPPING IS ALLOWED. A user who does not want to report a week can say
 *     so once instead of being chased for it.
 *
 * Numbers are entered in the user's own units and converted server-side, matching
 * onboarding. The client never divides.
 */

const MEASUREMENTS = [
  // Waist first: §7.2 calls it the one that matters most, and a user who fills in
  // exactly one field should fill in that one.
  { key: 'waist_cm', label: 'Waist', lead: true },
  { key: 'chest_cm', label: 'Chest' },
  { key: 'hips_cm', label: 'Hips' },
  { key: 'arm_cm', label: 'Arm' },
  { key: 'thigh_cm', label: 'Thigh' },
  { key: 'neck_cm', label: 'Neck' },
]

export default function WeeklyCheckIn({ data, onAnswered }) {
  const { pending, last_review: lastReview, units, history } = data
  const metric = units === 'metric'
  const wUnit = metric ? 'kg' : 'lb'
  const lUnit = metric ? 'cm' : 'in'

  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({})
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [done, setDone] = useState(null)
  const [showMeasurements, setShowMeasurements] = useState(false)

  const set = (k) => (e) =>
    setForm((f) => ({ ...f, [k]: e.target.value === '' ? undefined : e.target.value }))

  // Nothing to answer and nothing to read: five days out of seven this component
  // renders nothing at all, which is the correct amount of noise.
  if (!pending && !lastReview) return null

  async function submit(e) {
    e.preventDefault()
    setError(null)
    setBusy(true)
    try {
      const payload = {}
      // Only keys the user actually filled in. Sending undefined for the rest
      // would clear values on a re-answer, and omitting them leaves them alone.
      if (form.weight != null && form.weight !== '') payload.weight_kg = Number(form.weight)
      for (const m of MEASUREMENTS) {
        if (form[m.key] != null && form[m.key] !== '') payload[m.key] = Number(form[m.key])
      }
      if (form.self_report?.trim()) payload.self_report = form.self_report.trim()
      if (form.emphasis?.trim()) payload.emphasis_request = form.emphasis.trim()

      const r = await api.weekly.answer(pending.id, payload)
      setDone(r.message || 'Saved.')
      onAnswered?.()
    } catch (err) {
      setError(err.message || 'That did not save. Try again.')
    } finally {
      setBusy(false)
    }
  }

  async function skip() {
    setError(null)
    setBusy(true)
    try {
      await api.weekly.skip(pending.id)
      onAnswered?.()
    } catch (err) {
      setError(err.message || 'Could not skip that.')
    } finally {
      setBusy(false)
    }
  }

  // Just submitted. Says what happens next rather than only "saved", because the
  // two cases mean genuinely different things.
  if (done) {
    return (
      <section className="card stack-sm">
        <h2 className="subheading">Thanks</h2>
        <p className="small muted" style={{ margin: 0 }}>{done}</p>
      </section>
    )
  }

  if (!pending) {
    // No open check-in, but there is a review to read. Without this the coach
    // writes a review nobody ever sees.
    return <LastReview review={lastReview} />
  }

  return (
    <section className="card stack-sm checkin-weekly">
      <div className="row">
        <h2 className="subheading">Your week</h2>
        {pending.can_shape_plan
          ? <span className="tag push">shapes next week</span>
          : <span className="tag push">plan already built</span>}
      </div>

      <p className="tiny muted" style={{ margin: 0 }}>
        {formatRange(pending.week_start, pending.week_end)}
      </p>

      {/* The honest version of "you are late". Not a scold: the plan exists and
          their answers still matter, just differently. */}
      {!pending.can_shape_plan && (
        <p className="small muted prose" style={{ margin: 0 }}>
          Next week's plan is already built. Fill this in anyway and your coach will
          change it if something here needs changing.
        </p>
      )}

      {!open ? (
        <div className="row" style={{ flexWrap: 'wrap' }}>
          <button type="button" className="btn btn--primary" onClick={() => setOpen(true)}>
            Fill it in
          </button>
          <button type="button" className="btn btn--quiet" disabled={busy} onClick={skip}>
            Not this week
          </button>
        </div>
      ) : (
        <form className="stack-sm" onSubmit={submit}>
          <div className="row" style={{ flexWrap: 'wrap', gap: 10 }}>
            <label className="field" style={{ maxWidth: '9em' }}>
              <span className="tiny muted">Weight ({wUnit})</span>
              <input className="input num" type="number" inputMode="decimal" step="any" min="0"
                value={form.weight ?? ''} onChange={set('weight')} />
            </label>
            <label className="field" style={{ maxWidth: '9em' }}>
              <span className="tiny muted">Waist ({lUnit})</span>
              <input className="input num" type="number" inputMode="decimal" step="any" min="0"
                value={form.waist_cm ?? ''} onChange={set('waist_cm')} />
            </label>
          </div>

          {/* The other five are behind a toggle. Waist is the one that matters most,
              and six boxes on first open makes a two-minute form look like a
              medical intake. */}
          {!showMeasurements ? (
            <button type="button" className="btn btn--quiet"
              onClick={() => setShowMeasurements(true)}>
              Add the other measurements
            </button>
          ) : (
            <div className="macro-grid">
              {MEASUREMENTS.filter((m) => !m.lead).map((m) => (
                <label className="field" key={m.key}>
                  <span className="tiny muted">{m.label}</span>
                  <input className="input num" type="number" inputMode="decimal" step="any" min="0"
                    value={form[m.key] ?? ''} onChange={set(m.key)} />
                </label>
              ))}
            </div>
          )}

          {/*
            Progress photos (§7.2).

            Optional, and last, because the form has to stay answerable in two minutes and
            somebody who does not want to photograph themselves should not have to scroll past
            it three times. The scale lies in both directions during a recomp, which is the
            argument for having them at all.
          */}
          <PhotoRow checkinId={pending.id} photos={pending.photos ?? {}} onChanged={onAnswered} />

          <label className="field">
            <span className="tiny muted">How did the week go?</span>
            <textarea
              className="textarea"
              rows="3"
              placeholder="Anything your coach should know. What worked, what got in the way."
              value={form.self_report ?? ''}
              onChange={set('self_report')}
            />
          </label>

          <div className="field">
            <span className="tiny muted" id="emphasis-label">
              Anything you want more of?
              <Help label="emphasis requests">
                If you have been following the plan, you get a say in it. Ask for more
                of something and your coach will work it into the coming weeks where
                it makes sense.
              </Help>
            </span>
            <input
              className="input"
              aria-labelledby="emphasis-label"
              placeholder="Glutes, shoulders, more cardio, whatever it is"
              value={form.emphasis ?? ''}
              onChange={set('emphasis')}
            />
          </div>

          {history?.length > 0 && (
            <p className="tiny muted num" style={{ margin: 0 }}>
              Last time: {history[0].weight} {wUnit}
              {history[0].waist ? `, waist ${history[0].waist} ${lUnit}` : ''}
              {' '}({history[0].week_start})
            </p>
          )}

          {error && <p className="error">{error}</p>}

          <div className="row">
            <button type="submit" className="btn btn--primary" disabled={busy}>
              {busy ? 'Saving…' : 'Send it'}
            </button>
            <button type="button" className="btn btn--quiet" disabled={busy}
              onClick={() => setOpen(false)}>
              Later
            </button>
          </div>
        </form>
      )}

      {error && !open && <p className="error">{error}</p>}
    </section>
  )
}

/**
 * What the coach said back.
 *
 * Worth its own treatment: this is the one piece of writing in the app that is
 * genuinely addressed to the user, and burying it would waste the call that
 * produced it.
 */
function LastReview({ review }) {
  const [open, setOpen] = useState(false)

  return (
    <section className="card stack-sm">
      <div className="row sec-head">
        <button
          type="button"
          className="sec-toggle"
          aria-expanded={open}
          aria-controls="last-review"
          onClick={() => setOpen((v) => !v)}
        >
          <span className="sec-caret" aria-hidden="true">›</span>
          <span className="subheading" role="heading" aria-level="2">
            Your coach on last week
          </span>
        </button>
        {/* Says the plan changed when it did. A superseded plan the user was never
            told about is the kind of surprise that costs trust. */}
        {review.late_outcome === 'altered' && (
          <span className="tag push">plan updated</span>
        )}
      </div>

      {open && (
        <div id="last-review" className="stack-sm">
          <p className="tiny muted" style={{ margin: 0 }}>
            Week of {review.week_start}
          </p>
          {/* pre-wrap: the review is prose with paragraph breaks, and collapsing
              them turns a considered read into a wall. */}
          <p className="small prose" style={{ margin: 0, whiteSpace: 'pre-wrap' }}>
            {review.review}
          </p>
          {review.late_outcome === 'altered' && (
            <p className="notice small" style={{ margin: 0 }}>
              Your plan for this week was rebuilt based on what you said.
            </p>
          )}
        </div>
      )}
    </section>
  )
}

/** "21 to 27 July" rather than two ISO dates. */
function formatRange(start, end) {
  const a = new Date(`${start}T00:00:00`)
  const b = new Date(`${end}T00:00:00`)
  const sameMonth = a.getMonth() === b.getMonth()
  const day = (d) => d.getDate()
  const month = (d) => d.toLocaleDateString(undefined, { month: 'long' })
  return sameMonth
    ? `${day(a)} to ${day(b)} ${month(b)}`
    : `${day(a)} ${month(a)} to ${day(b)} ${month(b)}`
}

const ANGLES = [
  { key: 'front', label: 'Front' },
  { key: 'side', label: 'Side' },
  { key: 'back', label: 'Back' },
]

/**
 * Three optional photo slots (§7.2).
 *
 * OPTIONAL AND SAID SO. Photos are the strongest evidence a recomp is working — the scale moves
 * the wrong way while the mirror moves the right way — but somebody who does not want to
 * photograph themselves must not feel chased for it. Nothing here is required and the copy does
 * not nag.
 *
 * `capture="environment"` opens the rear camera on a phone rather than the file picker, because
 * this is almost always a photo being taken now rather than one being found.
 *
 * The image comes from GET /api/media/{id}, which checks the asker owns it. That means an
 * <img src> works because the session cookie rides along — but it also means these are never
 * shareable URLs, which is the point (§10.4: pairing up to train is not consent to share body
 * metrics, and hide_photos defaults to on).
 */
function PhotoRow({ checkinId, photos, onChanged }) {
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState(null)

  async function upload(angle, file) {
    if (!file) return
    setError(null)
    setBusy(angle)
    try {
      await api.checkin.photo(checkinId, angle, file)
      onChanged?.()
    } catch (e) {
      setError(e.message || 'That photo did not upload.')
    } finally {
      setBusy(null)
    }
  }

  async function remove(angle) {
    setError(null)
    setBusy(angle)
    try {
      await api.checkin.removePhoto(checkinId, angle)
      onChanged?.()
    } catch (e) {
      setError(e.message || 'That did not delete.')
    } finally {
      setBusy(null)
    }
  }

  return (
    <div className="field">
      <span className="tiny muted">
        Progress photos, if you want them
        <Help label="progress photos">
          Every couple of weeks is enough to see a change; weekly rarely is. They are private
          to you — not your buddy, not your coach — and the point is that the scale can sit
          still for a month while these do not.
        </Help>
      </span>

      <div className="row" style={{ gap: 8, flexWrap: 'wrap' }}>
        {ANGLES.map(({ key, label }) => {
          const mediaId = photos[key]
          return (
            <div key={key} className="stack-tight" style={{ alignItems: 'center' }}>
              {mediaId ? (
                <>
                  <img
                    src={`/api/media/${mediaId}?size=thumb`}
                    alt={`${label} progress photo`}
                    style={{
                      width: 84,
                      height: 84,
                      objectFit: 'cover',
                      borderRadius: 8,
                      display: 'block',
                    }}
                  />
                  <button
                    type="button"
                    className="btn btn--quiet btn--small"
                    disabled={busy === key}
                    onClick={() => remove(key)}
                  >
                    Remove
                  </button>
                </>
              ) : (
                <label
                  className="btn btn--quiet btn--small"
                  style={{
                    width: 84,
                    height: 84,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    cursor: 'pointer',
                  }}
                >
                  {busy === key ? '…' : label}
                  <input
                    type="file"
                    accept="image/jpeg,image/png"
                    capture="environment"
                    aria-label={`Add a ${label.toLowerCase()} photo`}
                    style={{ display: 'none' }}
                    disabled={busy === key}
                    onChange={(e) => upload(key, e.target.files?.[0])}
                  />
                </label>
              )}
            </div>
          )
        })}
      </div>

      {error && <p className="error tiny" style={{ margin: 0 }}>{error}</p>}
    </div>
  )
}
