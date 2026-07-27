import { useEffect, useState } from 'react'
import { api } from '../api'
import Help from './Help'

/**
 * How the app behaves, as opposed to who the user is.
 *
 * Two groups. The schedule and the pause switch were live in the database with a
 * default and no way to reach them. The coaching voice, explanation depth, nudges and the
 * privacy toggles were section 9 of the quiz until they moved here, because none of them was
 * really a question: asking someone to pick a coaching voice before they have read a word
 * the coach writes is asking them to guess. Here they can change their mind after finding
 * out.
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

const TONES = [
  { value: 'friendly_encouraging', label: 'Friendly and encouraging', note: 'calm, patient' },
  { value: 'direct_no_fluff', label: 'Direct, no fluff', note: 'says the thing and stops' },
  { value: 'high_school_coach', label: 'High school coach', note: 'never satisfied, always in your corner' },
  { value: 'sarcastic_hardass', label: 'Sarcastic hardass', note: 'roasts your excuses, not you' },
  { value: 'motivational_speaker', label: 'Motivational speaker', note: 'big energy' },
  { value: 'funny_positive', label: 'Funny and positive', note: 'light, a bit silly' },
]

const DEPTHS = [
  { value: 'just_tell_me', label: 'Just tell me what to do' },
  { value: 'brief', label: 'A quick reason' },
  { value: 'explain', label: 'Explain the thinking' },
]

const INTENSITIES = [
  { value: 'leave_me_alone', label: 'Leave me alone' },
  { value: 'gentle', label: 'A nudge' },
  { value: 'persistent', label: 'Keep at me' },
  { value: 'relentless', label: 'Relentless' },
]

/** 24-hour values, labelled the way a person reads a clock. */
const HOURS = Array.from({ length: 24 }, (_, h) => ({
  value: h,
  label: h === 0 ? '12am' : h < 12 ? `${h}am` : h === 12 ? '12pm' : `${h - 12}pm`,
}))

/*
 * Plain English for the equipment tokens the API offers.
 *
 * Keyed off what the server sends in home_kit_options rather than hardcoding the list, so
 * adding a seventh item is a server change and this only supplies the wording. An unknown key
 * falls back to the token itself, which is ugly but visible — better than an empty button.
 */
const HOME_KIT_LABELS = {
  dumbbell: 'Dumbbells',
  bench: 'Bench',
  resistance_band: 'Resistance bands',
  pull_up_bar: 'Pull-up bar',
  kettlebell: 'Kettlebell',
  barbell: 'Barbell and rack',
}

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

      {/*
        The voice, and how much it explains. First of the former section 9, because it is the
        one a user is most likely to want changed: a tone that grated is felt every day.
      */}
      <div className="card stack-sm">
        <h3 className="subheading">How your coach talks to you</h3>
        <p className="tiny muted prose" style={{ margin: 0 }}>
          This applies everywhere, including about food and your body.
        </p>
        <Picker
          label="Voice"
          options={TONES}
          value={s.tone}
          busy={busy}
          onChange={(v) => save({ tone: v })}
        />
        <Picker
          label="How much explanation"
          options={DEPTHS}
          value={s.explanation_depth}
          busy={busy}
          onChange={(v) => save({ explanation_depth: v })}
        />
      </div>

      <div className="card stack-sm">
        <h3 className="subheading">If you go quiet</h3>
        <Picker
          label="How hard to push"
          options={INTENSITIES}
          value={s.nudge_intensity}
          busy={busy}
          onChange={(v) => save({ nudge_intensity: v })}
        />
        <label className="field">
          <span className="label">Days of silence before we say something</span>
          <select
            className="input"
            value={s.nudge_after_days}
            disabled={busy}
            onChange={(e) => save({ nudge_after_days: Number(e.target.value) })}
          >
            {[1, 2, 3, 4, 5, 7, 10, 14, 21, 30].map((d) => (
              <option key={d} value={d}>{d === 1 ? '1 day' : `${d} days`}</option>
            ))}
          </select>
        </label>
      </div>

      {/*
        What is in the home gym.

        Only affects days marked "Home gym" in the availability grid — a full gym day is
        unchanged by it. Editable here because kit changes: people buy a bench, or move house
        and lose the garage rack, and neither should mean re-running onboarding.

        NULL and empty are different states and the copy says which one you are in. Never
        answered keeps the old permissive behaviour; answered-with-nothing means bodyweight
        work at home.
      */}
      <div className="card stack-sm">
        <h3 className="subheading">Your home gym</h3>
        <p className="tiny muted prose" style={{ margin: 0 }}>
          {s.home_equipment === null
            ? 'We have never asked, so we assume you have everything on the days you train at '
              + 'home. Tell us what is actually there and your plan will stick to it.'
            : 'Used on the days your schedule says "Home gym". Your gym days are unaffected.'}
        </p>
        <div className="row" style={{ gap: 6, flexWrap: 'wrap' }}>
          {(s.home_kit_options ?? []).map((item) => {
            const owned = (s.home_equipment ?? []).includes(item)
            return (
              <button
                key={item}
                type="button"
                className={owned ? 'btn btn--primary btn--small' : 'btn btn--quiet btn--small'}
                aria-pressed={owned}
                disabled={busy}
                onClick={() => {
                  const now = s.home_equipment ?? []
                  save({
                    home_equipment: owned
                      ? now.filter((x) => x !== item)
                      : [...now, item],
                  })
                }}
              >
                {HOME_KIT_LABELS[item] ?? item}
              </button>
            )
          })}
        </div>
        {s.home_equipment !== null && s.home_equipment.length === 0 && (
          <p className="tiny muted prose" style={{ margin: 0 }}>
            Nothing selected, so home days will be bodyweight work.
          </p>
        )}
      </div>

      <div className="card stack-sm">
        <h3 className="subheading">Privacy</h3>
        <Switch
          label="Keep progress photos private"
          on={s.hide_photos}
          busy={busy}
          onChange={(v) => save({ hide_photos: v })}
        />
        <Switch
          label="Keep weight and measurements private"
          on={s.hide_measurements}
          busy={busy}
          onChange={(v) => save({ hide_measurements: v })}
        />
        {/*
          Honest about the scope, which is narrow.
          These now govern what a training buddy can see, and nothing else: there is no
          public profile and no other reader. Worth saying, because "keep private" with no
          object invites the reader to imagine a larger audience than exists.
        */}
        <p className="tiny muted prose" style={{ margin: 0 }}>
          These control what a training buddy can see. Your sessions are always visible to a
          buddy, since that is the point of pairing up. Your weight, measurements and photos
          are not, unless you turn them on here.
        </p>
      </div>

      {error && <p className="error">{error}</p>}
    </div>
  )
}

/**
 * A labelled set of chips.
 *
 * Chips rather than a select: these are short, mutually exclusive, and worth seeing all at
 * once — picking a coaching voice is a comparison, not a lookup. The notes under each are
 * what make the choice meaningful, and a dropdown cannot show them.
 */
function Picker({ label, options, value, busy, onChange }) {
  return (
    <div className="field">
      <span className="label">{label}</span>
      <div className="chips" role="radiogroup" aria-label={label}>
        {options.map((o) => (
          <button
            key={o.value}
            type="button"
            role="radio"
            aria-checked={value === o.value}
            className="chip"
            disabled={busy}
            onClick={() => onChange(o.value)}
            title={o.note || undefined}
          >
            {o.label}
          </button>
        ))}
      </div>
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
