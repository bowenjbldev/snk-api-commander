import type { CSSProperties } from 'react'
import { useTick } from '../providers/TickProvider'
import { formatAbsoluteUTC, formatRelative } from '../lib/time'

// "3h ago" with a dotted underline → absolute UTC time on hover. Live (ticks).
export function RelativeTime({ iso, className, style }: { iso: string; className?: string; style?: CSSProperties }) {
  const now = useTick()
  return (
    <span className={`scns-reltime ${className ?? ''}`} style={style} title={formatAbsoluteUTC(iso)}>
      {formatRelative(iso, now)}
    </span>
  )
}
