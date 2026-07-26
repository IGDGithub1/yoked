import { useEffect, useState } from 'react'
import { api } from '../api'

/**
 * The days a pair trains together (SPEC-coaching §10.1a, §10.3a, §10.3b).
 *
 * Only rendered for an ACTIVE pair. Three things happen here, in the order a user meets them:
 *
 *   1. The shared days, which is the concrete thing pairing delivers.
 *   2. A compromise prompt when the overlap is too thin to be a partnership (§10.3a). This is
 *      the screen that stops the app quietly generating two solo weeks and leaving both users
 *      to wonder why pairing did nothing.
 *   3. The surplus question when the shared days do not cover someone's commitment (§10.3b).
 *
 * WHAT THE COPY MAY NOT SAY. Generation is still per-user, so a shared day means "you are both
 * in the gym", never "you are doing the same workout". §10.6 is where that changes. Getting
 * this wrong is the failure mode a real pair notices within a week.
 *
 * The offered day is deliberately the OFFERER's own figures. Their grid says they cannot train
 * then, so the app has no other source for how long they could manage or where.
 */

const ACCESS_LABELS = {
  full_gym: 'Full gym',
  home_gym: 'Home gym',
  bodyweight: 'Bodyweight only',
  outdoors: 'Outdoors',
}

export default function BuddySchedule({ paired, onChanged }) {
  const [s, setS] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  const load = () => api.buddy.schedule()
    .then((r) => setS(r.schedule))
    .catch((e) => setError(e.message || 'Could not load your shared days.'))

  useEffect(() => {
    if (!paired) {
      setS(null)
      return
    }
    load()
  }, [paired])

  async function run(fn) {
    setError(null)
    setBusy(true)
    try {
      await fn()
      await load()
      // The pairing card shows the shared-day summary, so it needs re-reading too.
      onChanged?.()
    } catch (e) {
      setError(e.message || 'That did not work.')
      load()
    } finally {
      setBusy(false)
    }
  }

  if (!paired || s === null) return null

  const dayName = (wd) => s.day_names?.[wd] || `Day ${wd}`

  return (
    <div className="card stack-sm">
      <h3 className="subheading">Days you train together</h3>

      {s.agreed.length > 0 ? (
        <div className="stack-tight">
          {s.agreed.map((wd) => (
            <div className="prefrow" key={wd}>
              <span className="prefrow-body">
                <span className="small" style={{ fontWeight: 600 }}>{dayName(wd)}</span>
                {/*
                  Says whether this was a natural overlap or something one of them agreed to.
                  "You both had Wednesday free" reads differently from "you agreed to add
                  Wednesday", and a conceded day is the one worth revisiting if the pairing
                  stops working.
                */}
                <span className="tiny muted">
                  {s.overlap.includes(wd) ? 'You were both free' : 'One of you agreed to add it'}
                </span>
              </span>
              <button
                type="button"
                className="btn btn--quiet btn--small"
                disabled={busy}
                onClick={() => run(() => api.buddy.dropDay(wd))}
              >
                Drop
              </button>
            </div>
          ))}
        </div>
      ) : (
        <p className="tiny muted prose" style={{ margin: 0 }}>
          No shared days yet. Your available days do not overlap, so you are both training on
          your own for now.
        </p>
      )}

      {/*
        Where the two of you are actually meeting (§10.3).

        A pair trains in one place, and the app cannot know which one. When the grids disagree —
        one has a full gym, the other dumbbells at home — it assumes the better-equipped venue
        and the other person travelling, because that is the usual arrangement and because
        defaulting downward meant pairing could only ever cost somebody equipment.

        That assumption gets said out loud rather than acted on quietly. The app never asks
        WHERE: which gym and who drives are for the two of them to sort out.
      */}
      {(s.unconfirmed_access?.length ?? 0) > 0 && (
        <FacilityPrompt s={s} busy={busy} run={run} />
      )}

      {/*
        What a shared day actually means, stated once and plainly.

        This used to say only that you both have a session. That was all the app could honestly
        claim while generation was per-user. It now builds the two sessions to the same shape, so
        the copy says so — and still says what stays yours, because the other wrong assumption is
        that you will be lifting the same weights as someone months ahead of you.
      */}
      {s.agreed.length > 0 && (
        <p className="tiny muted prose" style={{ margin: 0 }}>
          On these days you get the same kind of session, in the same order, with the same core
          work, so you can train side by side. The weights and the reps are still yours.
        </p>
      )}

      {s.thin && <Compromise s={s} dayName={dayName} busy={busy} run={run} />}

      <Offers s={s} dayName={dayName} busy={busy} run={run} />

      {s.surplus?.needs_choice && (
        <Surplus s={s} busy={busy} run={run} />
      )}

      {error && <p className="error tiny">{error}</p>}
    </div>
  )
}

/**
 * The thin-overlap prompt (§10.3a).
 *
 * "Where the intersection is too thin to pair meaningfully, the app tells both users and asks
 * them to compromise." Offering a day means offering one of YOUR OWN free days that your buddy
 * cannot currently make — or a day neither of you has, if you are willing.
 */
function Compromise({ s, dayName, busy, run }) {
  const [open, setOpen] = useState(null)      // the weekday being offered
  const [minutes, setMinutes] = useState('')
  const [access, setAccess] = useState('')

  // Days worth offering: yours that they cannot make, then anything neither of you has. Days
  // already agreed are excluded, and so are days only THEY have — those are theirs to offer.
  const candidates = []
  for (let wd = 1; wd <= 7; wd++) {
    if (s.agreed.includes(wd)) continue
    if (s.theirs_only.includes(wd)) continue
    candidates.push(wd)
  }

  return (
    <div className="veto stack-sm">
      <p className="tiny" style={{ margin: 0, fontWeight: 600 }}>
        {s.agreed.length === 0
          ? 'Your weeks do not overlap at all.'
          : `You only share ${s.agreed.length} day${s.agreed.length === 1 ? '' : 's'}.`}
      </p>
      <p className="tiny muted prose" style={{ margin: 0 }}>
        {/*
          Names the number needed, because "compromise" without a target is a vague ask. It is
          the SMALLER of the two commitments: beyond that the other person's surplus is
          individual anyway.
        */}
        Training together works better with about {s.needed} shared day
        {s.needed === 1 ? '' : 's'}. If either of you can make another day work, offer it and
        the other can agree.
      </p>

      {open === null ? (
        <div className="chips" role="group" aria-label="Offer a day">
          {candidates.map((wd) => (
            <button
              key={wd}
              type="button"
              className="chip"
              disabled={busy}
              onClick={() => { setOpen(wd); setMinutes(''); setAccess('') }}
            >
              {dayName(wd)}
            </button>
          ))}
        </div>
      ) : (
        <div className="stack-sm">
          <p className="tiny" style={{ margin: 0 }}>
            Offering <strong>{dayName(open)}</strong>.
          </p>
          {/*
            The offerer's own figures. Their grid has nothing useful to say about a day they
            said they could not train, so asking is the only honest source.
          */}
          <label className="field">
            <span className="label">How long could you manage?</span>
            <select
              className="input"
              value={minutes}
              onChange={(e) => setMinutes(e.target.value)}
            >
              <option value="">Not sure</option>
              {[30, 45, 60, 75, 90, 120].map((m) => (
                <option key={m} value={m}>{m} minutes</option>
              ))}
            </select>
          </label>
          <label className="field">
            <span className="label">Where?</span>
            <select
              className="input"
              value={access}
              onChange={(e) => setAccess(e.target.value)}
            >
              <option value="">Not sure</option>
              {Object.entries(ACCESS_LABELS).map(([v, label]) => (
                <option key={v} value={v}>{label}</option>
              ))}
            </select>
          </label>
          <div className="row" style={{ gap: 6 }}>
            <button
              type="button"
              className="btn btn--primary btn--small"
              disabled={busy}
              onClick={() => run(async () => {
                await api.buddy.offerDay({
                  weekday: open,
                  minutes: minutes === '' ? undefined : Number(minutes),
                  access: access === '' ? undefined : access,
                })
                setOpen(null)
              })}
            >
              Offer it
            </button>
            <button
              type="button"
              className="btn btn--quiet btn--small"
              disabled={busy}
              onClick={() => setOpen(null)}
            >
              Cancel
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

/** Offers waiting, both directions. */
function Offers({ s, dayName, busy, run }) {
  const incoming = s.offers?.incoming ?? []
  const outgoing = s.offers?.outgoing ?? []
  if (incoming.length === 0 && outgoing.length === 0) return null

  return (
    <div className="stack-tight">
      {incoming.map((o) => (
        <div className="prefrow" key={o.id}>
          <span className="prefrow-body">
            <span className="small" style={{ fontWeight: 600 }}>
              They offered {dayName(o.weekday)}
            </span>
            <span className="tiny muted">
              {[
                o.minutes ? `${o.minutes} minutes` : null,
                o.access ? ACCESS_LABELS[o.access] : null,
              ].filter(Boolean).join(' · ') || 'No details given'}
            </span>
          </span>
          <span className="row" style={{ gap: 6, flex: 'none' }}>
            <button
              type="button"
              className="btn btn--primary btn--small"
              disabled={busy}
              onClick={() => run(() => api.buddy.actOnOffer(o.id, 'accept'))}
            >
              Agree
            </button>
            <button
              type="button"
              className="btn btn--quiet btn--small"
              disabled={busy}
              onClick={() => run(() => api.buddy.actOnOffer(o.id, 'decline'))}
            >
              No
            </button>
          </span>
        </div>
      ))}

      {outgoing.map((o) => (
        <div className="prefrow" key={o.id}>
          <span className="prefrow-body">
            <span className="small">You offered {dayName(o.weekday)}</span>
            <span className="tiny muted">No answer yet</span>
          </span>
          <button
            type="button"
            className="btn btn--quiet btn--small"
            disabled={busy}
            onClick={() => run(() => api.buddy.actOnOffer(o.id, 'withdraw'))}
          >
            Withdraw
          </button>
        </div>
      ))}
    </div>
  )
}

/**
 * The surplus question (§10.3b).
 *
 * Asked, never guessed. The three answers are all defensible and only the user knows which
 * they meant: someone who paired to get a lighter, more social week wants a different answer
 * from someone who paired for accountability and still wants their five days.
 *
 * Until they answer, the app keeps their original commitment — never silently less than they
 * asked for.
 */
/**
 * Where are you two actually meeting? (§10.3)
 *
 * Only shown when the two grids disagree about a shared day. If both said full gym there is
 * nothing to settle and this never appears.
 *
 * The app has already picked the better-equipped option and will train them there unless told
 * otherwise, so this reads as a confirmation rather than a blocking question. Nothing is
 * broken if they ignore it; they just get a guess instead of an answer, and the guess is the
 * arrangement most pairs land on anyway.
 *
 * Deliberately does NOT ask where. Which gym, whose garage, and who drives are things two
 * people sort out between themselves, and an app that collected addresses to prescribe a
 * dumbbell row would be asking for something it does not need.
 */
function FacilityPrompt({ s, busy, run }) {
  const labels = s.access_labels ?? {}

  return (
    /*
     * Named so a test can scope to it.
     *
     * The offer form further down has its own facility buttons with the same labels, so an
     * unscoped "click the home gym button" hits whichever renders first — which is how the
     * browser suite ended up settling nothing and reporting the surviving prompt as a bug.
     */
    <div className="veto stack-sm" data-testid="facility-prompt">
      <p className="tiny" style={{ margin: 0, fontWeight: 600 }}>
        {s.unconfirmed_access.length === 1
          ? 'Where are you training together?'
          : 'Where are you training on these days?'}
      </p>
      <p className="tiny muted prose" style={{ margin: 0 }}>
        You each said something different, so we have assumed the better-equipped option and
        that whoever has less gear travels. Sort out the details between you — we only need to
        know what kit will be there.
      </p>

      <div className="stack-tight">
        {s.unconfirmed_access.map((d) => (
          <div className="stack-tight" key={d.weekday}>
            <span className="small" style={{ fontWeight: 600 }}>
              {d.day}: {d.label}
            </span>
            <div className="row" style={{ gap: 6, flexWrap: 'wrap' }}>
              {/*
                Both users' own answers, and nothing else. Offering all four tiers would invite
                a pair to agree on a full gym neither of them has.
              */}
              {[...new Set([d.yours, d.theirs].filter(Boolean))].map((access) => (
                <button
                  key={access}
                  type="button"
                  className={
                    access === d.assumed
                      ? 'btn btn--primary btn--small'
                      : 'btn btn--quiet btn--small'
                  }
                  aria-pressed={access === d.assumed}
                  disabled={busy}
                  onClick={() => run(() => api.buddy.setDayAccess(d.weekday, access))}
                >
                  {labels[access] ?? access}
                </button>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}

function Surplus({ s, busy, run }) {
  const shared = s.agreed.length
  const surplus = s.surplus.surplus
  const stated = shared + surplus

  return (
    <div className="veto stack-sm">
      <p className="tiny" style={{ margin: 0, fontWeight: 600 }}>
        You train {stated} days a week, and {shared} of them are shared.
      </p>
      <p className="tiny muted prose" style={{ margin: 0 }}>
        What should happen with the other {surplus}?
      </p>

      <div className="stack-tight">
        {[
          {
            mode: 'keep_commitment',
            label: `Keep all ${stated}`,
            note: `${shared} with your buddy, ${surplus} on your own`,
          },
          {
            mode: 'extras_optional',
            label: `Commit to the ${shared} shared days`,
            note: 'The others are there if you want them, and never count against you',
          },
          {
            mode: 'match_buddy',
            label: `Just the ${shared} shared days`,
            note: 'A lighter week, matched to your buddy',
          },
        ].map((o) => (
          <button
            key={o.mode}
            type="button"
            className="profilelink"
            disabled={busy}
            onClick={() => run(() => api.buddy.setSurplus(o.mode))}
          >
            <span className="stack-tight">
              <span className="small" style={{ fontWeight: 600 }}>{o.label}</span>
              <span className="tiny muted">{o.note}</span>
            </span>
          </button>
        ))}
      </div>

      <p className="tiny muted prose" style={{ margin: 0 }}>
        You can change this whenever you like. Until you pick, you keep all {stated}.
      </p>
    </div>
  )
}
