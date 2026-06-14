// Pure formatting helpers. All time-relative helpers take an explicit `nowMs`
// so callers can pass the ticking simulated clock (useNowMs) for live updates.

const MONTHS = [
  'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
  'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
]

const pad = (n: number) => String(n).padStart(2, '0')

// --- numbers -------------------------------------------------------------
export function fmtInt(n: number): string {
  return n.toLocaleString('en-US')
}

/** Compact: 1.66M / 138.4k / 412. */
export function fmtCompact(n: number): string {
  if (n >= 1_000_000) return trimZero(n / 1_000_000) + 'M'
  if (n >= 1_000) return trimZero(n / 1_000) + 'k'
  return String(Math.round(n))
}
function trimZero(v: number): string {
  const s = (Math.round(v * 10) / 10).toFixed(1)
  return s.endsWith('.0') ? s.slice(0, -2) : s
}

/** Percentage from a 0..n fraction: 0.83 -> "83%", 1.24 -> "124%". */
export function pct(fraction: number): string {
  return Math.round(fraction * 100) + '%'
}

export function fmtUSD(amount: number, opts: { cents?: boolean } = {}): string {
  return (
    '$' +
    amount.toLocaleString('en-US', {
      minimumFractionDigits: opts.cents ? 2 : 0,
      maximumFractionDigits: opts.cents ? 2 : 0,
    })
  )
}

/** Log byte label: 1.2K / 18.4K / 2.1MB. */
export function fmtLogBytes(bytes: number): string {
  if (bytes >= 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + 'MB'
  return (bytes / 1024).toFixed(1) + 'K'
}

/** Big byte label: 2.1GB / 780MB. */
export function fmtBigBytes(bytes: number): string {
  const gb = bytes / 1024 ** 3
  if (gb >= 1) return trimZero(gb) + 'GB'
  return Math.round(bytes / 1024 ** 2) + 'MB'
}

// --- dates ---------------------------------------------------------------
export function dayMonth(iso: string): string {
  const d = new Date(iso)
  return `${MONTHS[d.getUTCMonth()]} ${pad(d.getUTCDate())}`
}

export function monthYear(iso: string): string {
  const d = new Date(iso)
  return `${MONTHS[d.getUTCMonth()]} ${d.getUTCFullYear()}`
}

/** "Jun 12 03:44 UTC". */
export function dateTimeUTC(iso: string): string {
  const d = new Date(iso)
  return `${MONTHS[d.getUTCMonth()]} ${pad(d.getUTCDate())} ${pad(
    d.getUTCHours(),
  )}:${pad(d.getUTCMinutes())} UTC`
}

/** Absolute tooltip: "2026-06-11 14:02 UTC". */
export function absoluteUTC(iso: string): string {
  const d = new Date(iso)
  return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(
    d.getUTCDate(),
  )} ${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())} UTC`
}

/** Full precision incl. seconds — used in the log detail panel. */
export function absoluteUTCSeconds(iso: string): string {
  const d = new Date(iso)
  const ms = pad(d.getUTCMilliseconds())
  return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(
    d.getUTCDate(),
  )} ${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}:${pad(
    d.getUTCSeconds(),
  )}.${String(d.getUTCMilliseconds()).padStart(3, '0').slice(0, 3) || ms}`
}

/** HH:MM:SS.mmm clock label used in the log table. */
export function clockMs(iso: string): string {
  const d = new Date(iso)
  return `${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}:${pad(
    d.getUTCSeconds(),
  )}.${String(d.getUTCMilliseconds()).padStart(3, '0')}`
}

// --- relative / countdown ------------------------------------------------
/** "3h ago" / "8s ago" / "2d ago" / "in 13h 42m". */
export function relativeTime(iso: string, nowMs: number): string {
  const deltaMs = nowMs - Date.parse(iso)
  const past = deltaMs >= 0
  const abs = Math.abs(deltaMs)
  const s = Math.floor(abs / 1000)
  const m = Math.floor(s / 60)
  const h = Math.floor(m / 60)
  const d = Math.floor(h / 24)
  let body: string
  if (s < 60) body = `${s}s`
  else if (m < 60) body = `${m}m`
  else if (h < 24) body = `${h}h`
  else body = `${d}d`
  return past ? `${body} ago` : `in ${countdownShort(abs)}`
}

/** "13h 42m" / "61h 08m" / "42m". */
export function countdownShort(ms: number): string {
  const total = Math.max(0, Math.floor(ms / 1000))
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  if (h > 0) return `${h}h ${pad(m)}m`
  const s = total % 60
  return `${m}m ${pad(s)}s`
}

/** "13:42:10" big mono countdown (hours uncapped). */
export function countdownClock(ms: number): string {
  const total = Math.max(0, Math.floor(ms / 1000))
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  return `${pad(h)}:${pad(m)}:${pad(s)}`
}

/** Whole days an invoice is past its due date (>=0). */
export function daysOverdue(dueIso: string, nowMs: number): number {
  const delta = nowMs - Date.parse(dueIso)
  return Math.max(0, Math.floor(delta / (24 * 3600 * 1000)))
}
