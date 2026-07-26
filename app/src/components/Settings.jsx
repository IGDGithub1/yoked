import { useEffect, useState } from 'react'
import { api } from '../api'
import Help from './Help'

/**
 * The settings the quiz never asked.
 *
 * Every field here was live in the database with a default and no way to change it. §9 of
 * the quiz already edits tone, nudges and units, so none of those appear — two editors for
 * one field is how they drift.
 *
 * SAVES ON CHANGE, one field at a time, with no Save button. These are independent switches
 * rather than a form: there is no state in which half of them are valid together, and a
 * button implies a commit step that does not exist. The PUT is partial for the same reason,
 * so toggling the pause cannot reset the check-in day.
 */

const DAYS = [
  { value: 1, label: 'Monday' },
  { value: 2, label: 'Tuesday' },
  { value: 3, label: 'Wednesday' },
  { value: 4, label: 'Thursday' },
  { value: 5, label: 'Friday' },
  { value: 6, label: 'Saturday' },
  { value: 7, label: 'Sunday' },
]

const CORE = [
  { value: 'off', label: 'None' },
  { value: 'light', label: 'Light' },
  { value: 'standard', label: 'Standard' },
  { value: 'heavy', label: 'Heavy' },
]

/** 24-hour values, labelled the way a person reads a clock. */
const HOURS = Array.from({ length: 24 }, (_, h) => ({
  value: h,
  label: h === 0 ? '12am' : h < 12 ? `${h}am` : h === 12 ? '12pm' : `${h - 12}pm`,
}))

export default function Settings() {
  const [s, setS] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api.settings.load()
      .then((r) => setS(r.settings))
      .catch((e) => setError(e.message || 'Could not load your settings.'))
  }, [])

  async function save(fields) {
    setError(null)
    setBusy(true)
    try {
      const r = await api.settings.save(fields)
      // Rendered from the server's copy, not from what we hoped we sent. The schedule rule
      // can reject a change, and showing the requested value after a refusal would be a lie.
      setS(r.settings)
    } catch (e) {
      setError(e.message || 'That did not save.')
      // Re-read, so a rejected change does not leave the control showing the value the
      // server refused.
      api.settings.load().then((r) => setS(r.settings)).catch(() => {})
    } finally {
      setBusy(false)
    }
  }

  if (error && s === null) {
    return <p className="error">{error}</p>
  }
  if (s === null) {
    return <p className="small muted">Loading your settings…</p>
  }

  return (
    <div className="stack">
      {/*
        The pause, first and on its own.
        It is the only setting that stops the app doing anything, and a user who came here
        looking for it (hurt, travelling, fed up) should not have to scroll past scheduling
        to find it.
      */}
      <div className="card stack-sm">
        <Switch
          label="Pause coaching"
          on={s.coaching_paused}
          busy={busy}
          onChange={(v) => save({ coaching_paused: v })}
        />
        <p className="tiny muted prose" style={{ margin: 0 }}>
          {s.coaching_paused
            ? 'Paused. No new plans, no check-ins, no nudges. Your logs still save, and '
              + 'nothing you have entered is lost.'
            : 'Stops new plans, check-ins and nudges until you turn it back on. Logging '
              + 'keeps working.'}
        </p>
      </div>

      <div className="card stack-sm">
        <div className="row">
          <h3 className="subheading">When things happen</h3>
          {s.timezone && (
            // Without the zone, "6pm" is ambiguous. It is detected from the browser rather
            // than editable, because the device is a better source of truth than a dropdown
            // and it self-corrects after a move.
            <span className="tiny muted push">{s.timezone.replace(/_/g, ' ')}</span>
          )}
        </div>

        <Slot
          label="Weekly check-in opens"
          weekday={s.checkin.weekday}
          hour={s.checkin.hour}
          busy={busy}
          onChange={(f) => save({ checkin_weekday: f.weekday, checkin_hour: f.hour })}
        />

        <Slot
          label="Next week's plan arrives"
          weekday={s.plan.weekday}
          hour={s.plan.hour}
          busy={busy}
          onChange={(f) => save({
            plan_generation_weekday: f.weekday,
            plan_generation_hour: f.hour,
          })}
        />

        <p className="tiny muted prose" style={{ margin: 0 }}>
          Your check-in has to open before your plan is written, so there is time to answer
          it.
        </p>

        {/*
          The review hour, and 0 genuinely means off rather than midnight.
          Kept apart from the two slots above because it is a daily thing, not weekly, and
          because it is the one here that can be switched off entirely.
        */}
        <div className="field" style={{ marginTop: 4 }}>
          <span className="label">
            Tomorrow preview
            <Help label="What this is">
              A card each evening showing tomorrow's session and meals, so nothing needs
              planning at 7am. Set it to off if you would rather not think about tomorrow
              tonight.
            </Help>
          </span>
          {/*
            An explicit aria-label rather than a wrapping <label>.
            The Help button's "?" is real text inside the label span, so a wrapped control
            gets an accessible name of "Tomorrow preview ?" — the glyph is announced as part
            of the field's name. Naming the select directly keeps the help available without
            it becoming part of what the control is called.
          */}
          <select
            className="input"
            aria-label="Tomorrow preview"
            value={s.review_hour}
            disabled={busy}
            onChange={(e) => save({ review_hour: Number(e.target.value) })}
          >
            <option value={0}>Off</option>
            {HOURS.filter((h) => h.value >= 17).map((h) => (
              <option key={h.value} value={h.value}>{h.label}</option>
            ))}
          </select>
        </div>
      </div>

      <div className="card stack-sm">
        <h3 className="subheading">Core work</h3>
        <p className="tiny muted prose" style={{ margin: 0 }}>
          How much core work your sessions carry. Standard unless you say otherwise.
        </p>
        <div className="chips" role="radiogroup" aria-label="Core work">
          {CORE.map((c) => (
            <button
              key={c.value}
              type="button"
              role="radio"
              aria-checked={s.core_emphasis === c.value}
              className="chip"
              disabled={busy}
              onClick={() => save({ core_emphasis: c.value })}
            >
              {c.label}
            </button>
          ))}
        </div>
      </div>

      {error && <p className="error">{error}</p>}
    </div>
  )
}

/** A labelled on/off switch. Fields.jsx's Bool hardcodes privacy wording. */
function Switch({ label, on, busy, onChange }) {
  return (
    <button
      type="button"
      className="toggle"
      role="switch"
      aria-checked={on}
      disabled={busy}
      onClick={() => onChange(!on)}
    >
      <span className="toggle-track" aria-hidden="true">
        <span className="toggle-knob" />
      </span>
      <span>{label}</span>
    </button>
  )
}

/**
 * A weekday plus an hour, saved together.
 *
 * Together rather than as two independent writes because the server checks that the check-in
 * precedes generation. Sending the day alone can transiently break that rule and be
 * rejected, which would leave the user staring at a refusal for a change they were halfway
 * through making.
 */
function Slot({ label, weekday, hour, busy, onChange }) {
  return (
    <div className="field">
      <span className="label">{label}</span>
      <div className="row" style={{ gap: 8 }}>
        <select
          className="input"
          aria-label={`${label}: day`}
          value={weekday}
          disabled={busy}
          onChange={(e) => onChange({ weekday: Number(e.target.value), hour })}
        >
          {DAYS.map((d) => <option key={d.value} value={d.value}>{d.label}</option>)}
        </select>
        <select
          className="input"
          aria-label={`${label}: time`}
          value={hour}
          disabled={busy}
          onChange={(e) => onChange({ weekday, hour: Number(e.target.value) })}
        >
          {HOURS.map((h) => <option key={h.value} value={h.value}>{h.label}</option>)}
        </select>
      </div>
    </div>
  )
}
