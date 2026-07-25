import { useEffect, useRef } from 'react'

/**
 * The tier-confirmation dialog — the one place this design raises its voice.
 *
 * Everywhere else is quiet so this lands. It is the screen where the app is
 * being genuinely careful with someone: they marked something described as
 * post-surgical as "work around", and the two settings behave very differently.
 *
 * It is a QUESTION, not a rejection. The answer already saved; this only offers
 * the chance to reconsider. The copy explains what each setting does rather than
 * asking "are you sure", and closes on the user's judgement being final.
 */
export default function TierCheck({ item, onUpgrade, onKeep, busy }) {
  const ref = useRef(null)

  // Focus the dialog on open so a keyboard or screen-reader user lands here
  // rather than continuing to tab through the form behind it.
  useEffect(() => {
    ref.current?.focus()
  }, [])

  useEffect(() => {
    const onKey = (e) => {
      // Escape keeps the existing answer — the safe, non-destructive default,
      // and it matches what dismissing a question should mean.
      if (e.key === 'Escape' && !busy) onKeep()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onKeep, busy])

  return (
    <div className="scrim" role="presentation">
      <div
        className="careful"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="tier-title"
        aria-describedby="tier-body"
        tabIndex={-1}
        ref={ref}
      >
        <div className="careful-top">
          <p className="careful-eyebrow">Worth a second look</p>
          <h2 className="careful-title" id="tier-title">
            You marked your {item.subject} as something to work around.
          </h2>
        </div>

        <div className="careful-body" id="tier-body">
          <blockquote className="quoted">
            “{item.description}”
            <span>Your description · {item.subject} · work around</span>
          </blockquote>

          <p>
            <strong>Work around</strong> means your plan may still load this, with a
            reason given, and you can turn it down. <strong>Avoid entirely</strong>{' '}
            means it never appears.
          </p>

          <p className="aside">
            Either is fine, you know your body. We ask because “{item.matched}” came
            up, and the two settings behave very differently.
          </p>

          <div className="row" style={{ flexWrap: 'wrap' }}>
            <button
              type="button"
              className="btn btn--primary"
              onClick={onUpgrade}
              disabled={busy}
            >
              {busy ? 'Saving…' : 'Avoid entirely'}
            </button>
            <button type="button" className="btn btn--ghost" onClick={onKeep} disabled={busy}>
              Keep working around it
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
