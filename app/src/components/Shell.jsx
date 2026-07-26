import Yolk from './Yolk'
import ThemeToggle from './ThemeToggle'

/**
 * The chrome both signed-in views share: header, tab bar, and the scroll container.
 *
 * Extracted when the Journal stopped being the only destination. Before this, Today
 * owned the appbar and every new view would have copied it — which is how two headers
 * drift apart, and how a tab bar ends up in one view and not the other.
 *
 * THE TAB BAR IS AT THE BOTTOM. This is a phone-first PWA and the top of a phone
 * screen is the hardest place to reach; the two things a user switches between all day
 * belong under their thumb. It also keeps the header free for the things that are not
 * navigation (theme, answers, sign out).
 */

const TABS = [
  // Dashboard first because it is the landing page: review before entry.
  { route: 'dashboard', label: 'Dashboard' },
  { route: 'journal', label: 'Journal' },
]

export default function Shell({ route, onNavigate, onSignOut, onReview, children }) {
  return (
    <>
      <header className="appbar">
        <Yolk pct={100} size={34} />
        <span className="brand">Yoked</span>
        <ThemeToggle />
        <button type="button" className="btn btn--quiet push" onClick={onReview}>
          Your answers
        </button>
        <button type="button" className="btn btn--quiet" onClick={onSignOut}>
          Sign out
        </button>
      </header>

      {children}

      <nav className="tabbar" aria-label="Views">
        {TABS.map((t) => (
          <button
            key={t.route}
            type="button"
            className="tab"
            /* aria-current, not aria-pressed: these are navigation targets rather
               than toggles, and a screen reader should announce the active one as
               "current page". */
            aria-current={route === t.route ? 'page' : undefined}
            onClick={() => onNavigate(t.route)}
          >
            {t.label}
          </button>
        ))}
      </nav>
    </>
  )
}
