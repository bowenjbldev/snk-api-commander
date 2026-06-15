// Time helpers. All timestamps are ISO strings (UTC). Relative formatting is
// anchored to a passed-in `nowMs` so countdowns and "3h ago" stay coherent with
// the demo dataset, which is built relative to app-load time (see fixtures).

const MIN = 60_000
const HOUR = 60 * MIN
const DAY = 24 * HOUR

export function msUntil(iso: string, nowMs: number): number {
  return new Date(iso).getTime() - nowMs
}

export function msSince(iso: string, nowMs: number): number {
  return nowMs - new Date(iso).getTime()
}

/** "3h ago", "2m ago", "just now", or "in 5h" for the future. */
export function formatRelative(iso: string, nowMs: number): string {
  const diff = nowMs - new Date(iso).getTime()
  const future = diff < 0
  const abs = Math.abs(diff)
  let out: string
  if (abs < 45 * 1000) out = 'just now'
  else if (abs < HOUR) out = `${Math.round(abs / MIN)}m`
  else if (abs < DAY) out = `${Math.round(abs / HOUR)}h`
  else out = `${Math.round(abs / DAY)}d`
  if (out === 'just now') return out
  return future ? `in ${out}` : `${out} ago`
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']

function pad(n: number, len = 2): string {
  return String(n).padStart(len, '0')
}

/** "2026-06-11 14:02 UTC" — used in the hover tooltip on relative times. */
export function formatAbsoluteUTC(iso: string): string {
  const d = new Date(iso)
  return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())} ${pad(
    d.getUTCHours(),
  )}:${pad(d.getUTCMinutes())} UTC`
}

/** "Jun 12, 2026 09:00 UTC" — deadlines, created dates. */
export function formatDateUTC(iso: string): string {
  const d = new Date(iso)
  return `${MONTHS[d.getUTCMonth()]} ${d.getUTCDate()}, ${d.getUTCFullYear()} ${pad(
    d.getUTCHours(),
  )}:${pad(d.getUTCMinutes())} UTC`
}

/** "Jun 12" — compact date for table cells. */
export function formatDayMonth(iso: string): string {
  const d = new Date(iso)
  return `${MONTHS[d.getUTCMonth()]} ${pad(d.getUTCDate())}`
}

/** "Mar 2024" — client "since" lines. */
export function formatMonthYear(iso: string): string {
  const d = new Date(iso)
  return `${MONTHS[d.getUTCMonth()]} ${d.getUTCFullYear()}`
}

/** "13h 42m" — grace tag in tables and dashboard. Clamps at 0. */
export function formatCountdownShort(ms: number): string {
  if (ms <= 0) return '0m'
  const totalMin = Math.floor(ms / MIN)
  const h = Math.floor(totalMin / 60)
  const m = totalMin % 60
  if (h >= 24) {
    const d = Math.floor(h / 24)
    return `${d}d ${h % 24}h`
  }
  if (h > 0) return `${h}h ${pad(m)}m`
  return `${m}m`
}

/** "13:42:10" — the big mono countdown on the detail state card. Clamps at 0. */
export function formatCountdownClock(ms: number): string {
  const clamped = Math.max(0, ms)
  const totalSec = Math.floor(clamped / 1000)
  const h = Math.floor(totalSec / 3600)
  const m = Math.floor((totalSec % 3600) / 60)
  const s = totalSec % 60
  return `${pad(h)}:${pad(m)}:${pad(s)}`
}
