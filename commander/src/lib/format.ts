// Number / byte / currency formatting. Numeric table cells are right-aligned with
// tabular figures (see .scns-num).

const nf = new Intl.NumberFormat('en-US')

/** "1,240,332" */
export function formatInt(n: number): string {
  return nf.format(Math.round(n))
}

/** "1.66M", "138.4k", "412" — compact KPI/axis values. */
export function formatCompact(n: number): string {
  const abs = Math.abs(n)
  if (abs >= 1_000_000) return `${trim(n / 1_000_000)}M`
  if (abs >= 1_000) return `${trim(n / 1_000)}k`
  return String(Math.round(n))
}

function trim(n: number): string {
  // One decimal, but drop a trailing ".0".
  const s = n.toFixed(1)
  return s.endsWith('.0') ? s.slice(0, -2) : s
}

/** Bytes → "2.1 GB", "18.4 KB". Input is raw bytes. */
export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  const units = ['KB', 'MB', 'GB', 'TB']
  let val = bytes / 1024
  let i = 0
  while (val >= 1024 && i < units.length - 1) {
    val /= 1024
    i++
  }
  return `${trim(val)} ${units[i]}`
}

/** Megabytes → "8,920 MB" / "2.1 GB" for quota disk metrics. */
export function formatMb(mb: number): string {
  if (mb >= 1024) return `${trim(mb / 1024)} GB`
  return `${formatInt(mb)} MB`
}

const cf = new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD',
  minimumFractionDigits: 2,
})

/** "$520.00", "$3,420.00" */
export function formatCurrency(n: number): string {
  return cf.format(n)
}

/** "$3,420" — no cents, for MRR-style headline numbers. */
export function formatCurrencyShort(n: number): string {
  return `$${nf.format(Math.round(n))}`
}

/** Ratio 0..>1 → "124%", "83%". `digits` for sub-integer precision. */
export function formatPercent(ratio: number, digits = 0): string {
  return `${(ratio * 100).toFixed(digits)}%`
}
