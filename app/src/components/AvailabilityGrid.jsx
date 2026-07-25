import { DAYS, ACCESS } from '../questions'

/**
 * The weekly availability grid.
 *
 * Access is a property of a DAY, not of a person. "Full gym at work Monday to
 * Friday, bodyweight at home on weekends" is completely ordinary, and a single
 * global setting flattens it — then prescribes barbell work on a Saturday. So
 * every day carries its own access control.
 *
 * The committed count is derived here rather than asked as a separate question:
 * two sources for one fact drift apart.
 */
export default function AvailabilityGrid({ value, onChange }) {
  const grid = value && typeof value === 'object' ? value : {}

  const day = (n) => grid[String(n)] || {}
  const set = (n, patch) =>
    onChange({ ...grid, [String(n)]: { ...day(n), ...patch } })

  const committed = DAYS.filter((d) => day(d.n).can_train === 'yes').length

  return (
    <div className="stack">
      <div className="grid">
        <div className="grid-head" aria-hidden="true">
          <span>Day</span>
          <span>Can you train?</span>
          <span>Minutes</span>
          <span>Access</span>
        </div>

        {DAYS.map((d) => {
          const row = day(d.n)
          const off = row.can_train === 'no' || row.can_train === undefined
          return (
            <div className="grid-row" key={d.n}>
              <span className={`day${off ? ' day--off' : ''}`}>{d.short}</span>

              <div className="seg" role="radiogroup" aria-label={`${d.long}: can you train?`}>
                {[
                  ['yes', 'Yes'],
                  ['sometimes', 'Some'],
                  ['no', 'No'],
                ].map(([v, label]) => (
                  <button
                    key={v}
                    type="button"
                    role="radio"
                    aria-checked={row.can_train === v}
                    onClick={() =>
                      set(d.n, {
                        can_train: v,
                        // Clearing these on "no" keeps the payload honest —
                        // minutes on a day you are not training is noise the
                        // generator would have to ignore.
                        ...(v === 'no' ? { minutes: null, access: null } : {}),
                      })
                    }
                  >
                    {label}
                  </button>
                ))}
              </div>

              <span className="mins">
                {off ? (
                  <span className="muted" aria-label="Not training">—</span>
                ) : (
                  <input
                    className="input num mins-input"
                    type="number"
                    inputMode="numeric"
                    step="5"
                    min="0"
                    placeholder="60"
                    value={row.minutes ?? ''}
                    onChange={(e) =>
                      set(d.n, { minutes: e.target.value === '' ? null : Number(e.target.value) })
                    }
                    aria-label={`${d.long}: minutes available`}
                  />
                )}
              </span>

              <select
                className="select"
                value={row.access ?? ''}
                disabled={off}
                onChange={(e) => set(d.n, { access: e.target.value || null })}
                aria-label={`${d.long}: what do you have access to?`}
              >
                <option value="">{off ? 'Not training' : 'Choose…'}</option>
                {ACCESS.map((a) => (
                  <option key={a.value} value={a.value}>
                    {a.label}
                  </option>
                ))}
              </select>
            </div>
          )
        })}
      </div>

      <div className="notice">
        {committed === 0 ? (
          <>Mark at least one day <strong>Yes</strong> to set your committed week.</>
        ) : (
          <>
            <strong>
              {committed} committed {committed === 1 ? 'day' : 'days'}
            </strong>{' '}
            . That is your week. Hit those and the week is a success, even if you skip
            everything else. Anything extra is a bonus and never counts against you.
          </>
        )}
      </div>

      <p className="tiny muted prose" style={{ margin: 0 }}>
        <strong>Some</strong> means the day is usable but not promised. Your coach
        puts the session it can most afford to lose there.
      </p>
    </div>
  )
}
