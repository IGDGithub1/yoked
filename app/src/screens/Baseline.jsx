import Yolk from '../components/Yolk'

/**
 * The baseline fortnight, and the placeholder for everything after it.
 *
 * Logging, check-ins and the plan view are not built yet, so this is honest
 * about that rather than showing an empty dashboard that implies otherwise. An
 * empty state is an invitation to act — but only if there is something to act on.
 */
export default function Baseline({ user, onSignOut }) {
  return (
    <>
      <header className="appbar">
        <Yolk pct={100} size={34} />
        <span className="brand">Yoked</span>
        <button type="button" className="btn btn--quiet push" onClick={onSignOut}>
          Sign out
        </button>
      </header>

      <div className="wrap stack-lg">
        <div>
          <p className="eyebrow">Baseline</p>
          <h1 className="title">You are set up, {user.display_name?.split(' ')[0]}.</h1>
        </div>

        <div className="card stack">
          <h2 className="subheading">Nothing to log yet</h2>
          <p className="small muted prose" style={{ margin: 0 }}>
            Food and training logging is the next thing being built. Your profile,
            your limits and your week are saved — the coach can already write a plan
            from them, it just has nowhere to put your logs yet.
          </p>
        </div>

        <p className="tiny muted prose">
          Your first week is generated automatically once logging exists and the
          fortnight is done. Nothing to do until then.
        </p>
      </div>
    </>
  )
}
