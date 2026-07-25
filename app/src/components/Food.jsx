import { useEffect, useState } from 'react'
import { api } from '../api'
import { foodPlaceholder } from '../foodExamples'
import Scanner from './Scanner'
import Help from './Help'

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

export default function Food({ day, date, isToday, onDay }) {
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
             buttons is not several primary actions, it is none. */
          primary={meal.slot === nextSlot}
          favorites={favorites}
          onFavorites={setFavorites}
          onDay={onDay}
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

function Meal({ meal, date, prescribed, primary, favorites, onFavorites, onDay }) {
  const [adding, setAdding] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)

  const logged = meal.entries.length > 0
  const run = async (fn) => {
    setError(null)
    setBusy(true)
    try {
      const r = await fn()
      // Every mutating route returns the whole day, so state is replaced from the
      // response rather than patched locally — that keeps totals and the verdict
      // honest without a second GET.
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

      <Delta meal={meal} date={date} busy={busy} run={run} />

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
    <div className="row entry">
      <span className="small">
        {entry.name}
        {entry.serving_g ? <span className="muted num"> {entry.serving_g}g</span> : null}
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
  )
}

/**
 * The manual calorie nudge — "+50 for the oil I cooked in".
 *
 * The most-used correction path in the original, so it is one tap from the meal
 * rather than behind an edit screen. It sends the RUNNING total because the
 * server treats the delta as absolute against itself; an increment would double
 * it.
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
          kcal" and three buttons do not share a line. */}
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
        <div className="row" key={f.id}>
          <button
            type="button"
            className="result"
            disabled={busy}
            onClick={() => onPick(f)}
          >
            <span className="small">{f.name}</span>
            <span className="tiny muted num">
              {Math.round(f.calories)} kcal · P {f.protein} · F {f.fat} · net C {f.carbs}
              {/* Shown when it is known. A favorite saved before 008 has no
                  fiber figure, and printing "0g fiber" would assert something
                  nobody recorded. */}
              {f.fiber ? ` (−${f.fiber} fiber)` : ''}
              {f.serving_g ? ` · ${f.serving_g}g` : ''}
            </span>
          </button>
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

      {found?.map((f, i) => (
        <button
          key={`${f.name}-${i}`}
          type="button"
          className="result"
          disabled={busy}
          onClick={() => onAdd(f)}
        >
          <span className="small">{f.name}</span>
          <span className="tiny muted num">
            {Math.round(f.calories)} kcal · P {f.protein} · F {f.fat} · C {f.total_carbs}
            {f.fiber ? ` (−${f.fiber} fiber)` : ''}
            {f.serving_g ? ` · ${f.serving_g}g` : ''}
          </span>
        </button>
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

      {results?.map((f, i) => (
        <button
          key={`${f.name}-${i}`}
          type="button"
          className="result"
          disabled={busy}
          onClick={() => onAdd(f)}
        >
          <span className="small">{f.name}</span>
          <span className="tiny muted num">
            {Math.round(f.calories)} kcal · P {f.protein} · F {f.fat} · C {f.total_carbs}
            {f.fiber ? ` (−${f.fiber} fiber)` : ''}
          </span>
        </button>
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
