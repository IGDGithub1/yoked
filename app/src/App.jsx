import { useCallback, useEffect, useState } from 'react'
import { api } from './api'
import Auth from './screens/Auth'
import Quiz from './screens/Quiz'
import Ready from './screens/Ready'
import Today from './screens/Today'
import Answers from './screens/Answers'
import Yolk from './components/Yolk'

/**
 * Routing, such as it is.
 *
 * The server decides where a user belongs — /api/me returns a `next` step from
 * their onboarding_state and quiz progress. Duplicating that logic here would
 * mean two sources of truth that drift; a URL router adds nothing while the app
 * is this linear.
 */
export default function App() {
  const [state, setState] = useState({ status: 'loading' })

  const boot = useCallback(async () => {
    try {
      const me = await api.me()
      if (!me.authenticated) {
        setState({ status: 'anon' })
        return
      }
      setState({ status: 'in', user: me.user, next: me.next })
    } catch {
      setState({ status: 'offline' })
    }
  }, [])

  useEffect(() => { boot() }, [boot])

  /** After sign-in or sign-up, the response already carries user and next. */
  const signedIn = (body) => setState({ status: 'in', user: body.user, next: body.next })

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

  // Past onboarding, the app IS the logging screen. There is no dashboard
  // between them: a user who is set up came here to log something.
  return <Today user={state.user} onSignOut={signOut} onReview={review} />
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
