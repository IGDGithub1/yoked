import { useCallback, useEffect, useState } from 'react'
import { api, today as todayDate, shiftDate } from '../api'
import CheckIn from '../components/CheckIn'
import Food, { FoodSummary } from '../components/Food'
import Macros from '../components/Macros'
import Training, { TrainingSummary } from '../components/Training'
import Section from '../components/Section'
import Yolk from '../components/Yolk'

/**
 * The Journal: one day, and everything you enter about it.
 *
 * DATA ENTRY ONLY. This was called Today and had accumulated every review surface in
 * the app — notifications, the weekly check-in, coach reviews, the baseline countdown —
 * because there was nowhere else to put them. Each one pushed the actual meals further
 * down the page, and each needed a comment arguing why it outranked the last. Those all
 * live on the Dashboard now, and what is left is the three things a user came here to
 * record.
 *
 * The daily check-in stays. It is four taps about TODAY's state and it belongs beside
 * today's food, unlike the weekly one which is a conversation about a week that ended.
 *
 * Two requests per day, not seven: nutrition and training are separate endpoints
 * because they are separate concerns server-side, but they are always wanted together,
 * so they are fetched in parallel and rendered as one thing.
 */
export default function Journal({ baseline }) {
  const [date, setDate] = useState(todayDate)
  const [nutrition, setNutrition] = useState(null)
  const [training, setTraining] = useState(null)
  /*
   * Vetoes are fetched for the WEEK, not the day, and held here rather than in Food or
   * Training. One request feeds both sections, and a veto raised against a meal has to be
   * visible next to that meal on whichever day it lands on.
   */
  const [vetoes, setVetoes] = useState([])
  const [status, setStatus] = useState('loading')
  const [error, setError] = useState(null)

  const load = useCallback(async (d) => {
    setStatus('loading')
    setError(null)
    try {
      // In parallel: two sequential round trips on a phone is a visible wait for
      // no reason — neither response depends on the other.
      const [n, t, v] = await Promise.all([
        api.nutrition.day(d),
        api.training.day(d),
        // allSettled semantics by hand: a veto list that fails must not stop the day
        // rendering. It is annotation, not content.
        api.vetoes.load().catch(() => ({ vetoes: [] })),
      ])
      setNutrition(n)
      setTraining(t)
      setVetoes(v?.vetoes ?? [])
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
   * nutrition day is one request against a stale count the user would otherwise
   * see until they stepped away and back.
   */
  const onNutritionDay = (day) => setNutrition(day)

  /* After raising a veto: re-read the list so the pending state appears immediately. */
  const refreshVetoes = () =>
    api.vetoes.load().then((v) => setVetoes(v?.vetoes ?? [])).catch(() => {})
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
        {/* Forward is disabled on today: logging tomorrow's food is not a thing, and
            the Next Day Review (§4.1a) is a Dashboard card with a different job. */}
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

      {/* Below the day nav, not inside it: sitting between the two arrows the rail ran
          right up against them and read as part of the control. Only while observing,
          and only on today, since on an earlier day it would be counting from the
          wrong end. */}
      {baseline && isToday && <BaselineProgress baseline={baseline} />}

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
            Nothing you logged is lost, it saves as you go.
          </p>
          <button className="btn btn--primary" onClick={() => load(date)}>Try again</button>
        </div>
      )}

      {status === 'ready' && nutrition && training && (
        <>
          {/*
            The day's four numbers, first, because "how am I doing" is the question this
            screen is opened to answer and it was previously answered in a text line inside
            a section header you had to scroll to.

            Above the check-in deliberately. The check-in is four taps ABOUT today and can
            wait; the totals are the state of today and are why you came.
          */}
          <Macros totals={nutrition.totals} target={nutrition.target} />

          {/*
            Baseline week 1 is pure observation and has no prescription, so the absence of
            targets is the design rather than a gap.

            IT SITS HERE, next to the rings, and it used to sit at the bottom of the screen.
            That was fine while the targets were a line inside a section header; with four
            rings at the top it left a baseline user looking at four empty circles and the
            explanation three sections below. The rings each say "no target yet" to a screen
            reader; this is the same fact for everyone else.
          */}
          {!nutrition.target && (
            <p className="tiny muted prose" style={{ margin: '2px 0 0' }}>
              No targets yet. Log what you normally eat and do, and your first plan will
              follow.
            </p>
          )}

          {/* Keyed by date so stepping days REMOUNTS the card. Its open/shut state is
              decided once at mount from whether the day is answered, and that is
              exactly the granularity wanted: shut when you arrive at an answered day,
              but never yanked shut mid-interaction by a save response. */}
          <CheckIn key={date} checkin={nutrition.checkin} onSave={saveCheckIn} />

          <Section name="food" title="Food" summary={<FoodSummary day={nutrition} />}>
            <Food
              day={nutrition}
              date={date}
              isToday={isToday}
              onDay={onNutritionDay}
              vetoes={vetoes}
              onVeto={refreshVetoes}
            />
          </Section>

          <Section
            name="training"
            title="Training"
            summary={<TrainingSummary day={training} />}
          >
            <Training
              day={training}
              date={date}
              onDay={onTrainingDay}
              vetoes={vetoes}
              onVeto={refreshVetoes}
            />
          </Section>

        </>
      )}
    </div>
  )
}

/**
 * How far through the two weeks, and when the plan lands.
 *
 * The observation period is the least interesting part of the app to be in: you log,
 * and nothing answers back. A visible finish line is the smallest thing that makes it
 * feel like progress rather than a void.
 *
 * Says when the plan arrives, not why the two weeks matter. The user needs the date;
 * they do not need the mechanism.
 */
function BaselineProgress({ baseline }) {
  const { day, total, days_left: left, started } = baseline

  if (!started) {
    // Signed up mid-week. Their logging still counts as practice, and the clock has
    // not started, so a day count would read as negative.
    return (
      <p className="tiny muted" style={{ margin: '4px 0 0' }}>
        Your two weeks start Monday. Log anything you like before then.
      </p>
    )
  }

  return (
    <div className="baseline-progress">
      <div className="rail" aria-hidden="true">
        {/* One pip per day, echoing the onboarding rail rather than inventing a second
            progress language. */}
        {Array.from({ length: total }, (_, i) => (
          <span
            key={i}
            className="pip"
            data-state={i + 1 < day ? 'done' : i + 1 === day ? 'now' : ''}
          />
        ))}
      </div>
      <p className="tiny muted num" style={{ margin: '4px 0 0' }}>
        Day {day} of {total}
        {left > 0
          ? ` · your plan arrives in ${left} ${left === 1 ? 'day' : 'days'}`
          : ' · your plan is being built'}
      </p>
    </div>
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
