/**
 * The day's four numbers, as rings, at the top of the Journal.
 *
 * They were a right-aligned text line inside the Food section header — "1935 / 2400 kcal"
 * over "P 106/180g F 50/80g net C 156/220g". Accurate, complete, and unreadable at a
 * glance: six numbers and three slashes, in a place you had to scroll to. "How am I doing
 * today" is the question this screen exists to answer and it was answered in data.
 *
 * ONE RING CARRIES THE ACCENT, and it is calories. DESIGN.md is explicit — one yellow
 * element per view, and the yolk earns it by MEASURING rather than decorating. Four
 * coloured rings is what the source app does and it is the right call there; here it would
 * put four accents on a screen whose whole palette discipline is one. So calories get the
 * yolk, because that is the number with a target everyone recognises, and the three macros
 * are drawn in ink at low opacity: still rings, still filling, no competing hues.
 *
 * NO VERDICTS. A ring past its target is drawn full and says so in the label; it does not
 * turn red. The server owns judgment and only forms one for a day that is over, and a
 * mid-afternoon "over on fat" is the exact scolding the drift rules exist to avoid.
 */
export default function Macros({ totals, target }) {
  const t = totals || {}
  const g = target || null

  return (
    <div className="macrorings" role="group" aria-label="Today's totals">
      <Ring
        label="kcal"
        value={t.calories}
        target={g?.calories}
        accent
        round
      />
      <Ring label="protein" short="P" value={t.protein} target={g?.protein} />
      <Ring label="fat" short="F" value={t.fat} target={g?.fat} />
      {/* "net C", not "C". Carbs are ambiguous to anyone actually counting them and the
          whole intake model turns on net versus total — the server derives net at intake,
          so a ring labelled "C" would be quietly claiming to be the total. */}
      <Ring label="net carbs" short="net C" value={t.carbs} target={g?.carbs} />
    </div>
  )
}

/**
 * One ring.
 *
 * A conic-gradient on a masked circle rather than an SVG: it is one element, it animates
 * the same custom property the yolk does, and there is no viewBox to keep in step with the
 * size. The number sits inside, because a ring with its value beside it is two things to
 * read instead of one.
 */
function Ring({ label, short, value, target, accent = false, round = false }) {
  const v = Number(value) || 0
  const has = target != null && Number(target) > 0
  const pct = has ? Math.min(100, (v / Number(target)) * 100) : 0
  const over = has && v > Number(target)

  const shown = round ? Math.round(v) : Math.round(v * 10) / 10
  const goal = has ? (round ? Math.round(Number(target)) : Math.round(Number(target))) : null

  /*
   * The accessible name says the whole fact, because the ring itself is a shape and the
   * digits inside it are the total alone. A screen reader getting "106" from a screen that
   * visually reads "106 of 180g protein" is the same class of omission as a badge count
   * that never reaches the accessibility tree.
   */
  const spoken = has
    ? `${shown} of ${goal} ${label}${over ? ', over target' : ''}`
    : `${shown} ${label}, no target yet`

  return (
    <div className="macroring" data-accent={accent ? 'true' : 'false'}>
      {/*
        The dial and the number are SIBLINGS inside a positioned wrapper, not parent and
        child. The ring is cut out of the dial with a mask, and a mask applies to the whole
        subtree — a number inside it gets its middle eaten along with the dial's.
      */}
      <div className="macroring-face">
        <div
          className="macroring-dial"
          style={{ '--pct': pct }}
          data-over={over ? 'true' : 'false'}
          role="img"
          aria-label={spoken}
        />
        <span className="macroring-num num" aria-hidden="true">{shown}</span>
      </div>
      {/*
        The target under the ring, not inside it. Two numbers in a 56px circle is smaller
        than either of them deserves, and the total is the one being watched.
      */}
      <span className="macroring-label tiny muted" aria-hidden="true">
        {short || label}
        {has ? <span className="num"> / {goal}</span> : null}
      </span>
    </div>
  )
}
