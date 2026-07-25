import { useEffect, useRef, useState } from 'react'

/**
 * A "?" that explains a term, on tap.
 *
 * The alternative is explaining everything inline, which is how a form turns into
 * an essay. "Session RPE" needs a definition the first two times someone sees it
 * and never again, so the definition should be available rather than present.
 *
 * A button, not `title=`: a native tooltip needs a mouse hover, which a phone does
 * not have, and this app is mostly used on a phone. Click-to-toggle works on both,
 * and the text is a real element so a screen reader reaches it through the
 * described-by relationship rather than a title attribute browsers announce
 * inconsistently.
 */
export default function Help({ label, children }) {
  const [open, setOpen] = useState(false)
  const wrap = useRef(null)
  const id = useRef(`help-${Math.random().toString(36).slice(2, 8)}`)

  // Escape and outside-click both close it. A popover that can only be dismissed
  // by finding the same small button again is a trap on a touchscreen.
  useEffect(() => {
    if (!open) return

    const onKey = (e) => { if (e.key === 'Escape') setOpen(false) }
    const onDown = (e) => {
      if (wrap.current && !wrap.current.contains(e.target)) setOpen(false)
    }
    document.addEventListener('keydown', onKey)
    // Capture phase: a click on another Help button should close this one before
    // that one opens, rather than leaving two bubbles on screen.
    document.addEventListener('pointerdown', onDown, true)
    return () => {
      document.removeEventListener('keydown', onKey)
      document.removeEventListener('pointerdown', onDown, true)
    }
  }, [open])

  return (
    <span className="help" ref={wrap}>
      <button
        type="button"
        className="help-btn"
        aria-expanded={open}
        aria-describedby={open ? id.current : undefined}
        /* Names the TERM, not the icon. "What is Session RPE?" is a useful
           announcement; "help" repeated six times down a form is not. */
        aria-label={`What is ${label}?`}
        onClick={() => setOpen((v) => !v)}
      >
        ?
      </button>
      {open && (
        <span className="help-bubble" role="note" id={id.current}>
          {children}
        </span>
      )}
    </span>
  )
}
