import { useEffect, useRef, useState } from 'react'
import { api } from '../api'

/**
 * Friends (SPEC-coaching §10.1).
 *
 * A prerequisite for buddy pairing, not a social network. There is no feed, no profile to
 * open, no follower count. Two people connect and that unlocks training together, which is
 * the only reason the graph exists.
 *
 * THE BUTTON COMES FROM THE SERVER. Every search result carries a `relationship`, and this
 * renders from that rather than deciding for itself. Otherwise the client and the API each
 * hold an opinion about whether you can add someone, and they disagree at the edges — you
 * already asked, they already asked you, one of you blocked the other.
 *
 * Requests waiting on you come first. Someone is on the other end of those, which is not
 * true of anything else on this screen.
 */
export default function Friends({ onCount }) {
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(0)

  const load = () => api.friends.load()
    .then((r) => {
      setData(r.friends)
      // Report the count up so the nav badge clears the moment a request is answered,
      // rather than on the next boot.
      onCount?.(r.pending || 0)
    })
    .catch((e) => setError(e.message || 'Could not load your friends.'))

  useEffect(() => { load() }, [])

  async function act(userId, action) {
    setError(null)
    setBusy(userId)
    try {
      const r = await api.friends.act(userId, action)
      setData(r.friends)
      // The PATCH returns the lists but not the count, so derive it: an incoming request is
      // exactly what the badge counts.
      onCount?.((r.friends?.incoming || []).length)
    } catch (e) {
      setError(e.message || 'That did not work.')
      load()
    } finally {
      setBusy(0)
    }
  }

  if (error && data === null) return <p className="error">{error}</p>
  if (data === null) return <p className="small muted">Loading…</p>

  return (
    <div className="stack">
      {/*
        Waiting on you, first and loudest. A person is on the other end.
      */}
      {data.incoming.length > 0 && (
        <div className="card stack-sm">
          <h3 className="subheading">
            {data.incoming.length === 1
              ? 'Someone wants to connect'
              : `${data.incoming.length} people want to connect`}
          </h3>
          {data.incoming.map((p) => (
            <div className="prefrow" key={p.id}>
              <span className="prefrow-body">
                <span className="small" style={{ fontWeight: 600 }}>{p.display_name}</span>
                {/*
                  A little context for the decision, and only here. When they joined and
                  whether anyone you both know vouches for them is the difference between an
                  informed accept and a blind one. A count, never names.
                */}
                <span className="tiny muted">
                  {[
                    p.joined ? `joined ${monthYear(p.joined)}` : null,
                    p.mutuals > 0
                      ? `${p.mutuals} mutual friend${p.mutuals === 1 ? '' : 's'}`
                      : 'no mutual friends',
                  ].filter(Boolean).join(' · ')}
                </span>
              </span>
              <span className="row" style={{ gap: 6, flex: 'none' }}>
                <button
                  type="button"
                  className="btn btn--primary btn--small"
                  disabled={busy === p.id}
                  onClick={() => act(p.id, 'accept')}
                >
                  Accept
                </button>
                <button
                  type="button"
                  className="btn btn--quiet btn--small"
                  disabled={busy === p.id}
                  onClick={() => act(p.id, 'decline')}
                >
                  Ignore
                </button>
              </span>
            </div>
          ))}
        </div>
      )}

      <AddFriend onChanged={load} />

      <div className="card stack-sm">
        <h3 className="subheading">Your friends</h3>
        {data.friends.length === 0 ? (
          <p className="tiny muted prose" style={{ margin: 0 }}>
            Nobody yet. Adding someone here is what lets you train together.
          </p>
        ) : (
          data.friends.map((p) => (
            <div className="prefrow" key={p.id}>
              <span className="prefrow-body">
                <span className="small" style={{ fontWeight: 600 }}>{p.display_name}</span>
                <span className="tiny muted">{p.username}</span>
              </span>
              <button
                type="button"
                className="btn btn--quiet btn--small"
                disabled={busy === p.id}
                onClick={() => act(p.id, 'remove')}
              >
                Remove
              </button>
            </div>
          ))
        )}
      </div>

      {/* Asked and not yet answered. Quiet: nothing here needs doing. */}
      {data.outgoing.length > 0 && (
        <div className="card stack-sm">
          <h3 className="subheading">Waiting on them</h3>
          {data.outgoing.map((p) => (
            <div className="prefrow" key={p.id}>
              <span className="prefrow-body">
                <span className="small">{p.display_name}</span>
                <span className="tiny muted">Asked, no answer yet</span>
              </span>
              <button
                type="button"
                className="btn btn--quiet btn--small"
                disabled={busy === p.id}
                onClick={() => act(p.id, 'remove')}
              >
                Cancel
              </button>
            </div>
          ))}
        </div>
      )}

      {/* Only ever shown to the person who did the blocking. */}
      {data.blocked.length > 0 && (
        <div className="card stack-sm">
          <h3 className="subheading">Blocked</h3>
          {data.blocked.map((p) => (
            <div className="prefrow" key={p.id}>
              <span className="prefrow-body">
                <span className="small">{p.display_name}</span>
                <span className="tiny muted">Cannot find or contact you</span>
              </span>
              <button
                type="button"
                className="btn btn--quiet btn--small"
                disabled={busy === p.id}
                onClick={() => act(p.id, 'unblock')}
              >
                Unblock
              </button>
            </div>
          ))}
        </div>
      )}

      {error && <p className="error">{error}</p>}
    </div>
  )
}

/**
 * Finding someone.
 *
 * Debounced, and silent below three characters — the server returns nothing there anyway,
 * and firing a request per keystroke would burn the rate limit before anyone found anybody.
 */
function AddFriend({ onChanged }) {
  const [q, setQ] = useState('')
  const [results, setResults] = useState([])
  const [searched, setSearched] = useState(false)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(0)
  const timer = useRef(null)
  // Guards against an older, slower response landing after a newer one and overwriting it.
  const seq = useRef(0)

  useEffect(() => {
    clearTimeout(timer.current)
    const term = q.trim()
    if (term.length < 3) {
      setResults([])
      setSearched(false)
      return
    }
    timer.current = setTimeout(async () => {
      const mine = ++seq.current
      try {
        const r = await api.friends.search(term)
        if (mine !== seq.current) return
        setResults(r.results || [])
        setSearched(true)
        setError(null)
      } catch (e) {
        if (mine !== seq.current) return
        setError(e.message || 'Search failed.')
      }
    }, 350)
    return () => clearTimeout(timer.current)
  }, [q])

  async function add(userId) {
    setError(null)
    setBusy(userId)
    try {
      await api.friends.request(userId)
      // Re-run the search so the button reflects the new relationship, and refresh the lists
      // above so the outgoing request appears without a reload.
      const r = await api.friends.search(q.trim())
      setResults(r.results || [])
      onChanged?.()
    } catch (e) {
      setError(e.message || 'Could not send that.')
    } finally {
      setBusy(0)
    }
  }

  return (
    <div className="card stack-sm">
      <h3 className="subheading">Add a friend</h3>
      <label className="field">
        <span className="label">Their name, username, or full email</span>
        <input
          className="input"
          type="text"
          value={q}
          placeholder="ian"
          aria-label="Find someone"
          onChange={(e) => setQ(e.target.value)}
        />
      </label>

      {/*
        Says the email rule out loud, because it is the one thing that will surprise someone.
        Typing half an address and getting nothing looks broken unless you know it is
        deliberate.
      */}
      <p className="tiny muted prose" style={{ margin: 0 }}>
        Names and usernames match as you type. An email address has to be typed in full, so
        nobody can go fishing for who is on here.
      </p>

      {error && <p className="error tiny">{error}</p>}

      {searched && results.length === 0 && (
        <p className="tiny muted" style={{ margin: 0 }}>
          Nobody matched. Check the spelling, or ask them for their username.
        </p>
      )}

      {results.map((p) => (
        <div className="prefrow" key={p.id}>
          <span className="prefrow-body">
            <span className="small" style={{ fontWeight: 600 }}>{p.display_name}</span>
            <span className="tiny muted">{p.username}</span>
          </span>
          <Action p={p} busy={busy === p.id} onAdd={() => add(p.id)} />
        </div>
      ))}
    </div>
  )
}

/** The right control for the relationship, as the SERVER reported it. */
function Action({ p, busy, onAdd }) {
  if (p.relationship === 'friends') {
    return <span className="tiny muted">Already friends</span>
  }
  if (p.relationship === 'pending_out') {
    return <span className="tiny muted">Asked</span>
  }
  if (p.relationship === 'pending_in') {
    // They asked first. Tapping add accepts, which is what the server does with it.
    return (
      <button type="button" className="btn btn--primary btn--small" disabled={busy} onClick={onAdd}>
        Accept
      </button>
    )
  }
  return (
    <button type="button" className="btn btn--ghost btn--small" disabled={busy} onClick={onAdd}>
      {busy ? '…' : 'Add'}
    </button>
  )
}

/** "2026-03-14" -> "March 2026". A joined date is context, not a timestamp. */
function monthYear(iso) {
  const [y, m] = String(iso).split('-')
  const months = ['January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December']
  return `${months[Number(m) - 1] || ''} ${y}`.trim()
}
