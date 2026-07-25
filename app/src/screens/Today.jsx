import { useCallback, useEffect, useState } from 'react'
import { api, today as todayDate, shiftDate } from '../api'
import CheckIn from '../components/CheckIn'
import Food from '../components/Food'
import Training from '../components/Training'
import Yolk from '../components/Yolk'

/**
 * The logging screen — one day, scrolling: check-in, food, training.
 *
 * One screen rather than three tabs. Logging is one activity done at a few
 * moments in a day, and the three parts inform each other: a hard session is
 * why the extra food, and low energy is why the short session. Splitting them
 * would hide that and add a navigation layer this app does not otherwise have.
 *
 * Two requests per day, not seven: nutrition and training days are separate
 * endpoints because they are separate concerns server-side, but they are always
 * wanted together, so they are fetched in parallel and rendered as one thing.
 */
export default function Today({ user, onSignOut, onReview }) {
  const [date, setDate] = useState(todayDate)
  const [nutrition, setNutrition] = useState(null)
  const [training, setTraining] = useState(null)
  const [status, setStatus] = useState('loading')
  const [error, setError] = useState(null)

  const load = useCallback(async (d) => {
    setStatus('loading')
    setError(null)
    try {
      // In parallel: two sequential round trips on a phone is a visible wait for
      // no reason — neither response depends on the other.
      const [n, t] = await Promise.all([api.nutrition.day(d), api.training.day(d)])
      setNutrition(n)
      setTraining(t)
      setStatus('ready')
    } catch (e) {
      setError(e.message || 'Could not load that day.')
      setStatus('error')
    }
  }, [])

  useEffect(() => { load(date) }, [date, load])

  /**
   * Mutating routes return the whole day, so writes replace state from the
   * response instead of re-fetching.
   *
   * The nutrition payload also carries the check-in and the session counts, so a
   * training write has to update BOTH — logging a session changes
   * sessions_completed on the day that the food section reads. Refetching the
   * nutrition day is one request against a stale count that the user would
   * otherwise see until they stepped away and back.
   */
  const onNutritionDay = (day) => setNutrition(day)
  const onTrainingDay = (day) => {
    setTraining(day)
    api.nutrition.day(date).then(setNutrition).catch(() => {})
  }

  async function saveCheckIn(fields) {
    const r = await api.checkin(date, fields)
    if (r?.day) setNutrition(r.day)
  }

  const isToday = date === todayDate()

  return (
    <>
      <header className="appbar">
        <Yolk pct={100} size={34} />
        <span className="brand">Yoked</span>
        <button type="button" className="btn btn--quiet push" onClick={onReview}>
          Your answers
        </button>
        <button type="button" className="btn btn--quiet" onClick={onSignOut}>
          Sign out
        </button>
      </header>

      <div className="wrap stack-lg">
        <div className="row daynav">
          <button
            type="button"
            className="btn btn--ghost"
            aria-label="Previous day"
            onClick={() => setDate((d) => shiftDate(d, -1))}
          >
            ‹
          </button>
          <div style={{ textAlign: 'center', flex: '1 1 auto' }}>
            <p className="eyebrow">{isToday ? 'Today' : relativeLabel(date)}</p>
            <h1 className="heading">{prettyDate(date)}</h1>
          </div>
          {/* Forward is disabled on today: logging tomorrow's food is not a
              thing, and the Next Day Review (§4.1a) is a different screen with
              a different job. */}
          <button
            type="button"
            className="btn btn--ghost"
            aria-label="Next day"
            disabled={isToday}
            onClick={() => setDate((d) => shiftDate(d, 1))}
          >
            ›
          </button>
        </div>

        {!isToday && (
          <button type="button" className="btn btn--quiet" onClick={() => setDate(todayDate())}>
            Back to today
          </button>
        )}

        {status === 'loading' && (
          <div style={{ textAlign: 'center', padding: '32px 0' }}>
            <Yolk pct={45} size={40} label="Loading" />
          </div>
        )}

        {status === 'error' && (
          <div className="card stack">
            <p className="error">{error}</p>
            <p className="error-help">
              Nothing you logged is lost — it is saved as you go.
            </p>
            <button className="btn btn--primary" onClick={() => load(date)}>Try again</button>
          </div>
        )}

        {status === 'ready' && nutrition && training && (
          <>
            <CheckIn checkin={nutrition.checkin} onSave={saveCheckIn} />
            <Food day={nutrition} date={date} isToday={isToday} onDay={onNutritionDay} />
            <Training day={training} date={date} onDay={onTrainingDay} />

            {/* Baseline week 1 is pure observation and has no prescription, so
                the absence of targets is the design rather than a gap. Saying so
                stops it reading as a broken screen. */}
            {!nutrition.target && (
              <p className="tiny muted prose">
                No targets yet — the baseline fortnight is observation. Log what you
                normally eat and do, and the first plan is built from it.
              </p>
            )}
          </>
        )}
      </div>
    </>
  )
}

function prettyDate(date) {
  const [y, m, d] = date.split('-').map(Number)
  return new Date(y, m - 1, d).toLocaleDateString(undefined, {
    weekday: 'long', day: 'numeric', month: 'long',
  })
}

/** "Yesterday" beats a date the user has to decode. */
function relativeLabel(date) {
  const t = todayDate()
  if (date === shiftDate(t, -1)) return 'Yesterday'
  return 'Earlier'
}
