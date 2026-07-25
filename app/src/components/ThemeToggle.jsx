import { useEffect, useState } from 'react'

/**
 * Light / dark / system, as one cycling button in the appbar.
 *
 * tokens.css already supports this: `data-theme` on the root wins over
 * prefers-color-scheme in BOTH directions, which is what lets someone force
 * light on a phone that is globally dark. All this does is set the attribute.
 *
 * Three states rather than a two-way switch, because "follow the system" is the
 * state most people want and a boolean toggle cannot express it — once you have
 * flipped a two-way switch you are pinned to your choice forever, including at
 * 2am when the phone goes dark and the app does not.
 *
 * One button, not a group of three: this is a rarely-touched preference sitting
 * in a crowded appbar, and cycling costs at most two taps.
 */

const KEY = 'yoked.theme'
const ORDER = ['system', 'light', 'dark']

const LABELS = {
  system: { icon: '◐', text: 'Theme: follows your device' },
  light: { icon: '☀', text: 'Theme: light' },
  dark: { icon: '☾', text: 'Theme: dark' },
}

function read() {
  try {
    const v = localStorage.getItem(KEY)
    return ORDER.includes(v) ? v : 'system'
  } catch {
    return 'system'
  }
}

/**
 * Applied to the ROOT element, which is what tokens.css keys on.
 *
 * 'system' removes the attribute rather than setting a value — the media query
 * is the fallback, and leaving a stale data-theme would override it.
 */
function apply(mode) {
  const root = document.documentElement
  if (mode === 'system') root.removeAttribute('data-theme')
  else root.setAttribute('data-theme', mode)
}

export default function ThemeToggle() {
  const [mode, setMode] = useState(read)

  useEffect(() => {
    apply(mode)
    try {
      localStorage.setItem(KEY, mode)
    } catch {
      // Nothing to tell the user; the theme still applies for this visit.
    }
  }, [mode])

  const next = () => setMode(ORDER[(ORDER.indexOf(mode) + 1) % ORDER.length])
  const label = LABELS[mode]

  return (
    <button
      type="button"
      className="btn btn--quiet theme-toggle"
      onClick={next}
      /* The icon alone is not a label. This is the whole accessible name, and it
         states the CURRENT theme rather than the next one — a control that
         announces where it will take you is a riddle. */
      aria-label={label.text}
      title={label.text}
    >
      <span aria-hidden="true">{label.icon}</span>
    </button>
  )
}

/**
 * Applies the stored theme before React mounts.
 *
 * Called from main.jsx, not from this component: by the time a component effect
 * runs, the page has already painted in the system theme, and someone who forced
 * light on a dark phone sees a dark flash on every load.
 */
export function applyStoredTheme() {
  apply(read())
}
