import { useState } from 'react'
import { api } from '../api'

/**
 * Food logging for one day.
 *
 * The intake model is Keto Tracker's, carried over because it survived real
 * daily use (SPEC-nutrition.md). Two things about it are load-bearing and are
 * the reason this component is shaped the way it is:
 *
 *   1. Meal total = manual delta + SUM(entries), and the delta is ADDITIVE.
 *      The nudge buttons send the running delta, not an increment, because the
 *      server treats it as absolute against itself — +50 twice is still +50.
 *   2. Net carbs are derived server-side at intake. This component sends
 *      total_carbs and fiber and never computes `carbs` itself.
 *
 * Nothing here judges the day. Totals are shown against target, but the verdict
 * is the server's — an old client must not be able to re-grade history.
 */

const SLOT_LABELS = {
  breakfast: 'Breakfast',
  lunch: 'Lunch',
  dinner: 'Dinner',
  snack_am: 'Morning snack',
  snack_pm: 'Afternoon snack',
  snack_eve: 'Evening snack',
}

const ADHERENCE_LABELS = {
  as_planned: 'as planned',
  substituted: 'substituted',
  skipped: 'skipped',
  unplanned: 'off-plan',
}

export default function Food({ day, date, isToday, onDay }) {
  const totals = day.totals || {}
  const target = day.target

  // An untouched snack slot is not an empty state worth showing — it is the
  // normal condition of most slots on most days. Prescribed and logged slots
  // always show; the rest collapse behind one button.
  const prescribedSlots = new Set((day.prescribed || []).map((p) => p.slot))
  const meals = day.meals || []
  const interesting = (m) =>
    m.entries.length > 0 || prescribedSlots.has(m.slot) || m.adherence === 'skipped'
  const [showAll, setShowAll] = useState(false)
  const hidden = meals.filter((m) => !interesting(m))
  const shown = showAll ? meals : meals.filter(interesting)

  // The first prescribed slot with nothing logged, in eating order. SLOTS is
  // already ordered server-side, so the array order IS eating order.
  const nextSlot = shown.find(
    (m) => m.entries.length === 0 && m.adherence !== 'skipped' && prescribedSlots.has(m.slot)
  )?.slot

  return (
    <section className="stack" aria-labelledby="food-h">
      <div className="row">
        <h2 className="heading" id="food-h">Food</h2>
        <div className="push" style={{ textAlign: 'right' }}>
          <div className="num" style={{ fontWeight: 600 }}>
            {Math.round(totals.calories || 0)}
            {target && <span className="muted"> / {Math.round(target.calories)}</span>}
            <span className="small muted"> kcal</span>
          </div>
          <MacroLine totals={totals} target={target} />
        </div>
      </div>

      {/*
        The verdict is displayed, never computed here — and only once the day is
        over. The server caches a verdict on every write, so mid-morning it says
        "2 macros are off" about a day with one meal in it, which is both true
        and useless. SPEC-coaching is explicit that the app is quiet by default
        and never comments on a day in progress; showing it live would make the
        one thing the user was promised they'd be spared.
      */}
      {day.verdict && !isToday && <Verdict verdict={day.verdict} />}

      {shown.map((meal) => (
        <Meal
          key={meal.slot}
          meal={meal}
          date={date}
          prescribed={(day.prescribed || []).find((p) => p.slot === meal.slot) || null}
          /* The accent goes to the NEXT unlogged meal only. A column of six
             identical yellow buttons is not six primary actions, it is none —
             and the next meal in eating order is the one being logged. */
          primary={meal.slot === nextSlot}
          onDay={onDay}
        />
      ))}

      {!showAll && hidden.length > 0 && (
        <button type="button" className="btn btn--quiet" onClick={() => setShowAll(true)}>
          Add another meal ({hidden.length} more slot{hidden.length === 1 ? '' : 's'})
        </button>
      )}
    </section>
  )
}

function MacroLine({ totals, target }) {
  const cell = (label, key, unit = 'g') => (
    <span className="tiny muted num">
      {label} {Math.round(totals[key] || 0)}
      {target ? `/${Math.round(target[key])}` : ''}{unit}
    </span>
  )
  return (
    <div className="row" style={{ gap: 10, justifyContent: 'flex-end' }}>
      {cell('P', 'protein')}
      {cell('F', 'fat')}
      {/* Net carbs. The server derives it; the label says so because "carbs"
          alone is ambiguous to anyone counting them. */}
      {cell('net C', 'carbs')}
    </div>
  )
}

function Verdict({ verdict }) {
  if (verdict.short_but_ok) {
    return (
      <p className="notice small" style={{ margin: 0 }}>
        Macros landed, calories came in short. That counts — noted, not a miss.
      </p>
    )
  }
  if (verdict.on_target) {
    return <p className="small muted" style={{ margin: 0 }}>On target today.</p>
  }
  return (
    <p className="small muted" style={{ margin: 0 }}>
      {verdict.failure_count === 1 ? 'One macro is off' : `${verdict.failure_count} macros are off`} today.
    </p>
  )
}

/* ---- one meal slot ------------------------------------------------------- */

function Meal({ meal, date, prescribed, primary, onDay }) {
  const [adding, setAdding] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const logged = meal.entries.length > 0
  const run = async (fn) => {
    setError(null)
    setBusy(true)
    try {
      const r = await fn()
      // Every mutating route returns the whole day, so state is replaced from
      // the response rather than patched locally — that is what keeps totals
      // and the verdict honest without a second GET.
      if (r?.day) onDay(r.day)
    } catch (e) {
      setError(e.message || 'That did not save.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="card stack-sm">
      <div className="row">
        <h3 className="subheading">{SLOT_LABELS[meal.slot] || meal.slot}</h3>
        {logged || meal.adherence === 'skipped' ? (
          <span className="tag push">{ADHERENCE_LABELS[meal.adherence]}</span>
        ) : (
          <span className="tiny muted push">not logged</span>
        )}
      </div>

      {/* What the coach asked for. Shown even after logging: "as planned" is
          only meaningful next to the plan it followed. */}
      {prescribed && (
        <p className="tiny muted" style={{ margin: 0 }}>
          Planned: {prescribed.name}
          {' · '}
          <span className="num">{Math.round(prescribed.calories)} kcal</span>
          {prescribed.prep_minutes ? ` · ${prescribed.prep_minutes} min prep` : ''}
        </p>
      )}

      {meal.entries.map((e) => (
        <Entry key={e.id} entry={e} busy={busy} run={run} />
      ))}

      {logged && (
        <div className="row">
          <span className="tiny muted">Meal total</span>
          <span className="tiny num push">
            {Math.round(meal.total.calories)} kcal · P {Math.round(meal.total.protein)}
            {' · '}F {Math.round(meal.total.fat)} · net C {Math.round(meal.total.carbs)}
          </span>
        </div>
      )}

      <Delta meal={meal} date={date} busy={busy} run={run} />

      <div className="row" style={{ flexWrap: 'wrap' }}>
        {/*
          The one-tap path, and the reason the plan is worth following.
          Idempotent server-side: tapping twice does not log two dinners.

          Primary only while it is the next thing to do. Once the meal is logged
          this is a state, not an action, so it drops to ghost — DESIGN.md allows
          one yellow element per view, and six yellow buttons down a day of meals
          spends the accent on nothing.
        */}
        {prescribed && (
          <button
            type="button"
            className={primary && meal.adherence !== 'as_planned'
              ? 'btn btn--primary'
              : 'btn btn--ghost'}
            disabled={busy}
            onClick={() => run(() => api.nutrition.asPlanned(date, meal.slot))}
          >
            {meal.adherence === 'as_planned' ? 'Logged as planned' : 'Ate as planned'}
          </button>
        )}
        <button
          type="button"
          className="btn btn--ghost"
          disabled={busy}
          onClick={() => setAdding((v) => !v)}
        >
          {adding ? 'Close' : 'Add food'}
        </button>
        {prescribed && meal.adherence !== 'skipped' && (
          <button
            type="button"
            className="btn btn--quiet"
            disabled={busy}
            onClick={() => run(() => api.nutrition.setAdherence(date, meal.slot, 'skipped'))}
          >
            Skipped it
          </button>
        )}
      </div>

      {error && <p className="error">{error}</p>}

      {adding && (
        <AddFood
          date={date}
          slot={meal.slot}
          onAdded={(dayPayload) => {
            onDay(dayPayload)
            setAdding(false)
          }}
        />
      )}
    </div>
  )
}

function Entry({ entry, busy, run }) {
  return (
    <div className="row entry">
      <span className="small">
        {entry.name}
        {entry.serving_g ? <span className="muted num"> {entry.serving_g}g</span> : null}
      </span>
      <span className="tiny muted num push">{Math.round(entry.calories)} kcal</span>
      <button
        type="button"
        className="btn btn--quiet"
        aria-label={`Remove ${entry.name}`}
        disabled={busy}
        onClick={() => run(() => api.nutrition.deleteEntry(entry.id))}
      >
        ×
      </button>
    </div>
  )
}

/**
 * The manual calorie nudge — "+50 for the oil I cooked in".
 *
 * This was the most-used correction path in the original app, so it is one tap
 * from the meal rather than behind an edit screen. It sends the RUNNING total
 * because the server treats the delta as absolute against itself; sending an
 * increment would double it.
 *
 * Calories only, deliberately. The full four-macro delta exists server-side, but
 * a row of twelve nudge buttons is how you make a fast path slow — and "I used
 * more oil than that" is a calorie correction in practice.
 */
function Delta({ meal, date, busy, run }) {
  const current = Math.round(meal.delta?.calories || 0)
  const nudge = (by) =>
    run(() =>
      api.nutrition.setDelta(date, meal.slot, {
        calories: Math.max(0, current + by),
        // The other three are resent unchanged: the route writes all four
        // columns, so omitting them would zero them.
        protein: meal.delta?.protein || 0,
        fat: meal.delta?.fat || 0,
        carbs: meal.delta?.carbs || 0,
      })
    )

  // Nothing logged and nothing nudged: there is no meal to correct yet.
  if (current === 0 && meal.entries.length === 0) return null

  return (
    <div className="delta">
      {/* Label above the buttons rather than beside them: at 360px "Extra +125
          kcal" and three buttons do not share a line, and the wrap put the unit
          on its own row. */}
      <span className="tiny muted">
        {current > 0
          ? <>Extra <span className="num">+{current} kcal</span> on top</>
          : 'Cooked in oil? Nudge it.'}
      </span>
      <div className="delta-btns">
        <button type="button" className="btn btn--quiet num" disabled={busy || current === 0}
          onClick={() => nudge(-25)}>−25</button>
        <button type="button" className="btn btn--quiet num" disabled={busy}
          onClick={() => nudge(25)}>+25</button>
        <button type="button" className="btn btn--quiet num" disabled={busy}
          onClick={() => nudge(100)}>+100</button>
      </div>
    </div>
  )
}

/* ---- adding a food ------------------------------------------------------- */

/**
 * Search, favorites, or by hand.
 *
 * Search is a paid call and rate limited to 60/hour, so the manual path is a
 * peer here rather than a fallback — someone who knows the numbers should not
 * have to spend a search on them.
 */
function AddFood({ date, slot, onAdded }) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [manual, setManual] = useState(false)

  async function search(e) {
    e.preventDefault()
    if (!query.trim()) return
    setError(null)
    setBusy(true)
    setResults(null)
    try {
      const r = await api.nutrition.search(query.trim())
      setResults(r.foods || [])
    } catch (err) {
      // A 429 says what to do instead, and the manual path is right there.
      setError(err.message || 'Could not look that up. Add it by hand instead.')
    } finally {
      setBusy(false)
    }
  }

  async function add(food) {
    setError(null)
    setBusy(true)
    try {
      const r = await api.nutrition.addEntry(date, slot, {
        name: food.name,
        serving_g: food.serving_g || null,
        calories: food.calories,
        protein: food.protein,
        fat: food.fat,
        // total_carbs + fiber, never a pre-netted `carbs`: the server derives
        // net at intake and would otherwise subtract fiber twice.
        total_carbs: food.total_carbs,
        fiber: food.fiber,
        source: food.source || 'manual',
        source_ref: food.source_ref || null,
      })
      if (r?.day) onAdded(r.day)
    } catch (err) {
      setError(err.message || 'That did not save.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="stack-sm addfood">
      <form onSubmit={search} className="row">
        <input
          className="input"
          placeholder="6oz chicken and a cup of broccoli"
          aria-label="What did you eat?"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />
        <button type="submit" className="btn btn--ghost" disabled={busy || !query.trim()}>
          {busy ? 'Looking…' : 'Search'}
        </button>
      </form>

      {error && <p className="error">{error}</p>}

      {results?.length === 0 && (
        <p className="small muted" style={{ margin: 0 }}>
          Nothing found. Try different wording, or add it by hand.
        </p>
      )}

      {results?.map((f, i) => (
        <button
          key={`${f.name}-${i}`}
          type="button"
          className="result"
          disabled={busy}
          onClick={() => add(f)}
        >
          <span className="small">{f.name}</span>
          <span className="tiny muted num">
            {Math.round(f.calories)} kcal · P {f.protein} · F {f.fat} · C {f.total_carbs}
            {f.fiber ? ` (−${f.fiber} fiber)` : ''}
          </span>
        </button>
      ))}

      <button type="button" className="btn btn--quiet" onClick={() => setManual((v) => !v)}>
        {manual ? 'Never mind' : 'Enter it by hand'}
      </button>

      {manual && <ManualFood busy={busy} onSubmit={add} />}
    </div>
  )
}

function ManualFood({ busy, onSubmit }) {
  const [f, setF] = useState({
    name: '', calories: '', protein: '', fat: '', total_carbs: '', fiber: '',
  })
  const set = (k) => (e) => setF((s) => ({ ...s, [k]: e.target.value }))
  const num = (v) => (v === '' ? 0 : Number(v))

  return (
    <form
      className="stack-sm"
      onSubmit={(e) => {
        e.preventDefault()
        if (!f.name.trim()) return
        onSubmit({
          name: f.name.trim(),
          calories: num(f.calories),
          protein: num(f.protein),
          fat: num(f.fat),
          total_carbs: num(f.total_carbs),
          fiber: num(f.fiber),
          source: 'manual',
        })
      }}
    >
      <input className="input" placeholder="What was it?" aria-label="Food name"
        value={f.name} onChange={set('name')} />
      <div className="macro-grid">
        <NumBox label="kcal" value={f.calories} onChange={set('calories')} />
        <NumBox label="Protein" value={f.protein} onChange={set('protein')} />
        <NumBox label="Fat" value={f.fat} onChange={set('fat')} />
        {/* Total carbs and fiber, not net — the server does the subtraction, and
            asking for net here would mean two places knowing the rule. */}
        <NumBox label="Carbs" value={f.total_carbs} onChange={set('total_carbs')} />
        <NumBox label="Fiber" value={f.fiber} onChange={set('fiber')} />
      </div>
      <button type="submit" className="btn btn--ghost" disabled={busy || !f.name.trim()}>
        Add it
      </button>
    </form>
  )
}

function NumBox({ label, value, onChange }) {
  return (
    <label className="field">
      <span className="tiny muted">{label}</span>
      <input
        className="input num"
        type="number"
        inputMode="decimal"
        min="0"
        step="any"
        value={value}
        onChange={onChange}
      />
    </label>
  )
}
