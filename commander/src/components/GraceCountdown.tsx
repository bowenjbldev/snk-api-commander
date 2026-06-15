import { useTick } from '../providers/TickProvider'
import { formatCountdownClock, formatCountdownShort, msUntil } from '../lib/time'

interface Props {
  until: string
  mode?: 'short' | 'clock'
}

// Live grace countdown. Subscribes only to the 1s tick — re-renders this leaf,
// not the surrounding table row. `short` → "13h 42m"; `clock` → big mono "13:42:10".
export function GraceCountdown({ until, mode = 'short' }: Props) {
  const now = useTick()
  const ms = msUntil(until, now)
  if (mode === 'clock') {
    return (
      <span
        className="scns-mono"
        style={{ fontSize: 36, fontWeight: 400, lineHeight: 1, color: 'var(--scns-amber-text)' }}
      >
        {formatCountdownClock(ms)}
      </span>
    )
  }
  return <>{formatCountdownShort(ms)}</>
}
