import { useEffect, useState } from 'react'

/**
 * Hash routing, in about forty lines.
 *
 * The app had no router at all, and App.jsx said so: "a URL router adds nothing while
 * the app is this linear." That was true while there was one destination past
 * onboarding. With a Dashboard and a Journal it stops being true — you land on one,
 * work in the other, and come back — and a browser back button that exits the app is
 * a bad PWA.
 *
 * HASH rather than the History API, because this is a single static index.html served
 * by nginx. A path-based route would 404 on reload unless every URL were rewritten
 * server-side, and .htaccess cannot touch what nginx answers directly on this host.
 * A hash never reaches the server at all.
 *
 * Hand-rolled rather than React Router: the whole project is deliberately free of
 * dependencies past React, and what is actually needed here is "read the hash, listen
 * for changes, write the hash". A 10kB router for that would be the largest
 * abstraction in the codebase.
 *
 * Routing does NOT decide whether a user is allowed somewhere. The server still owns
 * that through /api/me's `next` step — App.jsx sends a half-onboarded user to the quiz
 * regardless of what the hash says, because duplicating that logic in the client is
 * two sources of truth that drift.
 */

/**
 * The destinations a signed-in, onboarded user can reach.
 *
 * `coach` and `profile` are real routes but NOT in the nav. Both are reached from a
 * Dashboard card: the profile is visited rarely once the quiz is answered, and the coach
 * is entered when there is something to say rather than browsed. Being routes anyway is
 * what makes them linkable and gives them a working back button.
 */
export const ROUTES = ['dashboard', 'journal', 'coach', 'profile']

const DEFAULT = 'dashboard'

/** Parse a hash into a route name, falling back rather than 404ing. */
function parse(hash) {
  const name = String(hash || '').replace(/^#\/?/, '').split('?')[0].trim()
  return ROUTES.includes(name) ? name : DEFAULT
}

/**
 * The current route, and a way to change it.
 *
 * `navigate` writes the hash, which fires hashchange and updates state. Going through
 * the URL rather than setting state directly is what makes the back button work: every
 * navigation is a real history entry.
 */
export function useRoute() {
  const [route, setRoute] = useState(() => parse(window.location.hash))

  useEffect(() => {
    const onChange = () => setRoute(parse(window.location.hash))
    window.addEventListener('hashchange', onChange)

    // Normalise a bare or bogus hash on first load, so the URL matches what is
    // actually rendered. replaceState rather than assignment: landing on the app
    // should not leave a history entry you can go "back" to.
    const initial = parse(window.location.hash)
    if (window.location.hash !== `#/${initial}`) {
      window.history.replaceState(null, '', `#/${initial}`)
    }

    return () => window.removeEventListener('hashchange', onChange)
  }, [])

  const navigate = (name) => {
    const to = ROUTES.includes(name) ? name : DEFAULT
    if (parse(window.location.hash) === to) return
    // Assignment, not replaceState: this IS a new place, so it belongs in history.
    window.location.hash = `#/${to}`
  }

  return [route, navigate]
}
