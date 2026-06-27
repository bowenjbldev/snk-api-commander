import { formatPercent } from '../lib/format'

// Shared trend chip for KPI/chart helpers: a coloured ▲/▼ with a percentage and an optional suffix.
// Rendered only when `value` is non-null, so the caller never shows a fabricated arrow; pass a prior
// period's null through and nothing renders. `invert` flips the colour semantics (a rising error rate
// reads red). `decimals` controls the percentage precision (0 for ratios, 1 for percentage-points).
export function Delta({
  value,
  suffix,
  invert = false,
  decimals = 0,
}: {
  value: number | null
  suffix?: string
  invert?: boolean
  decimals?: number
}) {
  if (value == null) return null
  const up = value >= 0
  const good = invert ? !up : up
  return (
    <span className={good ? 'scns-val-green' : 'scns-val-red'}>
      {up ? '▲' : '▼'} {formatPercent(Math.abs(value), decimals)}
      {suffix ? ` ${suffix}` : ''}
    </span>
  )
}
