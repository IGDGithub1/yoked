import { useEffect, useMemo, useRef, useState } from 'react'
import { api, ApiError } from '../api'
import { SECTIONS, sectionById } from '../questions'
import { Field, Question } from '../components/Fields'
import AvailabilityGrid from '../components/AvailabilityGrid'
import TierCheck from '../components/TierCheck'
import Yolk from '../components/Yolk'

/**
 * The onboarding quiz.
 *
 * Saves per section rather than per keystroke: a section is the unit a user
 * thinks in, and it keeps the request count sane while still meaning nothing is
 * lost if they close the tab mid-quiz.
 */
export default function Quiz({ initial, onDone, onExit, startAt, reviewing = false }) {
  const [answers, setAnswers] = useState(initial?.answers || {})
  const [progress, setProgress] = useState(initial?.progress || null)
  const [sectionId, setSectionId] = useState(
    () => startAt || firstIncomplete(initial?.progress)
  )
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [tierChecks, setTierChecks] = useState([])
  // Confirmation for "save and come back later". Without it the button flipped
  // `busy` for 200ms and changed nothing else, so a successful save was
  // indistinguishable from a dead control.
  const [saved, setSaved] = useState(false)
  const topRef = useRef(null)

  const section = sectionById(sectionId) || SECTIONS[0]
  const index = SECTIONS.findIndex((s) => s.id === section.id)
  const units = answers['1.5'] || 'imperial'

  const doneCount = useMemo(() => {
    if (!progress) return 0
    return SECTIONS.filter((s) => progress.sections?.[s.id]?.complete).length
  }, [progress])

  // Scroll to the top on section change — otherwise a long section leaves the
  // next one's first question below the fold. The sticky appbar is cleared by
  // scroll-margin-top on [data-scroll-anchor], not by an offset here.
  //
  // The reduced-motion check has to happen in JS: a `behavior` argument is a
  // scripted value, so the `scroll-behavior: auto !important` rule in base.css
  // does not override it.
  useEffect(() => {
    const still = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    topRef.current?.scrollIntoView({ block: 'start', behavior: still ? 'auto' : 'smooth' })
  }, [sectionId])

  const set = (key) => (value) => {
    // A "saved" note next to an answer the user has since changed is a lie.
    setSaved(false)
    setAnswers((a) => ({ ...a, [key]: value }))
  }

  /** Which required questions in this section are still unanswered. */
  const missing = useMemo(() => {
    const required = progress?.sections?.[section.id]?.missing || []
    return required.filter((key) => {
      const v = answers[key]
      // An empty array IS an answer here — "no allergies" has to be sayable,
      // and the server distinguishes it from never having asked.
      if (Array.isArray(v)) return false
      return v === undefined || v === null || v === ''
    })
  }, [progress, section.id, answers])

  /**
   * @param advance  move to the next section on success
   * @param exit     hand control back to the shell — the "come back later" path
   */
  async function saveSection({ advance = true, exit = false } = {}) {
    setBusy(true)
    setError(null)
    setSaved(false)

    // Only this section's answers, so a save cannot resurrect a value the user
    // cleared in a section they have since left.
    const keys = section.grid ? ['7.1'] : (section.questions || []).map((q) => q.key)
    const payload = {}
    for (const k of keys) {
      if (answers[k] !== undefined) payload[k] = answers[k]
      const detail = (section.questions || []).find((q) => q.detailKey === k)
      if (detail && answers[detail.detailKey] !== undefined) {
        payload[detail.detailKey] = answers[detail.detailKey]
      }
    }
    // Companion free-text fields (3.2_detail) are not questions in their own
    // right, so they are collected separately.
    for (const q of section.questions || []) {
      if (q.detailKey && answers[q.detailKey] !== undefined) {
        payload[q.detailKey] = answers[q.detailKey]
      }
    }

    // Nothing to send. This is a normal thing to do — opening a resumable
    // section, touching nothing, and moving on — so it must not be a failed
    // request. The API rejects an empty answers object (correctly: it would
    // otherwise be an untraceable no-op), so the request is skipped entirely
    // rather than sent and allowed to 422.
    if (Object.keys(payload).length === 0) {
      if (advance) goNext()
      // Leaving with nothing to save is still leaving: the button must act even
      // when the section was untouched, or it reads as broken.
      if (exit) {
        onExit?.()
        return
      }
      setBusy(false)
      // Nothing was sent, but nothing was lost either, and saying so is kinder
      // than a control that appears to do nothing.
      setSaved(true)
      return
    }

    try {
      const res = await api.onboarding.save(payload)
      setProgress(res.progress)

      // The server may return a confirmation prompt — currently a soft-tiered
      // injury described in clinical terms. It is a question, not an error: the
      // answer is already saved.
      const prompts = (res.confirm || []).flatMap((c) => c.items || [])
      if (prompts.length > 0) {
        setTierChecks(prompts)
        setBusy(false)
        return
      }

      if (exit) {
        onExit?.()
        return   // leave `busy` set: the screen is going away
      }
      if (advance) {
        goNext(res.progress)
      } else {
        setSaved(true)
      }
      setBusy(false)
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not save. Check your connection.')
      setBusy(false)
    }
  }

  function goNext(p = progress) {
    const next = SECTIONS[index + 1]
    if (next) {
      setSectionId(next.id)
      return
    }
    // Past the last section: hand back to the shell, which decides between
    // "start the baseline" and "still incomplete".
    onDone(p)
  }

  async function resolveTier(item, tier) {
    setBusy(true)
    try {
      if (tier === 'hard') {
        await api.onboarding.confirmTier(item.subject, 'hard')
        // Mirror the change locally so the form does not contradict what the
        // server now holds.
        setAnswers((a) => ({
          ...a,
          '3.4': (a['3.4'] || []).map((inj) =>
            inj.area?.toLowerCase() === item.subject?.toLowerCase()
              ? { ...inj, tier: 'hard', work_up_to: false }
              : inj
          ),
        }))
      }
      const remaining = tierChecks.filter((t) => t !== item)
      setTierChecks(remaining)
      setBusy(false)
      if (remaining.length === 0) goNext()
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Could not save that change.')
      setBusy(false)
    }
  }

  const pct = Math.round((doneCount / (SECTIONS.length - 1)) * 100)

  return (
    <>
      <header className="appbar">
        <Yolk pct={pct} size={34} label={`${doneCount} of ${SECTIONS.length - 1} sections done`} />
        <span className="brand">Yoked</span>
        {reviewing ? (
          // A "3 / 10" counter would be a lie here: the user came to change one
          // section, not to walk the quiz. Offer the way out instead — and save
          // on the way, because leaving via the header should not quietly
          // discard an edit the user just made.
          <button
            type="button"
            className="btn btn--quiet push"
            onClick={() => saveSection({ advance: false, exit: true })}
            disabled={busy}
          >
            All answers
          </button>
        ) : (
          <span className="push tiny muted num">
            {index + 1} / {SECTIONS.length}
          </span>
        )}
      </header>

      <div className="wrap stack-lg" ref={topRef} data-scroll-anchor>
        <div>
          <p className="eyebrow">{section.optional ? 'Optional' : `Section ${section.id}`}</p>
          <h1 className="title">{section.name}</h1>
          {section.blurb && <p className="muted prose" style={{ marginTop: 8 }}>{section.blurb}</p>}
        </div>

        <div className="rail" aria-label={`Section ${index + 1} of ${SECTIONS.length}`}>
          {SECTIONS.map((s, i) => (
            <span
              key={s.id}
              className="pip"
              data-state={
                progress?.sections?.[s.id]?.complete ? 'done' : i === index ? 'now' : 'todo'
              }
            />
          ))}
          <span className="rail-label">{section.name}</span>
        </div>

        <div className="card stack-lg">
          {section.grid ? (
            <AvailabilityGrid value={answers['7.1']} onChange={set('7.1')} />
          ) : (
            (section.questions || []).map((q) => (
              <Question key={q.key} q={q}>
                <Field q={q} value={answers[q.key]} onChange={set(q.key)} units={units} />

                {/* A companion free-text field, shown once its parent has an
                    answer — asking for detail about nothing is noise. */}
                {q.detailKey && Array.isArray(answers[q.key]) && answers[q.key].length > 0 && (
                  <label className="field" style={{ marginTop: 14 }}>
                    <span className="label">{q.detailLabel}</span>
                    <textarea
                      className="textarea"
                      value={answers[q.detailKey] ?? ''}
                      onChange={(e) =>
                        setAnswers((a) => ({ ...a, [q.detailKey]: e.target.value }))
                      }
                    />
                    {q.detailHelp && <p className="hint">{q.detailHelp}</p>}
                  </label>
                )}
              </Question>
            ))
          )}
        </div>

        {error && (
          <div role="alert">
            <p className="error">{error}</p>
            <p className="error-help">Your answers are still here — try saving again.</p>
          </div>
        )}

        {missing.length > 0 && (
          <p className="tiny muted" role="status">
            Still needed in this section: {missing.join(', ')}
          </p>
        )}

        {saved && (
          <p className="saved-note" role="status">
            Saved. You can close this and pick up where you left off.
          </p>
        )}

        <div className="row" style={{ flexWrap: 'wrap' }}>
          {index > 0 && (
            <button
              type="button"
              className="btn btn--ghost"
              onClick={() => setSectionId(SECTIONS[index - 1].id)}
              disabled={busy}
            >
              Back
            </button>
          )}

          <div className="push row" style={{ flexWrap: 'wrap' }}>
            {/*
              Saves this section and LEAVES. It used to save without advancing
              and without any acknowledgement, so it looked like a dead control:
              nothing moved, nothing said anything, and the user was still in the
              quiz they had just asked to leave.
            */}
            <button
              type="button"
              className="btn btn--quiet"
              onClick={() => saveSection({ advance: false, exit: true })}
              disabled={busy}
            >
              {reviewing ? 'Done' : 'Save and finish later'}
            </button>
            <button
              type="button"
              className="btn btn--primary"
              onClick={() => saveSection()}
              disabled={busy}
            >
              {busy ? 'Saving…' : index === SECTIONS.length - 1 ? 'Finish' : 'Continue'}
            </button>
          </div>
        </div>
      </div>

      {tierChecks.length > 0 && (
        <TierCheck
          item={tierChecks[0]}
          busy={busy}
          onUpgrade={() => resolveTier(tierChecks[0], 'hard')}
          onKeep={() => resolveTier(tierChecks[0], 'soft')}
        />
      )}
    </>
  )
}

/** Resume where the user left off rather than restarting at section 1. */
function firstIncomplete(progress) {
  if (!progress?.sections) return SECTIONS[0].id
  const found = SECTIONS.find((s) => !s.optional && !progress.sections[s.id]?.complete)
  return (found || SECTIONS[SECTIONS.length - 1]).id
}
