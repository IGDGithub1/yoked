import { useState } from 'react'
import Yolk from './Yolk'
import ThemeToggle from './ThemeToggle'
import { GaugeIcon, LogOutIcon, ScrollTextIcon, UsersIcon } from './Icons'

/**
 * The chrome both signed-in views share.
 *
 * ALL NAVIGATION IS IN THE HEADER. The first version put view switching in a bottom tab
 * bar and everything else at the top, which meant a user scanning for "where can I go"
 * had to check two edges of the screen. Split navigation is worse than either edge
 * alone, whichever one you pick.
 *
 * Icons rather than text, because four text links do not fit a 360px header without
 * wrapping, and these are destinations a user learns once. Every one carries an
 * aria-label and a title, so the label is available to a screen reader and to a hover.
 *
 * Order is deliberate: the two DESTINATIONS first, then the two SETTINGS-ish controls,
 * with sign-out last because it is the one that ends the session.
 */
export default function Shell({ route, onNavigate, onSignOut, friendRequests = 0, children }) {
  const [confirming, setConfirming] = useState(false)

  return (
    <>
      <header className="appbar">
        {/*
          The mark is a link home. A logo that does nothing is a wasted affordance, and
          "click the logo to get back" is the one navigation convention that needs no
          teaching.
        */}
        <button
          type="button"
          className="brandlink"
          onClick={() => onNavigate('dashboard')}
          aria-label="Yoked home"
          title="Yoked home"
        >
          <Yolk pct={100} size={34} />
          <span className="brand">Yoked</span>
        </button>

        <nav className="navicons push" aria-label="Views">
          <NavIcon
            route="dashboard"
            current={route}
            onNavigate={onNavigate}
            label="Dashboard"
            Icon={GaugeIcon}
          />
          <NavIcon
            route="journal"
            current={route}
            onNavigate={onNavigate}
            label="Journal"
            Icon={ScrollTextIcon}
          />
          <NavIcon
            route="friends"
            current={route}
            onNavigate={onNavigate}
            label="Friends"
            Icon={UsersIcon}
            /* A count, not a dot: "3 waiting" is worth knowing before you tap. Only ever
               requests made TO you, since those are the ones that need an answer. */
            badge={friendRequests}
          />

          <ThemeToggle />

          {/*
            Confirmed, not immediate. As a text link next to "Your answers" this was hard
            to hit by accident; as a bare icon beside the theme toggle it is not, and the
            cost of a mis-tap is re-entering a password on a phone.
          */}
          <button
            type="button"
            className="navbtn"
            onClick={() => setConfirming(true)}
            aria-label="Sign out"
            title="Sign out"
          >
            <LogOutIcon />
          </button>
        </nav>
      </header>

      {confirming && (
        <div className="signout-confirm" role="alertdialog" aria-label="Sign out?">
          <p className="small" style={{ margin: 0 }}>Sign out of Yoked?</p>
          <div className="row">
            <button type="button" className="btn btn--primary" onClick={onSignOut}>
              Sign out
            </button>
            <button type="button" className="btn btn--quiet" onClick={() => setConfirming(false)}>
              Stay
            </button>
          </div>
        </div>
      )}

      {children}
    </>
  )
}

/**
 * One destination.
 *
 * aria-current="page" rather than a class, so the attribute a screen reader announces
 * and the one the CSS styles are the same thing and cannot disagree.
 */
function NavIcon({ route, current, onNavigate, label, Icon, badge = 0 }) {
  const active = route === current
  return (
    <button
      type="button"
      className="navbtn"
      aria-current={active ? 'page' : undefined}
      /* The count belongs in the accessible name, not just the pixels. A screen reader
         announcing "Friends" while a sighted user sees "Friends (2)" is the same class of
         bug as a selected chip that only looks selected to the accessibility tree. */
      aria-label={badge > 0 ? `${label}, ${badge} waiting` : label}
      title={badge > 0 ? `${label} (${badge} waiting)` : label}
      onClick={() => onNavigate(route)}
    >
      <Icon />
      {badge > 0 && <span className="navbadge" aria-hidden="true">{badge}</span>}
    </button>
  )
}
