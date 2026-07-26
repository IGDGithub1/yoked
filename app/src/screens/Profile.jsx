import { useEffect, useState } from 'react'
import { api } from '../api'
import { SECTIONS } from '../questions'
import Quiz from './Quiz'
import Yolk from '../components/Yolk'
import Settings from '../components/Settings'
import Preferences from '../components/Preferences'

/**
 * The user's profile: review and change what the coach knows about them.
 *
 * Was "Your answers" and sat in the top nav, which gave a screen visited a handful of
 * times the same prominence as the two views used daily. It is reached from a Dashboard
 * card now, and named for what it IS rather than for how it was collected — a user has
 * a profile, not a set of quiz answers.
 *
 * The quiz is a one-way corridor by design — it asks in an order that builds on
 * itself. But a corridor with no door back means a question left blank stays
 * blank forever, and an answer that turns out wrong cannot be corrected. This is
 * the door.
 *
 * It is a section PICKER rather than a re-run of the quiz: someone fixing one
 * answer should not have to walk through nine sections to reach it. Choosing a
 * section drops into the existing Quiz component at that section, so there is
 * one implementation of every field, not two that drift.
 */
/*
 * `standalone` means "nothing else is providing a header".
 *
 * Past onboarding the Profile renders INSIDE the Shell, which already has an appbar, and
 * rendering a second one put a sticky "Yoked / Done" bar in the middle of the page. It went
 * unnoticed while this screen was one short list; adding the preferences and settings
 * sections made it long enough to scroll, and the stray bar appeared halfway down.
 *
 * Mid-onboarding it IS standalone: the "save and finish later" exit lands here before the
 * Shell exists, and without its own header there would be no way back out.
 */
export default function Profile({ onClose, standalone = false }) {
  const [data, setData] = useState(null)
  const [failed, setFailed] = useState(false)
  const [editing, setEditing] = useState(null)

  const load = () => {
    setFailed(false)
    api.onboarding
      .load()
      .then(setData)
      .catch(() => setFailed(true))
  }

  useEffect(load, [])

  // Editing: hand off to the quiz, reloading on the way back so the summary
  // reflects what was just changed.
  if (editing !== null && data !== null) {
    return (
      <Quiz
        initial={data}
        startAt={editing}
        reviewing
        onExit={() => {
          setEditing(null)
          load()
        }}
        // In review mode "Continue" walks sections as usual; running off the end
        // returns to the list rather than trying to start the baseline again.
        onDone={() => {
          setEditing(null)
          load()
        }}
      />
    )
  }

  if (failed) {
    return (
      <div className="centred">
        <div className="card stack">
          <h1 className="subheading">Could not load your profile</h1>
          <p className="small muted" style={{ margin: 0 }}>
            Nothing is lost. Check your connection and try again.
          </p>
          <button className="btn btn--primary" onClick={load}>Try again</button>
          <button className="btn btn--quiet" onClick={onClose}>Go back</button>
        </div>
      </div>
    )
  }

  if (data === null) {
    return (
      <div className="centred">
        <div style={{ textAlign: 'center' }}>
          <Yolk pct={45} size={48} label="Loading" />
        </div>
      </div>
    )
  }

  const progress = data.progress?.sections || {}

  return (
    <>
      {standalone && (
        <header className="appbar">
          <Yolk pct={100} size={34} />
          <span className="brand">Yoked</span>
          <button type="button" className="btn btn--quiet push" onClick={onClose}>
            Done
          </button>
        </header>
      )}

      <div className="wrap stack-lg">
        <div>
          <p className="eyebrow">Your profile</p>
          <h1 className="title">Change anything.</h1>
          <p className="muted prose" style={{ marginTop: 8 }}>
            Your coach reads these every time it writes a week, so a correction here
            changes the next plan. Medical answers and allergies take effect
            immediately.
          </p>
        </div>

        <ul className="seclist">
          {SECTIONS.map((s) => {
            const p = progress[s.id] || {}
            const answered = p.answered ?? 0
            const total = p.total ?? (s.questions?.length || 0)
            // The count of REQUIRED questions still blank, from the server's own
            // `missing` list — not total minus answered, which counts optional
            // questions too and overstated it badly (section 4 read "10 still
            // needed" when only 3 of its 10 are required).
            const stillNeeded = (p.missing || []).length
            const complete = p.complete ?? false

            return (
              <li key={s.id}>
                <button type="button" className="secrow" onClick={() => setEditing(s.id)}>
                  <span className="secrow-num num">{s.optional ? '—' : s.id}</span>
                  <span className="secrow-body">
                    <span className="secrow-name">{s.name}</span>
                    <span className="secrow-meta">
                      {answered} of {total} answered
                      {!complete && stillNeeded > 0 && (
                        <span className="secrow-flag"> · {stillNeeded} still needed</span>
                      )}
                    </span>
                  </span>
                  <span className="secrow-go" aria-hidden="true">›</span>
                </button>
              </li>
            )
          })}
        </ul>

        <p className="tiny muted prose">
          Changing a hard limit, like an allergy or an injury you told us to avoid, takes
          effect the moment you save it. Nothing your coach writes after that can
          contradict it.
        </p>

        {/*
          Below the sections, because these are visited even less often than an answer
          correction, and because "what does the coach already believe about me" only makes
          sense after you have seen where those beliefs came from.
        */}
        <div>
          <h2 className="subheading" style={{ marginBottom: 8 }}>What your coach avoids</h2>
          <Preferences />
        </div>

        <div>
          <h2 className="subheading" style={{ marginBottom: 8 }}>Settings</h2>
          <Settings />
        </div>

        {/*
          A way out at the bottom, when the header is not providing one.
          Inside the Shell the nav can take you anywhere, but a long screen still wants an
          explicit "I am finished here" rather than making the user scroll back up to the
          header and pick a destination.
        */}
        {!standalone && (
          <button type="button" className="btn btn--ghost" onClick={onClose}>
            Done
          </button>
        )}
      </div>
    </>
  )
}
