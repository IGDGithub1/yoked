import { useCallback, useEffect, useState } from 'react'

/**
 * A collapsible section with a persisted open/closed state.
 *
 * The day screen is long — check-in, six meal slots, then sessions — and most
 * visits are about one of those three things. Collapsing is what makes the third
 * visit of the day as cheap as the first.
 *
 * Persisted in localStorage because the alternative is re-collapsing training
 * every morning. Keyed by a stable name, not by date: "I keep training closed" is
 * a preference about the section, not about Tuesday.
 *
 * Implemented as a real <button aria-expanded> against a plain hidden div rather
 * than <details>/<summary>: Safari cannot animate details, and more importantly
 * the summary row here holds its own live numbers ("725 / 2400", "1 of 1 done")
 * which belong in the heading whether the body is open or shut.
 */

const KEY = 'yoked.sections'

function readState() {
  try {
    const raw = localStorage.getItem(KEY)
    const parsed = raw ? JSON.parse(raw) : null
    return parsed && typeof parsed === 'object' ? parsed : {}
  } catch {
    // Private mode, or a stale value from an older shape. A section that fails
    // to remember its state is a nuisance; one that throws is a blank screen.
    return {}
  }
}

function writeState(next) {
  try {
    localStorage.setItem(KEY, JSON.stringify(next))
  } catch {
    // Nothing to do and nothing worth telling the user. The section still works
    // for this visit.
  }
}

/**
 * Read-and-persist for one section's open state.
 *
 * Exported because the check-in card wants the same persistence without this
 * component's heading layout.
 */
export function useSectionState(name, defaultOpen) {
  const [open, setOpen] = useState(() => {
    const stored = readState()[name]
    return typeof stored === 'boolean' ? stored : defaultOpen
  })

  const toggle = useCallback(() => {
    setOpen((was) => {
      const now = !was
      writeState({ ...readState(), [name]: now })
      return now
    })
  }, [name])

  return [open, toggle, setOpen]
}

export default function Section({ name, title, summary, defaultOpen = true, children }) {
  const [open, toggle] = useSectionState(name, defaultOpen)
  const bodyId = `sec-${name}`

  return (
    <section className="stack" aria-labelledby={`${bodyId}-h`}>
      <div className="row sec-head">
        <button
          type="button"
          className="sec-toggle"
          aria-expanded={open}
          aria-controls={bodyId}
          onClick={toggle}
        >
          {/* Rotated by CSS on [aria-expanded]. A single glyph rather than two
              swapped ones, so the transition has something to animate. */}
          <span className="sec-caret" aria-hidden="true">›</span>
          {/* A real heading, not a styled span: this is the document outline a
              screen-reader user navigates by, and wrapping it in a button does
              not stop it being the section's title. */}
          <span className="heading" role="heading" aria-level="2" id={`${bodyId}-h`}>
            {title}
          </span>
        </button>
        {/* Lives outside the button: it is status, not a control, and putting it
            inside would read it out as part of the toggle's label. */}
        {summary && <div className="push sec-summary">{summary}</div>}
      </div>

      {/* Unmounted rather than hidden when closed. These bodies hold forms with
          their own draft state, and a hidden-but-mounted session form would keep
          half-typed numbers alive invisibly. */}
      {open && <div id={bodyId} className="stack">{children}</div>}
    </section>
  )
}
