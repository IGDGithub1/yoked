import { useState } from 'react'

/**
 * The quiz field types.
 *
 * Every control is keyboard-operable and carries the right role, because the
 * choice rows and segmented buttons are custom rather than native inputs.
 * `radiogroup`/`radio` and `aria-pressed` are what make them announce correctly.
 */

/* ---- shared shell ------------------------------------------------------ */

export function Question({ q, children }) {
  return (
    <div className="q">
      <div className="q-head">
        <span className="q-num num">{q.key}</span>
        <h3 className="q-text">{q.label}</h3>
      </div>
      {q.help && <p className="q-help">{q.help}</p>}
      {children}
    </div>
  )
}

/* ---- single choice ----------------------------------------------------- */

export function Choice({ q, value, onChange }) {
  return (
    <div className="choices" role="radiogroup" aria-label={q.label}>
      {q.options.map((opt) => {
        const on = value === opt.value
        return (
          <button
            key={opt.value}
            type="button"
            role="radio"
            aria-checked={on}
            className="choice"
            onClick={() => onChange(opt.value)}
          >
            <span className="dot" aria-hidden="true" />
            <span>{opt.label}</span>
            {opt.note && <span className="choice-note">{opt.note}</span>}
          </button>
        )
      })}
    </div>
  )
}

/* ---- multi select ------------------------------------------------------ */

export function Multi({ q, value, onChange }) {
  const selected = Array.isArray(value) ? value : []

  const toggle = (v) =>
    onChange(selected.includes(v) ? selected.filter((x) => x !== v) : [...selected, v])

  return (
    <div className="stack-sm">
      <div className="chips">
        {q.options.map((opt) => {
          const on = selected.includes(opt.value)
          return (
            <button
              key={opt.value}
              type="button"
              aria-pressed={on}
              className="chip"
              onClick={() => toggle(opt.value)}
            >
              {opt.label}
            </button>
          )
        })}
      </div>
      {/*
        "None of these" has to be an explicit answer, not an empty array —
        the server distinguishes "asked and answered none" from "never asked",
        and a required section cannot complete without the difference.
      */}
      {q.emptyLabel && (
        <button
          type="button"
          className="btn btn--quiet"
          aria-pressed={selected.length === 0}
          onClick={() => onChange([])}
        >
          {q.emptyLabel}
        </button>
      )}
    </div>
  )
}

/* ---- free-text tags ---------------------------------------------------- */

/**
 * A list of user-typed items — allergies, disliked foods, movements.
 *
 * Each entry becomes its own constraint server-side, so they are kept as
 * separate items rather than one comma-joined string. Splitting on commas at
 * entry time means "peanuts, shellfish" does the obvious thing.
 */
export function Tags({ q, value, onChange }) {
  const [draft, setDraft] = useState('')
  const items = Array.isArray(value) ? value : []

  const add = () => {
    const parts = draft
      .split(/[,;]+/)
      .map((s) => s.trim())
      .filter(Boolean)
      .filter((s) => !items.some((i) => i.toLowerCase() === s.toLowerCase()))
    if (parts.length) onChange([...items, ...parts])
    setDraft('')
  }

  return (
    <div className="stack-sm">
      {items.length > 0 && (
        <div className="chips">
          {items.map((item) => (
            <span key={item} className="chip chip--fixed">
              {item}
              <button
                type="button"
                className="chip-x"
                aria-label={`Remove ${item}`}
                onClick={() => onChange(items.filter((i) => i !== item))}
              >
                ×
              </button>
            </span>
          ))}
        </div>
      )}
      <div className="row">
        <input
          className="input"
          value={draft}
          placeholder={q.placeholder || 'Type and press Enter'}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              add()
            }
          }}
          onBlur={add}
          aria-label={q.label}
        />
        <button type="button" className="btn btn--ghost" onClick={add} disabled={!draft.trim()}>
          Add
        </button>
      </div>
      {q.emptyLabel && items.length === 0 && (
        <button
          type="button"
          className="btn btn--quiet"
          aria-pressed={Array.isArray(value) && value.length === 0}
          onClick={() => onChange([])}
        >
          {q.emptyLabel}
        </button>
      )}
    </div>
  )
}

/* ---- text and numbers -------------------------------------------------- */

export function Text({ q, value, onChange }) {
  return (
    <input
      className="input"
      value={value ?? ''}
      placeholder={q.placeholder || ''}
      onChange={(e) => onChange(e.target.value)}
      aria-label={q.label}
    />
  )
}

export function LongText({ q, value, onChange }) {
  return (
    <textarea
      className="textarea"
      value={value ?? ''}
      placeholder={q.placeholder || ''}
      onChange={(e) => onChange(e.target.value)}
      aria-label={q.label}
    />
  )
}

export function NumberField({ q, value, onChange, units }) {
  // Height and weight are entered in whatever the user thinks in; the server
  // converts to metric on the way in.
  const suffix =
    q.unit === 'height' ? (units === 'metric' ? 'cm' : 'in')
    : q.unit === 'weight' ? (units === 'metric' ? 'kg' : 'lb')
    : null

  return (
    <div className="row">
      <input
        className="input num"
        type="number"
        inputMode={q.integer ? 'numeric' : 'decimal'}
        step={q.integer ? 1 : 'any'}
        value={value ?? ''}
        onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))}
        aria-label={q.label}
        style={{ maxWidth: 140 }}
      />
      {suffix && <span className="muted small">{suffix}</span>}
    </div>
  )
}

export function DateField({ q, value, onChange }) {
  return (
    <input
      className="input num"
      type="date"
      value={value ?? ''}
      onChange={(e) => onChange(e.target.value)}
      aria-label={q.label}
      style={{ maxWidth: 200 }}
    />
  )
}

export function Bool({ q, value, onChange }) {
  // Defaults to true where unanswered: 9.5 and 9.6 are privacy settings, and
  // private is the safe default for a body metric.
  const on = value === undefined || value === null ? true : Boolean(value)
  return (
    <button
      type="button"
      className="toggle"
      role="switch"
      aria-checked={on}
      onClick={() => onChange(!on)}
    >
      <span className="toggle-track" aria-hidden="true">
        <span className="toggle-knob" />
      </span>
      <span>{on ? 'Private' : 'Visible to other users'}</span>
    </button>
  )
}

/* ---- measurements ------------------------------------------------------ */

export function Measurements({ q, value, onChange, units }) {
  const m = value && typeof value === 'object' ? value : {}
  const unit = units === 'metric' ? 'cm' : 'in'

  const parts = [
    { key: 'waist', label: 'Waist', lead: true },
    { key: 'chest', label: 'Chest' },
    { key: 'hips', label: 'Hips' },
    { key: 'arm', label: 'Arm' },
    { key: 'thigh', label: 'Thigh' },
    { key: 'neck', label: 'Neck' },
  ]

  return (
    <div className="measure">
      {parts.map((p) => (
        <label key={p.key} className="measure-item">
          <span className="measure-label">
            {p.label}
            {/* Waist is called out because it tracks visceral fat, which is the
                measurement most goals here actually care about. */}
            {p.lead && <em> · matters most</em>}
          </span>
          <span className="row">
            <input
              className="input num"
              type="number"
              step="any"
              inputMode="decimal"
              value={m[p.key] ?? ''}
              onChange={(e) =>
                onChange({
                  ...m,
                  [p.key]: e.target.value === '' ? undefined : Number(e.target.value),
                })
              }
              aria-label={`${p.label} in ${unit}`}
            />
            <span className="muted tiny">{unit}</span>
          </span>
        </label>
      ))}
    </div>
  )
}

/* ---- injuries ---------------------------------------------------------- */

/**
 * Injuries, each with a user-chosen tier.
 *
 * The tier is the point: "avoid entirely" is enforced in code and never
 * prescribed; "work around" may still be loaded with a reason given. The two
 * are described in those terms rather than as hard/soft, because the words the
 * schema uses are not the words a person thinks in.
 */
export function Injuries({ q, value, onChange }) {
  const items = Array.isArray(value) ? value : []

  const update = (i, patch) =>
    onChange(items.map((item, n) => (n === i ? { ...item, ...patch } : item)))

  const add = () =>
    onChange([...items, { area: '', description: '', tier: 'hard', work_up_to: false }])

  return (
    <div className="stack">
      {items.map((item, i) => (
        <div key={i} className="injury">
          <div className="row">
            <input
              className="input"
              placeholder="Which part? e.g. left knee"
              value={item.area}
              onChange={(e) => update(i, { area: e.target.value })}
              aria-label="Body area"
            />
            <button
              type="button"
              className="btn btn--quiet"
              onClick={() => onChange(items.filter((_, n) => n !== i))}
              aria-label="Remove this injury"
            >
              Remove
            </button>
          </div>

          <input
            className="input"
            placeholder="What happened? e.g. ACL surgery in 2019, aches under load"
            value={item.description}
            onChange={(e) => update(i, { description: e.target.value })}
            aria-label="Description"
          />

          <div className="seg" role="radiogroup" aria-label="How should this be handled?">
            <button
              type="button"
              role="radio"
              aria-checked={item.tier === 'hard'}
              onClick={() => update(i, { tier: 'hard', work_up_to: false })}
            >
              Avoid entirely
            </button>
            <button
              type="button"
              role="radio"
              aria-checked={item.tier === 'soft'}
              onClick={() => update(i, { tier: 'soft' })}
            >
              Work around it
            </button>
          </div>

          <p className="tiny muted" style={{ margin: 0 }}>
            {item.tier === 'hard'
              ? 'Never appears in a plan.'
              : 'May still be loaded, with a reason given. You can turn it down.'}
          </p>

          {/* Only meaningful for "work around" — building toward something you
              never do is incoherent, so the option disappears. */}
          {item.tier === 'soft' && (
            <label className="check">
              <input
                type="checkbox"
                checked={Boolean(item.work_up_to)}
                onChange={(e) => update(i, { work_up_to: e.target.checked })}
              />
              <span>I want to work back up to this</span>
            </label>
          )}
        </div>
      ))}

      <div className="row">
        <button type="button" className="btn btn--ghost" onClick={add}>
          Add an injury
        </button>
        {items.length === 0 && (
          <button
            type="button"
            className="btn btn--quiet"
            aria-pressed={Array.isArray(value) && value.length === 0}
            onClick={() => onChange([])}
          >
            None
          </button>
        )}
      </div>
    </div>
  )
}

/* ---- dispatcher -------------------------------------------------------- */

export function Field({ q, value, onChange, units }) {
  const shared = { q, value, onChange, units }
  switch (q.type) {
    case 'choice':       return <Choice {...shared} />
    case 'multi':        return <Multi {...shared} />
    case 'tags':         return <Tags {...shared} />
    case 'text':         return <Text {...shared} />
    case 'longtext':     return <LongText {...shared} />
    case 'number':       return <NumberField {...shared} />
    case 'date':         return <DateField {...shared} />
    case 'bool':         return <Bool {...shared} />
    case 'measurements': return <Measurements {...shared} />
    case 'injuries':     return <Injuries {...shared} />
    default:
      // A key in questions.js with no matching control is a programming error,
      // and silently rendering nothing would hide it.
      return <p className="error">No control for question type "{q.type}".</p>
  }
}
