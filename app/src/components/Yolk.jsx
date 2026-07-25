/**
 * The mark, and the progress indicator. One object.
 *
 * A yolk is a filled circle inside a ring, which is already a progress ring —
 * so the logo, the onboarding progress, and the loading state are the same
 * component at different fill values. That is what earns the yellow: it
 * measures something rather than decorating.
 */
export default function Yolk({ pct = 0, size = 44, label }) {
  const clamped = Math.max(0, Math.min(100, pct))
  return (
    <span
      className="yolk"
      data-full={clamped >= 100 ? 'true' : 'false'}
      style={{ '--pct': clamped, '--size': `${size}px` }}
      role={label ? 'img' : 'presentation'}
      aria-label={label}
    />
  )
}
