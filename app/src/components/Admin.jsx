import { useEffect, useState } from 'react'
import { api } from '../api'

/**
 * The admin screen: who is here, and who can get in.
 *
 * Yoked is invite-only with no self-serve registration, so somebody has to issue the codes.
 * That was a CLI job and two API routes with no screen.
 *
 * READ-MOSTLY BY DESIGN. There is no delete: the cascade from a user reaches their plans,
 * logged days, photos, buddy pairs and check-ins, with no undo. Suspension is reversible and
 * covers the real case, which is "stop this account working" rather than "erase the person".
 *
 * The destructive-adjacent controls are disabled rather than hidden when they would fail — a
 * greyed button with a reason teaches what the rule is, where a missing one just looks like a
 * bug.
 */
export default function Admin({ onClose }) {
  const [data, setData] = useState(null)
  const [invites, setInvites] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  const load = () =>
    Promise.all([api.admin.members(), api.admin.invites()])
      .then(([m, i]) => {
        setData(m)
        setInvites(i.invites)
      })
      .catch((e) => setError(e.message || 'Could not load that.'))

  useEffect(() => {
    load()
  }, [])

  async function run(fn) {
    setError(null)
    setBusy(true)
    try {
      await fn()
      await load()
    } catch (e) {
      setError(e.message || 'That did not work.')
    } finally {
      setBusy(false)
    }
  }

  if (error && data === null) return <p className="error">{error}</p>
  if (data === null) return null

  const { members, admin_count: adminCount, me } = data

  return (
    <div className="stack">
      <div className="row" style={{ justifyContent: 'space-between', alignItems: 'baseline' }}>
        <h2 className="heading">Admin</h2>
        <button type="button" className="btn btn--quiet btn--small" onClick={onClose}>
          Done
        </button>
      </div>

      {error && <p className="error">{error}</p>}

      <div className="card stack-sm">
        <h3 className="subheading">Members</h3>
        <div className="stack-tight">
          {members.map((m) => (
            <Member
              key={m.id}
              m={m}
              isMe={m.id === me}
              lastAdmin={m.role === 'admin' && m.status === 'active' && adminCount <= 1}
              busy={busy}
              run={run}
            />
          ))}
        </div>
      </div>

      <Invites list={invites ?? []} busy={busy} run={run} />
    </div>
  )
}

/** How long ago, in the roughest useful terms. */
function ago(iso) {
  if (!iso) return 'never'
  const days = Math.floor((Date.now() - new Date(iso.replace(' ', 'T') + 'Z')) / 86400000)
  if (days <= 0) return 'today'
  if (days === 1) return 'yesterday'
  if (days < 30) return `${days} days ago`
  return `${Math.floor(days / 30)} months ago`
}

const STATE_LABEL = {
  pending: 'has not started the quiz',
  in_progress: 'part-way through the quiz',
  baseline: 'in the baseline fortnight',
  active: 'active',
}

function Member({ m, isMe, lastAdmin, busy, run }) {
  const suspended = m.status === 'suspended'

  /*
   * Stacked, not a prefrow.
   *
   * prefrow puts its body and controls on one line, which works for a label plus one switch.
   * Two buttons beside a three-line description overflowed a 390px viewport and clipped
   * "Suspend" off the right edge — the sideways scroll the browser suite exists to catch.
   */
  return (
    <div className="stack-tight" style={{ paddingBlock: 8 }}>
      {/* gap rather than whitespace between the name and its tags: JSX collapses the
          space before an element, so "@OO7Ian" ran straight into "admin". */}
      <span
        className="small row"
        style={{ fontWeight: 600, gap: 6, flexWrap: 'wrap', alignItems: 'baseline' }}
      >
        <span>
          {m.display_name} <span className="muted">@{m.username}</span>
        </span>
        {m.role === 'admin' && <span className="tag">admin</span>}
        {suspended && <span className="tag">suspended</span>}
      </span>
      {/*
        onboarding_state is the most useful column here: it answers "why has this person done
        nothing" without a database session. Someone at 'pending' never answered the quiz.
      */}
      <span className="tiny muted">
        {STATE_LABEL[m.onboarding_state] ?? m.onboarding_state}
        {' · '}last seen {ago(m.last_seen_at)}
        {' · '}
        {m.plans} {m.plans === 1 ? 'plan' : 'plans'}, {m.logged_days} logged
        {m.coaching_paused && ' · coaching paused'}
      </span>

      <div className="row" style={{ gap: 6, flexWrap: 'wrap' }}>
        <button
          type="button"
          className="btn btn--quiet btn--small"
          /*
           * Disabled rather than hidden, with the reason in the title.
           *
           * Demoting yourself breaks the screen you are standing on, and demoting the last
           * admin leaves the app with no way back in short of SQL. The server refuses both;
           * this stops the click happening at all.
           */
          disabled={busy || (m.role === 'admin' && (isMe || lastAdmin))}
          title={
            m.role === 'admin' && isMe
              ? 'You cannot remove your own admin access'
              : m.role === 'admin' && lastAdmin
                ? 'The only admin left'
                : undefined
          }
          onClick={() =>
            run(() => api.admin.setRole(m.username, m.role === 'admin' ? 'member' : 'admin'))
          }
        >
          {m.role === 'admin' ? 'Make member' : 'Make admin'}
        </button>

        <button
          type="button"
          className="btn btn--quiet btn--small"
          disabled={busy || (!suspended && (isMe || lastAdmin))}
          title={
            !suspended && isMe
              ? 'You cannot suspend yourself'
              : !suspended && lastAdmin
                ? 'The only admin left'
                : undefined
          }
          onClick={() =>
            run(() => api.admin.setStatus(m.username, suspended ? 'active' : 'suspended'))
          }
        >
          {suspended ? 'Reactivate' : 'Suspend'}
        </button>
      </div>
    </div>
  )
}

function Invites({ list, busy, run }) {
  const [days, setDays] = useState(30)
  const [minted, setMinted] = useState(null)
  const [copied, setCopied] = useState(false)

  const open = list.filter((i) => i.state === 'open')
  const rest = list.filter((i) => i.state !== 'open')

  return (
    <div className="card stack-sm">
      <h3 className="subheading">Invites</h3>
      <p className="tiny muted prose" style={{ margin: 0 }}>
        There is no self-serve sign-up, so a code is the only way in. Send it however you like —
        the app does not email.
      </p>

      <div className="row" style={{ gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
        <label className="field" style={{ maxWidth: '9em' }}>
          <span className="tiny muted">Valid for</span>
          <select
            className="input"
            value={days}
            disabled={busy}
            onChange={(e) => setDays(Number(e.target.value))}
          >
            {[7, 14, 30, 90, 365].map((d) => (
              <option key={d} value={d}>
                {d} days
              </option>
            ))}
          </select>
        </label>
        <button
          type="button"
          className="btn btn--primary btn--small"
          disabled={busy}
          onClick={() =>
            run(async () => {
              const r = await api.admin.mintInvite(days)
              setMinted(r.code)
              setCopied(false)
            })
          }
        >
          Generate a code
        </button>
      </div>

      {/*
        The new code is shown large and copyable rather than dropped into the list below.
        It is the one thing the admin came here for, and hunting for it among the others is
        exactly the moment a code gets mis-transcribed.
      */}
      {minted && (
        <div className="veto stack-tight">
          <span className="tiny muted">New code</span>
          <span className="num" style={{ fontSize: '1.3rem', letterSpacing: '0.08em' }}>
            {minted}
          </span>
          <button
            type="button"
            className="btn btn--quiet btn--small"
            onClick={() => {
              navigator.clipboard?.writeText(minted)
              setCopied(true)
            }}
          >
            {copied ? 'Copied' : 'Copy'}
          </button>
        </div>
      )}

      <div className="stack-tight">
        {open.length === 0 && (
          <p className="tiny muted" style={{ margin: 0 }}>
            No unused codes.
          </p>
        )}
        {open.map((i) => (
          <div className="prefrow" key={i.code}>
            <span className="prefrow-body">
              <span className="small num">{i.code}</span>
              <span className="tiny muted">
                {i.expires_at ? `expires ${i.expires_at.slice(0, 10)}` : 'no expiry'}
                {i.created_by && ` · from ${i.created_by}`}
              </span>
            </span>
            <button
              type="button"
              className="btn btn--quiet btn--small"
              disabled={busy}
              onClick={() => run(() => api.admin.revokeInvite(i.code))}
            >
              Revoke
            </button>
          </div>
        ))}
      </div>

      {/*
        Used and expired codes stay listed and are NOT revocable. A used invite is the only
        record of who let a given person in, so deleting it erases that.
      */}
      {rest.length > 0 && (
        <details>
          <summary className="tiny muted">
            {rest.length} used or expired
          </summary>
          <div className="stack-tight" style={{ marginTop: 8 }}>
            {rest.map((i) => (
              <div className="prefrow" key={i.code}>
                <span className="prefrow-body">
                  <span className="small num muted">{i.code}</span>
                  <span className="tiny muted">
                    {i.state === 'used'
                      ? `used by ${i.used_by ?? 'someone'}${
                          i.used_at ? ` on ${i.used_at.slice(0, 10)}` : ''
                        }`
                      : 'expired'}
                  </span>
                </span>
              </div>
            ))}
          </div>
        </details>
      )}
    </div>
  )
}
