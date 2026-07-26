import { useCallback, useEffect, useState } from 'react'
import { api } from './api'
import { useRoute } from './router'
import Auth from './screens/Auth'
import Quiz from './screens/Quiz'
import Ready from './screens/Ready'
import Dashboard from './screens/Dashboard'
import Journal from './screens/Journal'
import Answers from './screens/Answers'
import Shell from './components/Shell'
import Yolk from './components/Yolk'

/**
 * Two layers of routing, and the split between them matters.
 *
 * THE SERVER decides whether a user belongs in the app at all: /api/me returns a
 * `next` step from their onboarding_state and quiz progress, and a half-onboarded user
 * goes to the quiz whatever the URL says. Duplicating that logic here would be two
 * sources of truth that drift.
 *
 * THE HASH decides which of the two signed-in views they are looking at. That used to
 * be nothing — there was one destination past onboarding and a comment here saying "a
 * URL router adds nothing while the app is this linear", which was true at the time.
 * With a Dashboard for review and a Journal for entry it stops being true: you need to
 * land somewhere, get to the other, and come back without the back button ejecting you
 * from the app.
 */
export default function App() {
  const [state, setState] = useState({ status: 'loading' })
  const [route, navigate] = useRoute()

  const boot = useCallback(async () => {
    try {
      const me = await api.me()
      if (!me.authenticated) {
        setState({ status: 'anon' })
        return
      }
      setState({ status: 'in', user: me.user, next: me.next, baseline: me.baseline })

      /*
       * Report the timezone, but only when it differs from what is on file.
       *
       * The weekly slots fire in local time, so the server needs this to know when
       * "Saturday 18:00" is. Sent on boot rather than asked in the quiz because
       * the browser knows it accurately and it self-corrects after a move.
       *
       * Fire-and-forget on purpose: this is housekeeping, and a failure must not
       * stop a user reaching their day. It will be retried on the next boot.
       */
      const tz = Intl.DateTimeFormat().resolvedOptions().timeZone
      if (tz && tz !== me.user?.timezone) {
        api.setTimezone(tz).catch(() => {})
      }
    } catch {
      setState({ status: 'offline' })
    }
  }, [])

  useEffect(() => { boot() }, [boot])

  /**
   * After sign-in or sign-up.
   *
   * The login response carries user and next but NOT the baseline window, so this
   * re-boots rather than setting state from it directly. Without that, the
   * countdown was missing for the whole first session and only appeared after a
   * manual reload — the worst possible time for it to be absent, since a user in
   * observation has nothing else on screen telling them what is happening.
   *
   * Setting the user first means the app renders immediately and boot() fills in
   * the rest, rather than flashing the loading yolk again after a successful login.
   */
  const signedIn = (body) => {
    setState({ status: 'in', user: body.user, next: body.next })
    boot()
  }

  async function signOut() {
    try {
      await api.logout()
    } finally {
      setState({ status: 'anon' })
    }
  }

  if (state.status === 'loading') {
    return (
      <div className="centred">
        <div style={{ textAlign: 'center' }}>
          {/* The loading state is the mark again, part-filled — one idea reused
              rather than an unrelated spinner. */}
          <Yolk pct={45} size={48} label="Loading" />
        </div>
      </div>
    )
  }

  if (state.status === 'offline') {
    return (
      <div className="centred">
        <div className="card stack">
          <h1 className="subheading">Cannot reach Yoked</h1>
          <p className="small muted" style={{ margin: 0 }}>
            Check your connection. Nothing you have entered is lost.
          </p>
          <button className="btn btn--primary" onClick={boot}>Try again</button>
        </div>
      </div>
    )
  }

  if (state.status === 'anon') {
    return <Auth onSignedIn={signedIn} />
  }

  const step = state.next?.step

  // Reviewing answers after onboarding. Kept in App rather than inside Today
  // so it is reachable from anywhere that grows a link to it later, and so the
  // quiz is loaded fresh — the answers on screen must be what the server holds,
  // not whatever this session last typed.
  if (state.reviewing) {
    return (
      <Answers
        onClose={() => {
          // Re-boot on the way out: editing an answer can change what the
          // server says comes next, and reusing the old `next` would strand the
          // user on a screen that no longer applies.
          setState((s) => ({ ...s, reviewing: false }))
          boot()
        }}
      />
    )
  }

  const review = () => setState((s) => ({ ...s, reviewing: true }))

  if (step === 'onboarding' || step === 'start_baseline') {
    return (
      <QuizFlow
        user={state.user}
        initialStep={step}
        onRefresh={boot}
        onSignOut={signOut}
        onReview={review}
      />
    )
  }

  /*
   * Past onboarding: the Dashboard for review, the Journal for entry.
   *
   * Both live inside the same Shell so the header and tab bar are defined once. Before
   * the split the logging screen owned its own appbar, and a second view would have
   * copied it — which is how two headers drift apart.
   */
  return (
    <Shell route={route} onNavigate={navigate} onSignOut={signOut} onReview={review}>
      {route === 'journal' ? (
        <Journal
          /* Null unless mid-baseline. Drives the countdown and explains the absence
             of targets. */
          baseline={state.baseline}
        />
      ) : (
        <Dashboard user={state.user} baseline={state.baseline} onNavigate={navigate} />
      )}
    </Shell>
  )
}

/**
 * Loads the quiz once, then keeps the user in it until the baseline starts.
 *
 * Fetching here rather than in App keeps the boot path to a single request for
 * users who are past onboarding.
 */
function QuizFlow({ user, initialStep, onRefresh, onSignOut, onReview }) {
  const [phase, setPhase] = useState(initialStep === 'start_baseline' ? 'ready' : 'loading')
  const [data, setData] = useState(null)

  useEffect(() => {
    if (phase !== 'loading') return
    api.onboarding
      .load()
      .then((d) => {
        setData(d)
        setPhase(d.progress?.all_done ? 'ready' : 'quiz')
      })
      .catch(() => setPhase('quiz'))
  }, [phase])

  if (phase === 'loading') {
    return (
      <div className="centred">
        <div style={{ textAlign: 'center' }}>
          <Yolk pct={45} size={48} label="Loading" />
        </div>
      </div>
    )
  }

  if (phase === 'ready') {
    return <Ready onStarted={onRefresh} onReview={onReview} />
  }

  return (
    <Quiz
      initial={data}
      onDone={(progress) => setPhase(progress?.all_done ? 'ready' : 'quiz')}
      // "Save and finish later" mid-onboarding: land on the summary, which is
      // both the receipt that it saved and the way back in.
      onExit={onReview}
    />
  )
}
