// HTTP status → Carbon colour token for request-log rows: 2xx green, 3xx muted, 4xx amber, 5xx red.
export function statusColor(s: number): string {
  if (s < 300) return 'var(--scns-success)'
  if (s < 400) return 'var(--cds-text-helper)'
  if (s < 500) return 'var(--scns-warn-text)'
  return 'var(--scns-error)'
}
