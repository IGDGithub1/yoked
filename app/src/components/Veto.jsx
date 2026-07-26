import { useState } from 'react'
import { api } from '../api'
import Help from './Help'

/**
 * Turning something down (SPEC-coaching §5).
 *
 * "There's no 'nah, I don't want to do that workout' without a very good excuse."
 *
 * THE REASON IS THE WHOLE POINT (§5.1), so this is not a dismiss button with a confirm.
 * Picking a reason is the interaction, and there is no way to submit without one. A bare
 * "skip" would be easier to build and would throw away the only information that makes the
 * replacement any good.
 *
 * THE COACH MAY SAY NO (§5.4), which the copy has to prepare people for. Nothing here
 * promises a swap: the button says "ask for a swap", the confirmation says the coach will
 * look at it. An app that implies a veto is a delete, then declines, has lied twice.
 *
 * Not to be confused with logging a skip. Refusing tomorrow's dinner in advance is a
 * request; not having eaten it is a fact. The Journal already records facts.
 */

/*
 * The reason codes, in the order a real person would reach for them.
 *
 * Circumstances first, because those are the ones that get accepted, and burying them under
 * "don't like it" would teach the wrong habit. `needsText` mirrors the server exactly
 * (Vetoes::raise) — the client should not offer a submit the API will refuse.
 */
const REASONS = [
  { code: 'no_time',   label: 'No time',        needsText: false },
  { code: 'unwell',    label: 'Unwell',         needsText: false },
  { code: 'equipment', label: 'No equipment',   needsText: false },
  { code: 'travel',    label: 'Travelling',     needsText: false },
  { code: 'weather',   label: 'Weather',        needsText: false },
  { code: 'cant_do',   label: 'Cannot do it',   needsText: true },
  { code: 'dont_like', label: 'Do not like it', needsText: true },
  { code: 'other',     label: 'Something else', needsText: true },
]

/** Placeholder that matches the reason, so the prompt is never generic. */
const HINTS = {
  cant_do:   'What stops you? Anything that hurts, say where.',
  dont_like: 'What about it? The more specific, the better the swap.',
  other:     'A few words is plenty.',
}

export default function Veto({ subjectType, subjectId, label, onDone }) {
  const [open, setOpen] = useState(false)
  const [code, setCode] = useState(null)
  const [text, setText] = useState('')
  const [standing, setStanding] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState(null)
  const [sent, setSent] = useState(false)

  const chosen = REASONS.find((r) => r.code === code) || null
  const needsText = chosen?.needsText ?? false
  const ready = chosen !== null && (!needsText || text.trim().length > 0)

  async function submit(e) {
    e.preventDefault()
    if (!ready) return
    setError(null)
    setBusy(true)
    try {
      await api.vetoes.raise({
        subject_type: subjectType,
        subject_id: subjectId,
        reason_code: code,
        reason_text: text.trim() || undefined,
        scope: standing ? 'standing' : 'today',
      })
      setSent(true)
      setOpen(false)
      onDone?.()
    } catch (err) {
      setError(err.message || 'That did not send.')
    } finally {
      setBusy(false)
    }
  }

  /*
   * After sending, the control becomes a status line rather than vanishing.
   *
   * A button that disappears leaves no evidence anything happened, and the reply is minutes
   * away. This is the receipt.
   */
  if (sent) {
    return (
      <p className="tiny muted" style={{ margin: 0 }}>
        Asked your coach for a swap. It will change if it stacks up.
      </p>
    )
  }

  if (!open) {
    return (
      <button
        type="button"
        className="btn btn--ghost btn--small"
        onClick={() => setOpen(true)}
      >
        Cannot do this
      </button>
    )
  }

  return (
    <form className="veto stack-sm" onSubmit={submit}>
      <div className="row">
        <p className="tiny" style={{ margin: 0, fontWeight: 600 }}>
          Why not {label}?
        </p>
        <button
          type="button"
          className="btn btn--ghost btn--small push"
          onClick={() => { setOpen(false); setCode(null); setText(''); setError(null) }}
        >
          Cancel
        </button>
      </div>

      {/*
        role=radiogroup with aria-checked chips. The selected state needs its own CSS —
        aria-pressed styling does not apply to these, and shipping without it has produced
        an invisible selection twice before.
      */}
      <div className="chips" role="radiogroup" aria-label="Reason">
        {REASONS.map((r) => (
          <button
            key={r.code}
            type="button"
            role="radio"
            aria-checked={code === r.code}
            className="chip"
            onClick={() => setCode(r.code)}
          >
            {r.label}
          </button>
        ))}
      </div>

      {needsText && (
        <textarea
          className="textarea"
          rows="2"
          placeholder={HINTS[code] || ''}
          aria-label="Why not"
          value={text}
          onChange={(e) => setText(e.target.value)}
        />
      )}

      {/* Optional detail even when the code already says it: "no time" is enough to act on,
          and "no time, back to back meetings until 9" is enough to act on well. */}
      {chosen && !needsText && (
        <textarea
          className="textarea"
          rows="2"
          placeholder="Anything to add? Optional."
          aria-label="Anything to add"
          value={text}
          onChange={(e) => setText(e.target.value)}
        />
      )}

      {/*
        §5.2, the distinction that stops the app either forgetting a permanent dislike or
        reshuffling forever around a trip that ended. Off by default: most vetoes are about
        today, and a checkbox that quietly makes things permanent would be a trap.
      */}
      <label className="check tiny">
        <input
          type="checkbox"
          checked={standing}
          onChange={(e) => setStanding(e.target.checked)}
        />
        <span>
          Never suggest this again
          <Help label="What this does">
            Ticking this means your coach stops putting it in your plans, not just today.
            You can undo it in your profile later.
          </Help>
        </span>
      </label>

      {error && <p className="error tiny">{error}</p>}

      <div className="row">
        <button type="submit" className="btn btn--primary btn--small" disabled={!ready || busy}>
          {busy ? 'Sending…' : 'Ask for a swap'}
        </button>
        {/* Honest about what happens next, before they commit rather than after. */}
        {ready && (
          <span className="tiny muted push">Your coach decides</span>
        )}
      </div>
    </form>
  )
}

/**
 * The outcome of a veto already raised, shown against the thing it was about.
 *
 * Declines are shown, not hidden. §5.4 holds the line, and a user who cannot see that they
 * were told no will simply ask again — which is worse for both of them than reading the
 * reason once.
 */
export function VetoOutcome({ veto }) {
  if (!veto) return null

  if (veto.outcome === 'pending') {
    return (
      <p className="tiny muted" style={{ margin: 0 }}>
        Your coach is looking at this. It can take a few minutes.
      </p>
    )
  }

  return (
    <div className="stack-tight">
      <p className="tiny prose" style={{ margin: 0 }}>{veto.reply}</p>
      {veto.plan_changed && (
        <p className="tiny" style={{ margin: 0, fontWeight: 600 }}>Swapped out.</p>
      )}
      {veto.outcome === 'declined' && !veto.plan_changed && (
        <p className="tiny muted" style={{ margin: 0 }}>Still on the plan.</p>
      )}
      {veto.promoted && (
        <p className="tiny muted" style={{ margin: 0 }}>
          Added to your profile so it stops coming up.
        </p>
      )}
    </div>
  )
}
