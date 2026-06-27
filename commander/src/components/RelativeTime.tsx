import type { CSSProperties } from 'react'
import { useTick } from '../providers/TickProvider'
import { formatAbsoluteUTC, formatRelative } from '../lib/time'

// "3h ago" with a dotted underline → absolute UTC time on hover. Live (ticks).
export function RelativeTime({ iso, style }: { iso: string; style?: CSSProperties }) {
  const now = useTick()
  return (
    <span className="scns-reltime" style={style} title={formatAbsoluteUTC(iso)}>
      {formatRelative(iso, now)}
    </span>
  )
}
