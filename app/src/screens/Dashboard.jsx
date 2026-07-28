import { useCallback, useEffect, useState } from 'react'
import { api, today as todayDate, shiftDate } from '../api'
import Notifications from '../components/Notifications'
import WeeklyCheckIn from '../components/WeeklyCheckIn'
import NextDay from '../components/NextDay'
import { MessageIcon, UserIcon } from '../components/Icons'
import Yolk from '../components/Yolk'

/**
 * The landing page: what happened, what the coach said, what is next.
 *
 * The split that made this exist: the Journal is for DATA ENTRY and this is for DATA
 * REVIEW. Everything review-shaped had been accumulating on the logging screen —
 * notifications, the weekly check-in, coach reviews, the baseline countdown — each one
 * pushing the actual meals further down and each one needing a comment justifying why
 * it outranked the last. The `yieldAccent` plumbing that made Food and Training go
 * quiet when a check-in was open was a symptom of arbitrating priority between things
 * that should never have shared a screen.
 *
 * Nothing here is data entry. The one exception is the weekly check-in, which is a
 * form but belongs to review: it is a conversation about a week that has ended, not a
 * log of what is happening now.
 */
export default function Dashboard({ user, baseline, onNavigate }) {
  const [notes, setNotes] = useState(null)
  const [weekly, setWeekly] = useState(null)
  const [week, setWeek] = useState(null)
  const [tomorrow, setTomorrow] = useState(null)
  const [chat, setChat] = useState(null)
  const [status, setStatus] = useState('loading')

  const load = useCallback(async () => {
    /*
     * Four independent fetches, in parallel, and each allowed to fail alone.
     *
     * A dashboard is a collection of unrelated panels: a failed notifications call
     * should not blank the macro chart. allSettled rather than all, so one rejection
     * does not take the page down.
     */
    const monday = weekStartOf(todayDate())
    const [n, w, wk, tm, ch] = await Promise.allSettled([
      api.notifications.load(),
      api.weekly.load(),
      api.nutrition.week(monday),
      api.tomorrow.load(),
      api.chat.load(),
    ])
    setNotes(n.status === 'fulfilled' ? n.value : null)
    setWeekly(w.status === 'fulfilled' ? w.value : null)
    setWeek(wk.status === 'fulfilled' ? wk.value : null)
    setTomorrow(tm.status === 'fulfilled' ? (tm.value?.review ?? null) : null)
    setChat(ch.status === 'fulfilled' ? ch.value : null)
    setStatus('ready')
  }, [])

  useEffect(() => { load() }, [load])

  if (status === 'loading') {
    return (
      <div className="wrap" style={{ textAlign: 'center', padding: '48px 20px' }}>
        <Yolk pct={45} size={40} label="Loading" />
      </div>
    )
  }

  return (
    <div className="wrap stack-lg">
      <div>
        <p className="eyebrow">{greeting()}</p>
        <h1 className="title">{user.display_name?.split(' ')[0]}</h1>
        {baseline && <BaselineLine baseline={baseline} />}
      </div>

      {/* Nudges and coach questions first: a nudge exists because the user has not
          been here, so it is the first thing worth seeing when they are. */}
      {notes && <Notifications data={notes} onChanged={load} />}

      {/* The check-in and any review the coach has written back. */}
      {weekly && <WeeklyCheckIn data={weekly} onAnswered={load} />}

      {/* Below the check-in and above the week chart. Tomorrow is less urgent than a
          check-in that shapes the coming week, and more urgent than a review of the one
          that just ended. */}
      <NextDay review={tomorrow} onChanged={load} />

      {week && <WeekAtAGlance week={week} onNavigate={onNavigate} />}

      {/*
        The way into the Journal, for a user who came here to log rather than read.
        Without it the header icon is the only route, and a landing page offering no
        action is a dead end.

        Yellow ONLY when nothing else on this page is asking for something. An open
        weekly check-in is time-boxed and shapes the coming week; logging today is
        available all day. Two yellow buttons on one screen is the exact problem
        splitting the views was meant to solve, and it reappeared here the moment the
        Dashboard grew a second action.
      */}
      <button
        type="button"
        className={weekly?.pending ? 'btn btn--ghost btn--wide' : 'btn btn--primary btn--wide'}
        onClick={() => onNavigate('journal')}
      >
        Log today
      </button>

      {/*
        The way into the conversation.
        Above the profile because it is the one a user reaches for when something has
        changed, and louder when the coach has asked something: an unanswered question is
        the only case where this card is the reason they opened the app.
      */}
      <CoachLink chat={chat} onNavigate={onNavigate} />

      {/*
        The profile, at the bottom and quiet.
        It was a top-level nav link, which gave a screen visited a handful of times the
        same prominence as the two views used every day. It still has to be REACHABLE —
        a question left blank stays blank forever otherwise — just not prominent.
      */}
      <button type="button" className="profilelink" onClick={() => onNavigate('profile')}>
        <UserIcon />
        {/* Two lines rather than one wrapping line. Inline, the label and its
            description wrapped mid-sentence and "you" landed alone on the second row. */}
        <span className="stack-tight">
          <span className="small" style={{ fontWeight: 600 }}>Your profile</span>
          <span className="tiny muted">What your coach knows about you</span>
        </span>
      </button>
      {/* Admin is a nav icon rather than a card here: it is somewhere you go to check on
          other people, not to change your own answers, and burying it cost two taps. */}
    </div>
  )
}

/**
 * The entry to the conversation.
 *
 * Two states, and the difference matters. Normally it is a quiet link like the profile's.
 * When the COACH has asked something unanswered it says so, because a question nobody
 * knows about is a question that never gets answered — and drift questions are written by
 * cron, so the user was never here when it arrived.
 */
function CoachLink({ chat, onNavigate }) {
  const turns = chat?.turns ?? []
  const last = turns[turns.length - 1]
  // A coach turn that ends the conversation and asked something. `drift` marks a turn the
  // coach opened rather than a reply.
  const asking = last && last.role === 'assistant'
    && (last.outcome === 'question' || last.drift !== null)

  return (
    <button type="button" className="profilelink" onClick={() => onNavigate('coach')}>
      <MessageIcon />
      <span className="stack-tight">
        <span className="small" style={{ fontWeight: 600 }}>
          {asking ? 'Your coach asked you something' : 'Talk to your coach'}
        </span>
        <span className="tiny muted">
          {asking
            ? 'Waiting on your answer'
            : chat?.pending
              ? 'Thinking about what you said'
              : 'Travel, illness, anything that changed'}
        </span>
      </span>
    </button>
  )
}

/** Local-morning/afternoon/evening, from the browser rather than the server. */
function greeting() {
  const h = new Date().getHours()
  if (h < 12) return 'Good morning'
  if (h < 18) return 'Good afternoon'
  return 'Good evening'
}

/** The Monday of a date's week. Mirrors Schedule::weekStart server-side. */
function weekStartOf(date) {
  const [y, m, d] = date.split('-').map(Number)
  const dt = new Date(y, m - 1, d)
  // getDay() is 0=Sunday, so Sunday belongs to the week that is ENDING, six days back.
  const back = (dt.getDay() + 6) % 7
  return shiftDate(date, -back)
}

/**
 * Where they are in the two weeks, in a line.
 *
 * Shorter than the Journal's pip rail on purpose: the Journal shows it because that is
 * where the logging happens and progress is the motivation. Here it is context.
 */
function BaselineLine({ baseline }) {
  if (!baseline.started) {
    return (
      <p className="small muted" style={{ margin: '6px 0 0' }}>
        Your two weeks start Monday.
      </p>
    )
  }
  const left = baseline.days_left
  return (
    <p className="small muted num" style={{ margin: '6px 0 0' }}>
      Day {baseline.day} of {baseline.total}
      {left > 0 ? `, plan in ${left} ${left === 1 ? 'day' : 'days'}` : ', plan on the way'}
    </p>
  )
}

/**
 * Seven days of calories against target.
 *
 * A bar per day rather than a line: a week is seven discrete days, and a line implies
 * a continuous quantity between them. Days with nothing logged are visibly empty
 * rather than zero, because "did not log" and "ate nothing" are different facts and
 * the distinction is the whole basis of the drift rules.
 *
 * No verdicts here. The server owns those and only computes them for a day that is
 * over; a dashboard drawing its own conclusions would be the mid-day scolding problem
 * again, one layer up.
 */
function WeekAtAGlance({ week, onNavigate }) {
  const days = week.days || []
  const logged = days.filter((d) => (d.totals?.calories || 0) > 0)

  if (logged.length === 0) {
    return (
      <section className="card stack-sm">
        <h2 className="subheading">This week</h2>
        <p className="small muted" style={{ margin: 0 }}>
          Nothing logged yet this week.
        </p>
      </section>
    )
  }

  const avg = Math.round(
    logged.reduce((a, d) => a + (d.totals?.calories || 0), 0) / logged.length
  )
  // Scaled to the tallest of target and actual, so an over-target day is visibly over
  // rather than clipped at the top of the chart.
  const target = days.find((d) => d.target)?.target?.calories || 0
  const peak = Math.max(target, ...days.map((d) => d.totals?.calories || 0), 1)

  return (
    <section className="card stack-sm">
      <div className="row">
        <h2 className="subheading">This week</h2>
        <span className="tiny muted num push">
          {avg} avg kcal
          {target ? <span className="muted"> / {Math.round(target)}</span> : null}
        </span>
      </div>

      {/*
        The target is ONE line across the whole chart, not a dash per bar.
        Per-bar it read as a plotted series with gaps where days were unlogged, which
        is exactly the wrong thing: the target is a fixed reference, and drawing it
        seven times made it look like data.
      */}
      <div className="weekchart">
        {/* The tracks and the target share one positioning context, so the line's
            percentage is measured against exactly the height the bars are. */}
        <div className="weekchart-tracks">
          {target > 0 && (
            <span
              className="weekchart-target"
              style={{ bottom: `${(target / peak) * 100}%` }}
              aria-hidden="true"
            />
          )}
          <div className="weekbars">
            {days.map((d) => {
              const cals = d.totals?.calories || 0
              const has = cals > 0
              return (
                <div className="weekbar-track" key={d.date}>
                  <span
                    className="weekbar-fill"
                    data-empty={has ? 'false' : 'true'}
                    style={{ height: has ? `${Math.max(3, (cals / peak) * 100)}%` : '0' }}
                  />
                </div>
              )
            })}
          </div>
        </div>

        {/* Labels outside the track context, so they never shift the baseline. */}
        <div className="weekbars weekbar-labels">
          {days.map((d) => (
            <span className="tiny muted" key={d.date}>{dayLetter(d.date)}</span>
          ))}
        </div>
      </div>

      <button type="button" className="btn btn--quiet" onClick={() => onNavigate('journal')}>
        See the detail
      </button>
    </section>
  )
}

/** One letter per weekday. Locale-aware, so it is not hardcoded English. */
function dayLetter(date) {
  const [y, m, d] = date.split('-').map(Number)
  return new Date(y, m - 1, d)
    .toLocaleDateString(undefined, { weekday: 'narrow' })
}
