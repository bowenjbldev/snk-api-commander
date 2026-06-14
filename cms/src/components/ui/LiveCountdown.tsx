import { useNowMs } from '../../lib/time'
import { countdownClock } from '../../lib/format'

/**
 * Big mono countdown to a deadline (HH:MM:SS), ticking live. Amber by default,
 * matching the grace state card. Reads 00:00:00 once the deadline passes.
 */
export function LiveCountdown({
  deadlineIso,
  size = 36,
  color = 'var(--sc-support-warning)',
}: {
  deadlineIso: string
  size?: number
  color?: string
}) {
  const nowMs = useNowMs()
  const remaining = Date.parse(deadlineIso) - nowMs
  return (
    <span
      className="sc-mono"
      role="timer"
      aria-live="off"
      style={{
        fontSize: size,
        fontWeight: 400,
        color,
        lineHeight: 1,
        letterSpacing: 0.5,
      }}
    >
      {countdownClock(remaining)}
    </span>
  )
}
