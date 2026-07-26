import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '../api'
import Yolk from '../components/Yolk'

/**
 * The conversation (SPEC-coaching §6).
 *
 * Its own view rather than a card, for two reasons. A transcript inside the Dashboard is
 * exactly the accumulation the Dashboard/Journal split was meant to stop, and a
 * conversation deserves the screen.
 *
 * THE REPLY IS NOT INSTANT, and the UI has to be honest about that. Evaluating a message
 * is a model call, and one that ends in a plan revision takes minutes — so sending returns
 * immediately, the turn appears straight away, and this polls. A spinner that lies about
 * how long something takes is worse than one that says "your coach is thinking".
 *
 * What the user types NEVER edits the plan (§6.1). The copy reflects that: nothing here
 * promises a change, because only the coach's decision produces one.
 */
export default function Coach() {
  const [turns, setTurns] = useState([])
  const [pending, setPending] = useState(false)
  const [draft, setDraft] = useState('')
  const [status, setStatus] = useState('loading')
  const [error, setError] = useState(null)
  const [sending, setSending] = useState(false)
  const endRef = useRef(null)

  const load = useCallback(async () => {
    try {
      const r = await api.chat.load()
      setTurns(r.turns || [])
      setPending(Boolean(r.pending))
      setStatus('ready')
    } catch (e) {
      setError(e.message || 'Could not load the conversation.')
      setStatus('error')
    }
  }, [])

  useEffect(() => { load() }, [load])

  /*
   * Poll only while a reply is outstanding.
   *
   * Not a constant interval: a conversation nobody is waiting on does not need checking
   * every few seconds, and cron runs on a fifteen-minute cadence anyway. Eight seconds is
   * frequent enough to feel responsive when something IS coming, and stops entirely when
   * nothing is.
   */
  useEffect(() => {
    if (!pending) return
    const id = setInterval(load, 8000)
    return () => clearInterval(id)
  }, [pending, load])

  // Scroll to the newest turn, as a transcript should.
  useEffect(() => {
    endRef.current?.scrollIntoView({ block: 'end' })
  }, [turns.length, pending])

  async function send(e) {
    e.preventDefault()
    const message = draft.trim()
    if (!message) return

    setError(null)
    setSending(true)
    try {
      const r = await api.chat.send(message)
      setDraft('')
      setTurns(r.turns || [])
      setPending(true)
    } catch (err) {
      setError(err.message || 'That did not send. Try again.')
    } finally {
      setSending(false)
    }
  }

  if (status === 'loading') {
    return (
      <div className="wrap" style={{ textAlign: 'center', padding: '48px 20px' }}>
        <Yolk pct={45} size={40} label="Loading" />
      </div>
    )
  }

  return (
    <div className="wrap stack">
      <div>
        <p className="eyebrow">Your coach</p>
        <h1 className="heading">Anything I should know?</h1>
        {/*
          What this is FOR, in one line. §6.2's distinction is real and a user who does not
          know it will be surprised by a decline, so the invitation names the useful case:
          facts about reality, not requests.
        */}
        <p className="small muted prose" style={{ margin: '6px 0 0' }}>
          Tell me what changed and I will work around it. Travel, a bad night's sleep,
          something that hurts.
        </p>
      </div>

      {turns.length === 0 && !pending && (
        <p className="small muted" style={{ margin: 0 }}>
          Nothing yet. Whenever something comes up.
        </p>
      )}

      <div className="stack-sm">
        {turns.map((t) => <Turn key={t.id} turn={t} />)}

        {/* Honest about the wait rather than pretending it is instant. */}
        {pending && (
          <div className="turn turn--coach turn--thinking">
            <p className="small muted" style={{ margin: 0 }}>
              Your coach is thinking about this. It can take a few minutes.
            </p>
          </div>
        )}

        <div ref={endRef} />
      </div>

      {error && <p className="error">{error}</p>}

      <form className="stack-sm" onSubmit={send}>
        <textarea
          className="textarea"
          rows="3"
          placeholder="Travelling Monday to Thursday, no gym"
          aria-label="Tell your coach something"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
        />
        <div className="row">
          <button type="submit" className="btn btn--primary" disabled={sending || !draft.trim()}>
            {sending ? 'Sending…' : 'Send'}
          </button>
        </div>
      </form>
    </div>
  )
}

/**
 * One turn.
 *
 * Three visual cases, and the third is the one worth having: a question the COACH opened
 * (a drift question) is not the same as a reply to something the user said. Reading a
 * transcript where those look identical, a user cannot tell what is being asked of them.
 */
function Turn({ turn }) {
  const mine = turn.role === 'user'
  const raised = !mine && turn.drift !== null

  return (
    <div
      className={mine ? 'turn turn--mine' : 'turn turn--coach'}
      data-raised={raised ? 'true' : undefined}
    >
      {raised && <p className="eyebrow" style={{ margin: '0 0 4px' }}>Your coach asked</p>}

      <p className="small prose" style={{ margin: 0, whiteSpace: 'pre-wrap' }}>
        {turn.body}
      </p>

      {/* Only when it actually happened. A plan that changed without a word is the kind of
          surprise that costs trust, and one claimed but not made is worse. */}
      {turn.plan_changed && (
        <p className="tiny" style={{ margin: '6px 0 0', fontWeight: 600 }}>
          Your week was updated.
        </p>
      )}

      {/* The decline case, named. A user who is told no should be able to see that it was
          a decision rather than a misunderstanding. */}
      {turn.outcome === 'declined' && (
        <p className="tiny muted" style={{ margin: '6px 0 0' }}>
          Nothing changed in your plan.
        </p>
      )}
    </div>
  )
}
