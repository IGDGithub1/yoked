import { useEffect, useRef, useState } from 'react'
import { api } from '../api'
import { foodPlaceholder } from '../foodExamples'
import Scanner from './Scanner'
import Help from './Help'
import Veto, { VetoOutcome } from './Veto'

/**
 * Food logging for one day.
 *
 * The intake model is Keto Tracker's, carried over because it survived real daily
 * use (SPEC-nutrition.md). Two things about it are load-bearing:
 *
 *   1. Meal total = manual delta + SUM(entries), and the delta is ADDITIVE. The
 *      nudge buttons send the running delta, not an increment, because the server
 *      treats it as absolute against itself — +50 twice is still +50.
 *   2. Net carbs are derived server-side at intake. This sends total_carbs and
 *      fiber and never computes `carbs` itself.
 *
 * The three ways in are FAVORITES, SCAN, and SEARCH, in that order of prominence.
 * That order is the whole point and it is not the order the API suggests:
 *
 *   - Favorites first because eating is repetitive. The same eight breakfasts
 *     cover most mornings, and a one-tap re-log of "my usual" is the difference
 *     between a food log someone keeps and one they abandon in week three.
 *   - Scan second because it is exact and free of typing, and packaged food is
 *     most of what has a barcode worth scanning.
 *   - Search last because it is the slowest, costs a paid model call, and is
 *     rate limited to 60/hour. It is the fallback for genuinely new food, not
 *     the front door.
 *
 * Nothing here judges the day. Totals show against target, but the verdict is the
 * server's — an old client must not re-grade history.
 */

const SLOT_LABELS = {
  breakfast: 'Breakfast',
  lunch: 'Lunch',
  dinner: 'Dinner',
  snack_am: 'Morning snack',
  snack_pm: 'Afternoon snack',
  snack_eve: 'Evening snack',
}

/*
 * The three snack slots are presented as ONE "Snacks" card.
 *
 * They exist in the schema because the plan can prescribe a specific one, and
 * that stays true. But making a user decide whether a 4pm handful of almonds is
 * an "afternoon" or "evening" snack is a question with no right answer and no
 * consequence, and three near-empty cards for it crowds out the meals that
 * matter. So the card holds all three slots' entries together, and adding a
 * snack picks the first free slot.
 *
 * A prescribed snack still shows the time it was planned for, because that IS
 * information the coach put there.
 */
const SNACK_SLOTS = ['snack_am', 'snack_pm', 'snack_eve']

const SNACK_TIME = {
  snack_am: 'morning',
  snack_pm: 'afternoon',
  snack_eve: 'evening',
}

const ADHERENCE_LABELS = {
  as_planned: 'as planned',
  substituted: 'substituted',
  skipped: 'skipped',
  unplanned: 'off-plan',
}

export default function Food({ day, date, isToday, onDay, vetoes = [], onVeto }) {
  /*
   * Favorites are fetched ONCE for the whole section, not per meal card.
   *
   * Six slots each fetching the same list is six requests for one answer, and
   * they would drift after a star — so the list lives here and every card reads
   * the same copy.
   */
  const [favorites, setFavorites] = useState([])
  useEffect(() => {
    let live = true
    api.nutrition.favorites()
      .then((r) => { if (live) setFavorites(r.favorites || []) })
      // A failed favorites fetch must not take the day down with it: search and
      // scan still work, and the section degrades to what it was before.
      .catch(() => {})
    return () => { live = false }
  }, [])

  const prescribedSlots = new Set((day.prescribed || []).map((p) => p.slot))
  const meals = day.meals || []
  const prescribedFor = (slot) => (day.prescribed || []).find((p) => p.slot === slot) || null

  // The three real meals, always shown. Skipping breakfast is a fact about the
  // day, not a slot to hide.
  const mainMeals = meals.filter((m) => !SNACK_SLOTS.includes(m.slot))
  const snacks = meals.filter((m) => SNACK_SLOTS.includes(m.slot))

  // The first prescribed meal with nothing logged, in eating order. The server
  // returns slots in eating order, so array order IS eating order.
  const nextSlot = mainMeals.find(
    (m) => m.entries.length === 0 && m.adherence !== 'skipped' && prescribedSlots.has(m.slot)
  )?.slot

  return (
    <>
      {/*
        The verdict is displayed, never computed here — and only once the day is
        over. The server caches one on every write, so mid-morning it says "2
        macros are off" about a day with one meal in it: true, useless, and
        exactly the noise §4.2 promises to spare people.
      */}
      {day.verdict && !isToday && <Verdict verdict={day.verdict} />}

      {mainMeals.map((meal) => (
        <Meal
          key={meal.slot}
          meal={meal}
          date={date}
          prescribed={prescribedFor(meal.slot)}
          /* The accent goes to the NEXT unlogged meal only. A column of yellow
             buttons is not several primary actions, it is none.

             This used to also yield to an open weekly check-in, which shared the
             screen. It does not any more: review surfaces live on the Dashboard, so
             one prompt per screen now holds by construction rather than by
             negotiation between components. */
          primary={meal.slot === nextSlot}
          favorites={favorites}
          onFavorites={setFavorites}
          onDay={onDay}
          vetoes={vetoes}
          onVeto={onVeto}
        />
      ))}

      <Snacks
        snacks={snacks}
        date={date}
        prescribedFor={prescribedFor}
        favorites={favorites}
        onFavorites={setFavorites}
        onDay={onDay}
      />
    </>
  )
}

/** The section heading's live numbers, shown whether the body is open or shut. */
export function FoodSummary({ day }) {
  const totals = day.totals || {}
  const target = day.target
  return (
    <div style={{ textAlign: 'right' }}>
      <div className="num" style={{ fontWeight: 600 }}>
        {Math.round(totals.calories || 0)}
        {target && <span className="muted"> / {Math.round(target.calories)}</span>}
        <span className="small muted"> kcal</span>
      </div>
      {/* Each macro is one unbreakable unit. As a flex row the label and its
          value landed on separate lines at 360px ("P" above "90/180g"), which
          reads as four mystery numbers. */}
      <div className="macro-line">
        <Macro label="P" v={totals.protein} t={target?.protein} />
        <Macro label="F" v={totals.fat} t={target?.fat} />
        {/* Net carbs — the server derives it. "net C" rather than "C" because
            "carbs" is ambiguous to anyone actually counting them, and the whole
            intake model turns on net vs total. */}
        <Macro label={'net C'} v={totals.carbs} t={target?.carbs} />
      </div>
    </div>
  )
}

function Macro({ label, v, t }) {
  return (
    <span className="tiny muted num macro">
      {/* A non-breaking space: "P 90/180g" must never split after the label. */}
      {label}&nbsp;{Math.round(v || 0)}{t ? `/${Math.round(t)}` : ''}g
    </span>
  )
}

function Verdict({ verdict }) {
  if (verdict.short_but_ok) {
    return (
      <p className="notice small" style={{ margin: 0 }}>
        Macros landed, calories came in short. That still counts.
      </p>
    )
  }
  if (verdict.on_target) {
    return <p className="small muted" style={{ margin: 0 }}>On target.</p>
  }
  return (
    <p className="small muted" style={{ margin: 0 }}>
      {verdict.failure_count === 1 ? 'One macro was off' : `${verdict.failure_count} macros were off`}.
    </p>
  )
}

/* ---- snacks, as one card ------------------------------------------------- */

/**
 * All three snack slots in one card.
 *
 * The slots stay in the data because a plan can prescribe a specific one, but the
 * user is not asked to classify their own snacks: "is a 4pm handful of almonds
 * afternoon or evening" has no right answer and no consequence, and three
 * mostly-empty cards for it pushed the actual meals off the screen.
 *
 * Adding fills the first free slot. That is arbitrary and deliberately invisible
 * — the slot only matters when the COACH chose it, and those are labelled.
 */
function Snacks({ snacks, date, prescribedFor, favorites, onFavorites, onDay }) {
  const [adding, setAdding] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  // Flattened across the three slots, each entry remembering which one it came
  // from so it can still be deleted and starred individually.
  const entries = snacks.flatMap((m) => m.entries.map((e) => ({ ...e, slot: m.slot })))

  const total = snacks.reduce(
    (acc, m) => ({
      calories: acc.calories + (m.total?.calories || 0),
      protein: acc.protein + (m.total?.protein || 0),
      fat: acc.fat + (m.total?.fat || 0),
      carbs: acc.carbs + (m.total?.carbs || 0),
    }),
    { calories: 0, protein: 0, fat: 0, carbs: 0 }
  )

  // Prescribed snacks keep their time, because that is the coach's choice rather
  // than bookkeeping.
  const planned = SNACK_SLOTS
    .map((s) => ({ slot: s, p: prescribedFor(s) }))
    .filter((x) => x.p !== null)

  // First slot with nothing in it. Falls back to the last so a fourth snack still
  // logs rather than being silently refused.
  const freeSlot =
    SNACK_SLOTS.find((s) => !snacks.find((m) => m.slot === s)?.entries.length)
    || 'snack_eve'

  const run = async (fn) => {
    setError(null)
    setBusy(true)
    try {
      const r = await fn()
      if (r?.day) onDay(r.day)
      return r
    } catch (e) {
      setError(e.message || 'That did not save.')
      return null
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="card stack-sm">
      <div className="row">
        <h3 className="subheading">Snacks</h3>
        <span className="tiny muted push num">
          {entries.length === 0
            ? 'none yet'
            : `${entries.length} logged · ${Math.round(total.calories)} kcal`}
        </span>
      </div>

      {planned.map(({ slot, p }) => (
        <p className="tiny muted" key={slot} style={{ margin: 0 }}>
          Planned {SNACK_TIME[slot]}: {p.name}
          {' · '}
          <span className="num">{Math.round(p.calories)} kcal</span>
        </p>
      ))}

      {entries.map((e) => (
        <Entry
          key={e.id}
          entry={e}
          busy={busy}
          run={run}
          favorites={favorites}
          onFavorites={onFavorites}
        />
      ))}

      {entries.length > 1 && (
        <div className="row">
          <span className="tiny muted">All snacks</span>
          <span className="tiny num push">
            {Math.round(total.calories)} kcal · P {Math.round(total.protein)}
            {' · '}F {Math.round(total.fat)} · net C {Math.round(total.carbs)}
          </span>
        </div>
      )}

      {!adding && (
        <div className="row" style={{ flexWrap: 'wrap' }}>
          {planned.length > 0 && (
            <button
              type="button"
              className="btn btn--ghost"
              disabled={busy}
              onClick={() => run(() => api.nutrition.asPlanned(date, planned[0].slot))}
            >
              Ate the planned one
            </button>
          )}
          <button type="button" className="btn btn--ghost" disabled={busy}
            onClick={() => setAdding(true)}>
            Add a snack
          </button>
        </div>
      )}

      {error && <p className="error">{error}</p>}

      {adding && (
        <AddFood
          date={date}
          slot={freeSlot}
          favorites={favorites}
          onFavorites={onFavorites}
          onClose={() => setAdding(false)}
          onAdded={(dayPayload) => {
            onDay(dayPayload)
            setAdding(false)
          }}
        />
      )}
    </div>
  )
}

/* ---- one meal slot ------------------------------------------------------- */

function Meal({ meal, date, prescribed, primary, favorites, onFavorites, onDay,
               vetoes = [], onVeto }) {
  const [adding, setAdding] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const logged = meal.entries.length > 0

  /*
   * Has this exact prescription already been turned down?
   *
   * Matched on the prescribed meal's id rather than the slot, because a slot repeats every
   * day and a prescription does not. Newest first from the API, so the first match is the
   * current state — an old declined veto must not mask a fresh pending one.
   */
  const mealVeto = prescribed
    ? vetoes.find((v) => v.subject_type === 'meal' && v.subject_id === prescribed.id)
    : null

  /*
   * WRITES ARE QUEUED, one at a time, and this is not belt-and-braces.
   *
   * Every mutating route returns the whole day and this replaces state from it, which is
   * what keeps totals and the verdict honest without a second GET. That is only sound
   * while one write is in flight. Two overlapping PUTs to the same meal have no
   * guaranteed commit order, and each response snapshots the day as of ITS OWN commit —
   * so the second response can carry a day that predates the first write.
   *
   * Observed, in both directions: type 120 into a meal's calorie correction and 14 into
   * its fat with no pause. Unguarded, the fat response returned first and the calorie
   * response reverted fat to zero. With a last-write-wins guard, the fat response won and
   * carried calories: 0, because the server had not committed the calories when it built
   * that payload. Same bug, opposite symptom, and both read as "the second field does not
   * save".
   *
   * Chaining removes the race rather than picking a winner. The queue is a promise the
   * next call awaits, so the server sees the writes in the order they were made and every
   * response is built on top of the one before it.
   *
   * Nothing else on this screen could fire two writes close enough together to show this.
   * Four typed fields can.
   */
  const queue = useRef(Promise.resolve())

  const run = (fn) => {
    setError(null)
    setBusy(true)
    const next = queue.current.then(async () => {
      try {
        const r = await fn()
        if (r?.day) onDay(r.day)
        return r
      } catch (e) {
        setError(e.message || 'That did not save.')
        return null
      } finally {
        setBusy(false)
      }
    })
    // The chain must never reject, or one failure wedges every later write. `fn`'s errors
    // are already caught above; this covers anything thrown by onDay.
    queue.current = next.catch(() => {})
    return next
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

      {/* What the coach asked for. Shown even after logging: "as planned" is only
          meaningful next to the plan it followed. */}
      {prescribed && (
        <p className="tiny muted" style={{ margin: 0 }}>
          Planned: {prescribed.name}
          {' · '}
          <span className="num">{Math.round(prescribed.calories)} kcal</span>
          {prescribed.prep_minutes ? ` · ${prescribed.prep_minutes} min prep` : ''}
        </p>
      )}

      {meal.entries.map((e) => (
        <Entry
          key={e.id}
          entry={e}
          busy={busy}
          run={run}
          favorites={favorites}
          onFavorites={onFavorites}
        />
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

      <Delta meal={meal} date={date} prescribed={prescribed} busy={busy} run={run} />

      <div className="row" style={{ flexWrap: 'wrap' }}>
        {/* The one-tap path, and the reason the plan is worth following.
            Idempotent server-side: tapping twice does not log two dinners. */}
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
        {/* Only shown while CLOSED. When the panel is open it has its own way out
            at the bottom — a "Close" button up here reads as a peer of "Ate as
            planned", which is a real action, and the two competed. */}
        {!adding && (
          <button
            type="button"
            className={!prescribed && primary ? 'btn btn--primary' : 'btn btn--ghost'}
            disabled={busy}
            onClick={() => setAdding(true)}
          >
            Add food
          </button>
        )}
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

      {/*
        Turning it down, which is NOT the same as skipping it (§5).
        "Skipped it" is a fact about a meal that has already not happened. This is a
        request about one that has not happened yet, and the coach can decline it. Keeping
        them apart matters: collapsing the two would either turn every skip into a
        conversation or make a genuine "I cannot cook tonight" indistinguishable from
        having forgotten to eat.

        Only while there is still something to turn down: once it is logged or skipped, the
        question has answered itself.
      */}
      {prescribed && !logged && meal.adherence !== 'skipped' && (
        mealVeto
          ? <VetoOutcome veto={mealVeto} />
          : (
            <Veto
              subjectType="meal"
              subjectId={prescribed.id}
              label={(SLOT_LABELS[meal.slot] || meal.slot).toLowerCase()}
              onDone={onVeto}
            />
          )
      )}

      {adding && (
        <AddFood
          date={date}
          slot={meal.slot}
          favorites={favorites}
          onFavorites={onFavorites}
          onClose={() => setAdding(false)}
          onAdded={(dayPayload) => {
            onDay(dayPayload)
            setAdding(false)
          }}
        />
      )}
    </div>
  )
}

/**
 * A logged food, with the star that makes it reusable.
 *
 * Starring lives HERE, on the thing you just ate, rather than in a favorites
 * manager. That is the moment you know it is a keeper, and it is why the original
 * accumulated a useful list without anyone curating one.
 */
function Entry({ entry, busy, run, favorites, onFavorites }) {
  const [starring, setStarring] = useState(false)
  const [editing, setEditing] = useState(false)

  // Case-insensitive, matching the server's uk_fav_user_name collation — so the
  // star reflects what a POST would actually do rather than disagreeing with it.
  const fav = favorites.find(
    (f) => f.name.toLowerCase() === String(entry.name || '').toLowerCase()
  )

  async function toggleStar() {
    setStarring(true)
    try {
      if (fav) {
        const r = await api.nutrition.deleteFavorite(fav.id)
        onFavorites(r.favorites || favorites.filter((f) => f.id !== fav.id))
      } else {
        const r = await api.nutrition.addFavorite({
          name: entry.name,
          serving_g: entry.serving_g,
          calories: entry.calories,
          protein: entry.protein,
          fat: entry.fat,
          /*
           * The entry's carb figures go across intact (008), which before this
           * was impossible: favorite_foods had no fiber column, so starring a
           * food threw its fiber away for good.
           *
           * The two shapes must not be mixed. An entry logged from a PRESCRIBED
           * meal has net carbs and a fiber figure but NO total (prescribed carbs
           * are already net), so sending net-as-total alongside the fiber makes
           * the server subtract it a second time: a 50g net breakfast came back
           * as 44g. Only send the pair when a real total exists; otherwise net is
           * already the answer and travels alone.
           */
          ...(entry.total_carbs != null
            ? { total_carbs: entry.total_carbs, fiber: entry.fiber }
            : { carbs: entry.carbs, fiber: entry.fiber }),
        })
        onFavorites(r.favorites || favorites)
      }
    } catch {
      // A failed star is not worth an error banner on a logged meal. The state
      // simply does not change, and the tap can be repeated.
    } finally {
      setStarring(false)
    }
  }

  return (
    <div className="stack-tight entry-wrap">
      <div className="row entry">
        <span className="small">
          {entry.name}
          {/*
            The serving is a BUTTON when there is one to change, and plain text when there
            is not. An entry logged from a prescribed meal has no serving recorded, and an
            amount control with nothing to scale from would either do nothing or invent a
            baseline. Offering it only where the maths is possible is what keeps rescaling
            all-or-nothing from the user's side.
          */}
          {entry.serving_g ? (
            <button
              type="button"
              className="servingbtn num"
              aria-expanded={editing}
              aria-label={`Change the amount of ${entry.name}, currently ${entry.serving_g} grams`}
              onClick={() => setEditing((v) => !v)}
            >
              {entry.serving_g}g
            </button>
          ) : null}
        </span>
        <span className="tiny muted num push">{Math.round(entry.calories)} kcal</span>
        <button
          type="button"
          className="star"
          aria-pressed={!!fav}
          aria-label={fav ? `Remove ${entry.name} from favorites` : `Save ${entry.name} to favorites`}
          disabled={starring}
          onClick={toggleStar}
        >
          {fav ? '★' : '☆'}
        </button>
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

      {editing && (
        <Amount
          entry={entry}
          busy={busy}
          run={run}
          onDone={() => setEditing(false)}
        />
      )}
    </div>
  )
}

/**
 * Correcting how much of something was actually eaten.
 *
 * The server rescales every macro from the ratio, so this sends ONE number. That is the
 * whole reason it can be a single field rather than a form: nobody knows what 5g of yellow
 * mustard is in grams of fat, and asking is how the numbers stay wrong.
 *
 * The preview is computed here purely to show what will happen before it happens. It is
 * never sent — the server does its own maths from the stored row, so a rounding difference
 * between the two shows up as a slightly different figure after saving, not as a wrong
 * figure saved.
 */
function Amount({ entry, busy, run, onDone }) {
  const [grams, setGrams] = useState(String(entry.serving_g ?? ''))

  const next = Number(grams)
  const valid = Number.isFinite(next) && next > 0 && next <= 5000
  const scale = valid && entry.serving_g > 0 ? next / entry.serving_g : 1
  const changed = valid && next !== entry.serving_g

  return (
    <div className="amount">
      <label className="tiny muted" htmlFor={`amt-${entry.id}`}>
        How much did you actually eat?
      </label>
      <div className="row" style={{ gap: 6, flexWrap: 'wrap' }}>
        <input
          id={`amt-${entry.id}`}
          className="input num"
          type="number"
          inputMode="decimal"
          min="1"
          max="5000"
          step="1"
          style={{ maxWidth: '6.5em' }}
          value={grams}
          disabled={busy}
          onChange={(e) => setGrams(e.target.value)}
        />
        <span className="tiny muted">grams</span>

        {/* What it becomes. Shown before saving, because the point of scaling is that the
            other three numbers move and you should see that they will. */}
        {changed && (
          <span className="tiny muted num push">
            → {Math.round(entry.calories * scale)} kcal · P{' '}
            {Math.round(entry.protein * scale * 10) / 10} · F{' '}
            {Math.round(entry.fat * scale * 10) / 10} · net C{' '}
            {Math.round(entry.carbs * scale * 10) / 10}
          </span>
        )}
      </div>
      <div className="row" style={{ gap: 6 }}>
        <button
          type="button"
          className="btn btn--primary btn--small"
          disabled={busy || !changed}
          /* Closed only on success. `run` swallows the error into the meal's banner and
             resolves null, so an unconditional close would hide the failure behind a panel
             that looks like it saved. */
          onClick={() =>
            run(() => api.nutrition.updateEntry(entry.id, { serving_g: next }))
              .then((r) => { if (r) onDone() })
          }
        >
          Save
        </button>
        <button type="button" className="btn btn--quiet btn--small" onClick={onDone}>
          Cancel
        </button>
      </div>
    </div>
  )
}

const DELTA_FIELDS = [
  { key: 'calories', label: 'kcal', step: 5 },
  { key: 'protein', label: 'P', step: 1 },
  { key: 'fat', label: 'F', step: 1 },
  { key: 'carbs', label: 'net C', step: 1 },
]

/**
 * What went into the meal that no entry accounts for — the oil in the pan.
 *
 * ONLY WHERE THERE IS A PLAN. This used to render on any meal with entries, which meant
 * a user in their baseline fortnight — with no prescribed meal, by definition — was
 * offered "Cooked in oil? Nudge it." against a meal nothing had been proposed for. The
 * correction is meaningful against a PRESCRIPTION: the plan said 600 kcal, and the pan
 * says otherwise. With no plan, a food you did not log is a food to log, not a delta.
 *
 * It is additive and survives adding or removing entries, which is the whole reason it
 * exists rather than being folded into the totals. It is sent as an ABSOLUTE value
 * because the server treats it as absolute against itself.
 *
 * FOUR FIELDS, NOT THREE BUTTONS. The buttons could only say "+25 kcal", and a
 * tablespoon of oil is 120 kcal AND 14g of fat. Sending the calories alone left the
 * macro split wrong in a way nothing downstream could detect, because the number it
 * needed was never asked for. Typed fields are slower than a tap and they are the only
 * shape that can express the thing.
 *
 * Negative is allowed. The columns are signed and always were; the old buttons clamped
 * at zero, which made "I ate less of it than planned" inexpressible.
 */
function Delta({ meal, date, prescribed, busy, run }) {
  const d = meal.delta || {}
  const [open, setOpen] = useState(false)

  /*
   * THE DELTA AS SENT, not as rendered. This is the whole bug, and it took four wrong
   * diagnoses to find because every layer looked innocent on its own.
   *
   * The route writes all four columns, so every save must resend the other three. Reading
   * them from `meal.delta` sends whatever React last rendered — and when two fields are
   * committed in quick succession there is NO RENDER BETWEEN THEM. Blur calories, blur
   * fat, and fat's write resends `calories: 0` because the calorie response has not come
   * back and been rendered yet. The server is then correctly told to zero it.
   *
   * Observed payloads, in order:
   *     PUT {"calories":120,"fat":0}     correct
   *     PUT {"calories":0,"fat":14}      calories reverted by the sender
   *
   * A ref assigned during render does not help: there is no render to assign in. So the
   * ref is updated AT WRITE TIME instead, making it the authoritative record of what has
   * been sent rather than a mirror of what has been painted. It re-syncs from the server
   * whenever a response arrives, so an edit made on another device is not clobbered.
   */
  const sent = useRef(null)
  const known = sent.current ?? d
  useEffect(() => { sent.current = null }, [d.calories, d.protein, d.fat, d.carbs])

  const any = DELTA_FIELDS.some((f) => Math.abs(Number(known[f.key]) || 0) > 0.05)

  // No plan, no prescription to correct against.
  if (!prescribed) return null
  // Nothing logged and nothing adjusted: there is no meal to correct yet.
  if (!any && meal.entries.length === 0) return null

  const save = (key, value) => {
    // Built and recorded BEFORE the request goes out, so a second field committed in the
    // same tick resends this value rather than the last-rendered one.
    const body = {
      calories: Number(known.calories) || 0,
      protein: Number(known.protein) || 0,
      fat: Number(known.fat) || 0,
      carbs: Number(known.carbs) || 0,
      [key]: value,
    }
    sent.current = body
    return run(() => api.nutrition.setDelta(date, meal.slot, body))
  }

  if (!open && !any) {
    return (
      <button type="button" className="deltalink" disabled={busy} onClick={() => setOpen(true)}>
        Cooked it in something?
      </button>
    )
  }

  return (
    <div className="delta">
      <div className="row">
        <span className="tiny muted">On top of what is logged</span>
        {any && (
          <button
            type="button"
            className="btn btn--quiet btn--small push"
            disabled={busy}
            onClick={() => {
              // Through `sent` like any other write, so a field edited straight after a
              // clear resends zeroes rather than what was there before it.
              const zero = { calories: 0, protein: 0, fat: 0, carbs: 0 }
              sent.current = zero
              return run(() => api.nutrition.setDelta(date, meal.slot, zero))
                .then(() => setOpen(false))
            }}
          >
            Clear
          </button>
        )}
      </div>
      <div className="delta-grid">
        {DELTA_FIELDS.map((f) => (
          <DeltaField
            key={f.key}
            field={f}
            value={Number(known[f.key]) || 0}
            slot={meal.slot}
            onSave={(v) => save(f.key, v)}
          />
        ))}
      </div>
    </div>
  )
}

/**
 * One of the four.
 *
 * Held locally while it is being typed and committed on blur, because a write per
 * keystroke would be four requests to type "120" — and each response replaces the whole
 * day, so the field would fight the value under the cursor.
 *
 * NOT DISABLED WHILE A SIBLING SAVES, which is the opposite of every other control on
 * this card. `busy` is shared across the meal, so disabling here meant: type into
 * calories, tab to fat, and fat is inert until the calorie write returns — during which
 * the browser drops focus from the disabled field and the keystrokes go nowhere. What
 * you typed vanished with no error, because nothing had failed.
 *
 * Leaving them live is safe: the value is local until blur, and `latest` on the parent
 * reads the delta at write time rather than at render time, so two saves in flight
 * cannot revert each other.
 */
function DeltaField({ field, value, slot, onSave }) {
  const [local, setLocal] = useState(null)
  const shown = local ?? (value === 0 ? '' : String(value))
  const id = `d-${slot}-${field.key}`

  const commit = () => {
    const next = local === null || local.trim() === '' ? 0 : Number(local)
    setLocal(null)
    if (Number.isFinite(next) && Math.abs(next - value) > 0.05) onSave(next)
  }

  return (
    <label className="deltafield" htmlFor={id}>
      <span className="tiny muted">{field.label}</span>
      <input
        id={id}
        className="input num"
        type="number"
        inputMode="decimal"
        step={field.step}
        placeholder="0"
        value={shown}
        onChange={(e) => setLocal(e.target.value)}
        onBlur={commit}
        onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur() }}
      />
    </label>
  )
}

/* ---- adding a food ------------------------------------------------------- */

const WAYS = [
  { key: 'favorites', label: 'Usual' },
  { key: 'scan', label: 'Scan' },
  { key: 'search', label: 'Search' },
  { key: 'manual', label: 'By hand' },
]

/**
 * Favorites, scan, search, or by hand.
 *
 * Opens on FAVORITES when there are any. Eating is repetitive, and the list of
 * things you have eaten before is the highest-yield thing to show first; search
 * is a paid call and the slowest of the four.
 */
function AddFood({ date, slot, favorites, onFavorites, onClose, onAdded }) {
  const [way, setWay] = useState(favorites.length > 0 ? 'favorites' : 'search')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  /**
   * The one place an entry is written, whichever way found the food.
   *
   * Every source now speaks the same shape: total_carbs + fiber, with the server
   * deriving net at intake so that rule lives in exactly one place. Favorites
   * used to be the exception — they stored net only, so they needed a special
   * case here and lost their fiber on the way in. Migration 008 gave them the
   * same three columns as an entry, which is what removed the exception.
   *
   * The `?? food.carbs` fallback covers favorites saved before 008, where net is
   * genuinely all that was recorded.
   */
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
        total_carbs: food.total_carbs ?? food.carbs,
        fiber: food.fiber,
        source: food.source || 'manual',
        source_ref: food.source_ref || null,
        favorite_id: food.favorite_id || null,
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
      <div className="chips" role="tablist" aria-label="How to add food">
        {WAYS.map((w) => (
          <button
            key={w.key}
            type="button"
            role="tab"
            aria-selected={way === w.key}
            className="chip"
            onClick={() => setWay(w.key)}
          >
            {w.label}
            {w.key === 'favorites' && favorites.length > 0 && (
              <span className="tiny muted num"> {favorites.length}</span>
            )}
          </button>
        ))}
      </div>

      {error && <p className="error">{error}</p>}

      {way === 'favorites' && (
        <Favorites
          favorites={favorites}
          busy={busy}
          onPick={(f) => add({ ...f, source: 'favorite', favorite_id: f.id })}
          onRemove={async (id) => {
            const r = await api.nutrition.deleteFavorite(id)
            onFavorites(r.favorites || favorites.filter((f) => f.id !== id))
          }}
        />
      )}

      {way === 'scan' && (
        <BarcodeLookup busy={busy} onAdd={(f) => add(f)} onDone={() => setWay('search')} />
      )}

      {way === 'search' && (
        <Search date={date} slot={slot} busy={busy} onAdd={(f) => add(f)} />
      )}

      {way === 'manual' && <ManualFood busy={busy} onSubmit={(f) => add(f)} />}

      {/* The way out lives at the BOTTOM of the panel, quietly. Adding food
          closes it anyway, so this is only for changing your mind — which is not
          an action worth a button the same weight as "Ate as planned". */}
      <button type="button" className="btn btn--quiet" onClick={onClose}>
        Never mind
      </button>
    </div>
  )
}

/**
 * A found food, and how much of it.
 *
 * ONE COMPONENT FOR ALL THREE WAYS IN — search, scan, usuals — because they were three
 * copies of the same button and the amount control would have been a fourth. Scan already
 * printed the serving and the others did not, which was the only difference between them
 * and not a deliberate one.
 *
 * THE AMOUNT IS ASKED BEFORE THE FOOD IS LOGGED, not corrected afterwards. Search returns
 * a standard serving and a scan returns whatever the packet declares; neither is what you
 * ate. Fixing it after the fact works (an entry can be rescaled) but it means the wrong
 * number is in the record between the two taps, and it makes the common case two steps.
 *
 * Tapping the row still logs the standard serving in one tap. The amount is a second,
 * quieter control for when that figure is wrong — which is most of the time for anything
 * measured in condiments and almost never for a scanned bar.
 */
function Result({ food, busy, onAdd, netCarbs = false }) {
  const [open, setOpen] = useState(false)
  const [grams, setGrams] = useState('')

  const base = Number(food.serving_g) || 0
  const next = Number(grams)
  const valid = Number.isFinite(next) && next > 0 && next <= 5000
  const scale = valid && base > 0 ? next / base : 1
  const changed = valid && next !== base

  /*
   * Scaled here rather than server-side, unlike an entry correction.
   *
   * An entry already exists on the server, so rescaling it is a PATCH against a stored
   * row. Nothing exists yet at this point — the food is a search result — so there is no
   * row to scale and the numbers go in already right. Both paths end up applying the same
   * ratio to the same fields; this one just has nowhere to send it first.
   */
  const scaled = () => {
    if (!changed) return food
    const s = (v) => (v === null || v === undefined ? v : v * scale)
    /*
     * Every macro scales by the same ratio, and which CARB SHAPE this food is does not
     * change — only the magnitude. A search result carries total_carbs and fiber; a
     * favorite carries net with no total. Scaling both members of whichever pair is
     * present keeps the relationship intact, so intake derives net from the scaled figures
     * exactly as it would have from the originals.
     *
     * Deliberately NOT reshaping anything here. Mixing the two is the double-netting trap
     * that turned a 50g net breakfast into 44g, and the place that decides which shape
     * goes to the server is AddFood.add(); a second opinion in this function would be a
     * second place to get it wrong.
     */
    return {
      ...food,
      serving_g: next,
      calories: s(food.calories),
      protein: s(food.protein),
      fat: s(food.fat),
      carbs: s(food.carbs),
      total_carbs: s(food.total_carbs),
      fiber: s(food.fiber),
    }
  }

  const carbLabel = netCarbs ? 'net C' : 'C'
  const carbValue = netCarbs ? food.carbs : food.total_carbs
  const shownScale = changed ? scale : 1
  const r1 = (v) => Math.round((Number(v) || 0) * shownScale * 10) / 10

  return (
    /* Its own class, not a bare .stack-tight. That layout class is on the logged-entry
       wrapper too, so using it here made "the row for this food" ambiguous to anything
       selecting by it — including the tests. */
    <div className="resultrow stack-tight">
      <button
        type="button"
        className="result"
        disabled={busy}
        onClick={() => onAdd(scaled())}
      >
        <span className="small">{food.name}</span>
        <span className="tiny muted num">
          {Math.round((Number(food.calories) || 0) * shownScale)} kcal · P {r1(food.protein)}
          {' · '}F {r1(food.fat)} · {carbLabel} {r1(carbValue)}
          {/* Only when it is known. A favorite saved before 008 has no fiber figure, and
              printing "0g fiber" would assert something nobody recorded. */}
          {food.fiber ? ` (−${r1(food.fiber)} fiber)` : ''}
          {base ? ` · ${changed ? next : base}g` : ''}
        </span>
      </button>

      {/*
        Offered only where there is a serving to scale FROM. A result with no serving
        recorded has no ratio, and the same all-or-nothing rule applies here as on a
        logged entry: the control appears where the maths is possible and nowhere else.
      */}
      {base > 0 && !open && (
        <button type="button" className="amountlink" onClick={() => setOpen(true)}>
          Had a different amount?
        </button>
      )}

      {base > 0 && open && (
        <div className="amount">
          <div className="row" style={{ gap: 6, flexWrap: 'wrap' }}>
            <input
              className="input num"
              type="number"
              inputMode="decimal"
              min="1"
              max="5000"
              step="1"
              style={{ maxWidth: '6.5em' }}
              placeholder={String(base)}
              aria-label={`Grams of ${food.name}`}
              value={grams}
              onChange={(e) => setGrams(e.target.value)}
            />
            <span className="tiny muted">grams instead of {base}g</span>
          </div>
          {/* No Save. The row above already reads the scaled figures, so tapping it is the
              save — a second confirm button would make the fast path two taps. */}
          <span className="tiny muted">
            {changed ? 'Tap the food to log it at that amount.' : 'Enter what you ate.'}
          </span>
        </div>
      )}
    </div>
  )
}

function Favorites({ favorites, busy, onPick, onRemove }) {
  const [q, setQ] = useState('')

  if (favorites.length === 0) {
    return (
      <p className="small muted" style={{ margin: 0 }}>
        No usuals yet. Star anything you log and it will show up here.
      </p>
    )
  }

  const shown = q.trim()
    ? favorites.filter((f) => f.name.toLowerCase().includes(q.trim().toLowerCase()))
    : favorites

  return (
    <div className="stack-sm">
      {/* A filter, not a search: this is local and instant. Only worth the space
          once the list is long enough to scroll. */}
      {favorites.length > 6 && (
        <input
          className="input"
          placeholder="Filter your usuals"
          aria-label="Filter favorites"
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
      )}
      {shown.map((f) => (
        <div className="row favrow" key={f.id}>
          {/* A favorite stores NET carbs, not a total: it was saved from a figure that had
              already been through intake. Passing netCarbs says so, or the row would label
              a net number "C" and read 12g low against the same food from search. */}
          <Result food={f} busy={busy} onAdd={onPick} netCarbs />
          <button
            type="button"
            className="btn btn--quiet"
            aria-label={`Remove ${f.name} from favorites`}
            disabled={busy}
            onClick={() => onRemove(f.id)}
          >
            ×
          </button>
        </div>
      ))}
      {shown.length === 0 && (
        <p className="tiny muted" style={{ margin: 0 }}>Nothing matches that.</p>
      )}
    </div>
  )
}

/**
 * Scan a barcode, then confirm what came back.
 *
 * The result is shown for confirmation rather than logged straight away, because
 * the lookup has an AI fallback for UPCs that Open Food Facts does not know —
 * that answer is a guess, and a guess the user can see and correct beats a wrong
 * number logged silently.
 */
function BarcodeLookup({ busy, onAdd, onDone }) {
  const [scanning, setScanning] = useState(true)
  const [found, setFound] = useState(null)
  const [meta, setMeta] = useState({})
  const [error, setError] = useState(null)
  const [looking, setLooking] = useState(false)

  async function lookup(code) {
    setScanning(false)
    setError(null)
    setLooking(true)
    try {
      const r = await api.nutrition.barcode(code)
      setFound(r.foods || [])
      setMeta({ cached: r.cached, guessed: r.guessed, upc: code })
    } catch (e) {
      setError(e.message || 'That barcode is not in the database.')
    } finally {
      setLooking(false)
    }
  }

  if (scanning) {
    return <Scanner onDetected={lookup} onClose={onDone} />
  }

  return (
    <div className="stack-sm">
      {looking && <p className="small muted" style={{ margin: 0 }}>Looking it up…</p>}

      {error && (
        <>
          <p className="error">{error}</p>
          <p className="error-help">
            Add it by hand once and a scan will find it next time.
          </p>
        </>
      )}

      {/* An AI guess is labelled as one. The numbers are editable by hand
          afterwards, and saying where they came from is what makes that
          worthwhile rather than mysterious. */}
      {meta.guessed && (
        <p className="notice small" style={{ margin: 0 }}>
          Not in the barcode database, so these numbers are a best guess. Check
          them before logging.
        </p>
      )}

      {/* A scanned bar declares its serving, and it is the one result where the declared
          figure is usually right — so the amount control matters least here and is offered
          anyway, because "half the packet" is a real thing people do. */}
      {found?.map((f, i) => (
        <Result key={`${f.name}-${i}`} food={f} busy={busy} onAdd={onAdd} />
      ))}

      <button type="button" className="btn btn--quiet" onClick={() => setScanning(true)}>
        Scan another
      </button>
    </div>
  )
}

function Search({ date, slot, busy, onAdd }) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState(null)
  const [searching, setSearching] = useState(false)
  const [error, setError] = useState(null)

  async function search(e) {
    e.preventDefault()
    if (!query.trim()) return
    setError(null)
    setSearching(true)
    setResults(null)
    try {
      const r = await api.nutrition.search(query.trim())
      setResults(r.foods || [])
    } catch (err) {
      // A 429 says what to do instead, and the by-hand path is one tap away.
      setError(err.message || 'Could not look that up. Add it by hand instead.')
    } finally {
      setSearching(false)
    }
  }

  return (
    <div className="stack-sm">
      <form onSubmit={search} className="row">
        <input
          className="input"
          /* Matches the MEAL and varies by day — nobody eats "6oz chicken and a
             cup of broccoli" for breakfast, and a placeholder that does not fit
             the slot is the tell that an app was assembled rather than written.
             It also teaches the syntax: quantities and several foods at once. */
          placeholder={foodPlaceholder(slot, date)}
          aria-label="What did you eat?"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
        />
        <button type="submit" className="btn btn--ghost" disabled={searching || !query.trim()}>
          {searching ? 'Looking…' : 'Search'}
        </button>
      </form>

      {error && <p className="error">{error}</p>}

      {results?.length === 0 && (
        <p className="small muted" style={{ margin: 0 }}>
          Nothing found. Try different wording, or add it by hand.
        </p>
      )}

      {/* The path the amount control exists for. A search for "yellow mustard" returns
          100g, because that is what a nutrition database calls a serving, and nobody has
          ever eaten 100g of yellow mustard. */}
      {results?.map((f, i) => (
        <Result key={`${f.name}-${i}`} food={f} busy={busy} onAdd={onAdd} />
      ))}
    </div>
  )
}

function ManualFood({ busy, onSubmit }) {
  const [f, setF] = useState({
    name: '', serving_g: '', calories: '', protein: '', fat: '', total_carbs: '', fiber: '',
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
          serving_g: f.serving_g === '' ? null : Number(f.serving_g),
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
            asking for net here would mean two places knowing the rule. The help
            is on Carbs because "which number off the packet" is the actual
            question people get wrong. */}
        <NumBox
          label="Carbs"
          value={f.total_carbs}
          onChange={set('total_carbs')}
          help={(
            <Help label="Carbs">
              The total carbs off the label, before taking fiber off. Put the
              fiber in the next box and your net carbs are worked out for you.
            </Help>
          )}
        />
        <NumBox label="Fiber" value={f.fiber} onChange={set('fiber')} />
        <NumBox label="Grams" value={f.serving_g} onChange={set('serving_g')} />
      </div>
      <button type="submit" className="btn btn--ghost" disabled={busy || !f.name.trim()}>
        Add it
      </button>
    </form>
  )
}

function NumBox({ label, value, onChange, help }) {
  const input = (
    <input
      className="input num"
      type="number"
      inputMode="decimal"
      min="0"
      step="any"
      value={value}
      onChange={onChange}
    />
  )

  // With help, this cannot be a <label>: clicking the "?" inside one focuses the
  // input instead of opening the explanation.
  if (help) {
    const id = `mf-${label.toLowerCase()}`
    return (
      <div className="field">
        <span className="tiny muted" id={id}>{label}{help}</span>
        {/* aria-labelledby rather than a wrapping label, same reason. */}
        <input {...input.props} aria-labelledby={id} />
      </div>
    )
  }

  return (
    <label className="field">
      <span className="tiny muted">{label}</span>
      {input}
    </label>
  )
}
