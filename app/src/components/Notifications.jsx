import { useState } from 'react'
import { api } from '../api'

/**
 * Nudges and coach messages (SPEC-coaching §9).
 *
 * The design constraint is one line of scoping: "I hate noisy apps." So this renders
 * nothing at all most days, and what it does render is dismissible and never
 * repeated.
 *
 * Two distinct things live here, and the difference matters:
 *
 *   THE PASSIVE INDICATOR, at two quiet days. Not a message. A quiet line that states
 *   a fact and does not address the user. §9 puts a step before nudging on purpose:
 *   sending an actual message after two days is noise, but showing nothing wastes the
 *   one cheap signal available.
 *
 *   NUDGES AND MESSAGES, from three days on. Written in the user's chosen voice, and
 *   dismissible. Absence nudges address the SILENCE and never a bad day, which is why
 *   there is no notification type for a missed session: a logged bad day is a success
 *   and gets nothing.
 *
 * A drift question is a coach message rather than a nudge. It is the one that opens a
 * conversation, so it gets the notice treatment instead of a dismissible line.
 */
export default function Notifications({ data, onChanged }) {
  const [dismissing, setDismissing] = useState(null)

  const items = data?.notifications ?? []
  const passive = data?.show_passive && items.length === 0

  // Nothing to say. The common case, and the point.
  if (items.length === 0 && !passive) return null

  async function dismiss(id) {
    setDismissing(id)
    try {
      await api.notifications.read([id])
      onChanged?.()
    } catch {
      // A nudge that fails to dismiss is a nuisance, not an error worth a banner.
      // It will still be there next boot and the tap can be repeated.
    } finally {
      setDismissing(null)
    }
  }

  if (passive) {
    return (
      <p className="tiny muted passive-quiet">
        {/* States a fact, does not address the user. The difference between this and
            a nudge is the whole reason §9 has a step here. */}
        Nothing logged for {data.quiet_days} days.
      </p>
    )
  }

  return (
    <div className="stack-sm">
      {items.map((n) => (
        <div
          key={n.id}
          className={n.type === 'drift_question' ? 'notice small nudge' : 'card nudge'}
        >
          <div className="row">
            <p className="small prose" style={{ margin: 0 }}>{n.body}</p>
            <button
              type="button"
              className="btn btn--quiet push"
              aria-label="Dismiss"
              disabled={dismissing === n.id}
              onClick={() => dismiss(n.id)}
            >
              ×
            </button>
          </div>
        </div>
      ))}
    </div>
  )
}
