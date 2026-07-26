/**
 * The icon set: Lucide, re-exported through one module.
 *
 * WHY A WRAPPER rather than importing from 'lucide-react' at each use site:
 *
 *   - Size and stroke are set once. Icons that disagree by a pixel of stroke weight
 *     read as assembled from different places, and DESIGN.md's whole argument is that
 *     the app should look written.
 *   - `aria-hidden` is applied here so it cannot be forgotten. An icon is never the
 *     accessible name — the button around it carries the aria-label, because "gauge" is
 *     not what the control does.
 *   - Renaming an icon later touches one file.
 *
 * Bundling: lucide-react ships as ES modules, so Vite tree-shakes it and only the icons
 * imported below reach the bundle. Nothing is fetched at runtime, which matters because
 * the CSP blocks external requests outright — an icon font or a CDN sprite would simply
 * not load.
 */
import {
  Contrast,
  Gauge,
  LogOut,
  Moon,
  ScrollText,
  Sun,
  UserCog,
} from 'lucide-react'

/**
 * Shared props, so one icon cannot drift from the rest.
 *
 * 20px against Lucide's 24px default: these sit in a header beside 22px display text,
 * and the default read as oversized next to it.
 */
const base = {
  size: 20,
  strokeWidth: 2,
  'aria-hidden': true,
  focusable: 'false',
}

const wrap = (Component) => (props) => <Component {...base} {...props} />

/** Dashboard: a gauge, for the page that shows where things stand. */
export const GaugeIcon = wrap(Gauge)

/** Journal: scroll-text, for the thing you write the day into. */
export const ScrollTextIcon = wrap(ScrollText)

/*
 * Theme, one glyph per state.
 *
 * A separate icon rather than one with a badge: the control cycles system → light →
 * dark, and it has to say which is active at a glance. Contrast (a half-filled circle)
 * reads as "follows the device" better than any composite.
 */
export const SunIcon = wrap(Sun)
export const MoonIcon = wrap(Moon)
export const ContrastIcon = wrap(Contrast)

export const LogOutIcon = wrap(LogOut)

/** The profile card. Not in the nav, but from the same set. */
export const UserIcon = wrap(UserCog)
