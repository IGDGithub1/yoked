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
 * The controls that would fail are DISABLED with the reason in the title rather than hidden. A
 * greyed button teaches what the rule is; a missing one looks like a bug.
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

  // `.wrap` is what every other screen uses and what keeps the content off the left edge on a
  // desktop. Shell renders children bare, so a screen that forgets it spans the whole viewport.
  if (error && data === null) {
    return (
      <div className="wrap stack-lg">
        <p className="error">{error}</p>
      </div>
    )
  }
  if (data === null) return null

  const { members, admin_count: adminCount, me } = data

  return (
    <div className="wrap stack-lg">
      <div className="row" style={{ justifyContent: 'space-between', alignItems: 'baseline' }}>
        <h2 className="heading">Admin</h2>
        <button type="button" className="btn btn--quiet btn--small" onClick={onClose}>
          Done
        </button>
      </div>

      {error && <p className="error">{error}</p>}

      <div className="card stack-sm">
        <h3 className="subheading">
          Members <span className="sectioncount">· {members.length}</span>
        </h3>
        <div className="memberlist">
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
  // Stored UTC; the T and Z make the string parse the same in every browser.
  const days = Math.floor((Date.now() - new Date(iso.replace(' ', 'T') + 'Z')) / 86400000)
  if (days <= 0) return 'today'
  if (days === 1) return 'yesterday'
  if (days < 30) return `${days}d ago`
  return `${Math.floor(days / 30)}mo ago`
}

/** Joined: a date rather than a duration, because it does not change. */
function joined(iso) {
  if (!iso) return null
  const d = new Date(iso.replace(' ', 'T') + 'Z')
  return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })
}

const STATE_LABEL = {
  pending: 'quiz not started',
  in_progress: 'part-way through the quiz',
  baseline: 'in the baseline fortnight',
  active: 'active',
}

function Member({ m, isMe, lastAdmin, busy, run }) {
  const suspended = m.status === 'suspended'
  const initial = (m.display_name || m.username || '?').trim().charAt(0)

  return (
    <div className="memberrow" data-suspended={suspended} data-role={m.role}>
      {/*
        The avatar is the anchor that makes this a list rather than a run of text.
        Yoked stores no profile photos, so it is always an initial — but the column exists
        either way, and the eye tracks down it.
      */}
      <span className="memberavatar" aria-hidden="true">{initial}</span>

      <span className="memberwho">
        <span className="membername">
          <span className="small" style={{ fontWeight: 700 }}>{m.display_name}</span>
          <span className="tiny muted">@{m.username}</span>
          {m.role === 'admin' && <span className="tag">admin</span>}
          {isMe && <span className="tag">you</span>}
          {suspended && <span className="tag">suspended</span>}
        </span>
        {/*
          onboarding_state leads, because it answers the question an admin actually has:
          "why has this person done nothing" is usually "they never finished the quiz", and
          that used to need a database session.
        */}
        <span className="membermeta">
          {STATE_LABEL[m.onboarding_state] ?? m.onboarding_state}
          {joined(m.created_at) && ` · joined ${joined(m.created_at)}`}
          {' · '}seen {ago(m.last_seen_at)}
          {' · '}
          {m.plans} {m.plans === 1 ? 'plan' : 'plans'}
          {m.coaching_paused && ' · paused'}
        </span>
      </span>

      {/*
        Nothing to act on for yourself, so nothing is rendered.

        The disabled-with-a-reason treatment is right for a rule somebody might not know
        ("that is the last admin"); it is noise for the row that is obviously you.
      */}
      {!isMe && (
        <span className="memberacts">
          <button
            type="button"
            className="btn btn--quiet btn--small"
            /*
             * Demoting the last admin leaves the app with no administrator and no way back
             * short of SQL. The server refuses; this stops the click.
             */
            disabled={busy || (m.role === 'admin' && lastAdmin)}
            title={m.role === 'admin' && lastAdmin ? 'The only admin left' : undefined}
            onClick={() =>
              run(() => api.admin.setRole(m.username, m.role === 'admin' ? 'member' : 'admin'))
            }
          >
            {m.role === 'admin' ? 'demote' : 'make admin'}
          </button>

          <button
            type="button"
            className="btn btn--quiet btn--small"
            disabled={busy || (!suspended && lastAdmin)}
            title={!suspended && lastAdmin ? 'The only admin left' : undefined}
            onClick={() =>
              run(() => api.admin.setStatus(m.username, suspended ? 'active' : 'suspended'))
            }
          >
            {suspended ? 'reactivate' : 'suspend'}
          </button>
        </span>
      )}
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
      <h3 className="subheading">
        Invites <span className="sectioncount">· {open.length} open</span>
      </h3>
      <p className="tiny muted prose" style={{ margin: 0 }}>
        There is no self-serve sign-up, so a code is the only way in. Send it however you like —
        the app does not email.
      </p>

      <div className="row" style={{ gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
        <label className="field" style={{ maxWidth: '9em' }}>
          {/* `.label` and not `.tiny`: .field styles its label as a block, and an inline
              span sits beside the select instead of above it. */}
          <span className="label">Valid for</span>
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
        The new code is shown large and copyable rather than left to be found in the list.
        It is the one thing the admin came here for, and hunting for it among the others is
        exactly the moment a code gets mis-transcribed.
      */}
      {minted && (
        <div className="veto stack-tight">
          <span className="tiny muted">New code</span>
          <span className="codeout">{minted}</span>
          <div className="row" style={{ gap: 6 }}>
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
            <button
              type="button"
              className="btn btn--quiet btn--small"
              onClick={() => setMinted(null)}
            >
              Dismiss
            </button>
          </div>
        </div>
      )}

      <div className="memberlist">
        {open.length === 0 && (
          <p className="tiny muted" style={{ margin: 0 }}>
            No unused codes.
          </p>
        )}
        {open.map((i) => (
          <div className="memberrow memberrow--code" key={i.code}>
            <span className="memberwho">
              <span className="small num" style={{ fontWeight: 600 }}>{i.code}</span>
              <span className="membermeta">
                {i.expires_at ? `expires ${i.expires_at.slice(0, 10)}` : 'no expiry'}
                {i.created_by && ` · from ${i.created_by}`}
              </span>
            </span>
            <span className="memberacts">
              <button
                type="button"
                className="btn btn--quiet btn--small"
                disabled={busy}
                onClick={() => run(() => api.admin.revokeInvite(i.code))}
              >
                revoke
              </button>
            </span>
          </div>
        ))}
      </div>

      {/*
        Used and expired codes stay listed and are NOT revocable. A used invite is the only
        record of who let a given person in, so deleting it erases that.
      */}
      {rest.length > 0 && (
        <details>
          <summary className="tiny muted">{rest.length} used or expired</summary>
          <div className="memberlist" style={{ marginTop: 8 }}>
            {rest.map((i) => (
              <div className="memberrow memberrow--code" key={i.code}>
                <span className="memberwho">
                  <span className="small num muted">{i.code}</span>
                  <span className="membermeta">
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
