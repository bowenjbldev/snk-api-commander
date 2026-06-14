import { useNowMs } from '../../lib/time'
import { absoluteUTC, relativeTime } from '../../lib/format'

/**
 * Relative timestamp ("3h ago") with a dotted underline hinting an
 * absolute-time tooltip ("2026-06-11 14:02 UTC"). Ticks live.
 */
export function RelativeTime({
  iso,
  mono,
}: {
  iso: string
  mono?: boolean
}) {
  const nowMs = useNowMs()
  return (
    <span
      className={`sc-rel${mono ? ' sc-mono' : ''}`}
      title={absoluteUTC(iso)}
    >
      {relativeTime(iso, nowMs)}
    </span>
  )
}
