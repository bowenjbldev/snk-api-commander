import type { CSSProperties, ReactNode } from 'react'
import type { AttentionSeverity, HttpMethod } from '../../types'

/** Monospace inline span (identifiers, versions, emails…). */
export function Mono({
  children,
  color,
  size,
  title,
}: {
  children: ReactNode
  color?: string
  size?: number
  title?: string
}) {
  return (
    <span
      className="sc-mono"
      title={title}
      style={{ color, fontSize: size }}
    >
      {children}
    </span>
  )
}

/** Right-aligned tabular number. */
export function Num({
  children,
  color,
  title,
}: {
  children: ReactNode
  color?: string
  title?: string
}) {
  return (
    <span className="sc-num" title={title} style={{ color, display: 'block' }}>
      {children}
    </span>
  )
}

const METHOD_STYLE: Record<HttpMethod, CSSProperties> = {
  GET: { background: 'var(--sc-method-get-bg)', color: 'var(--sc-method-get-tx)' },
  POST: { background: 'var(--cds-tag-background-green, #044317)', color: 'var(--cds-tag-color-green, #6fdc8c)' },
  PUT: { background: 'var(--cds-layer-accent)', color: 'var(--cds-text-primary)' },
  PATCH: { background: 'var(--cds-layer-accent)', color: 'var(--cds-text-primary)' },
  DELETE: { background: 'var(--cds-tag-background-red, #750e13)', color: 'var(--cds-tag-color-red, #ff8389)' },
}

export function MethodTag({ method }: { method: HttpMethod }) {
  return (
    <span
      className="sc-mono"
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        height: 18,
        padding: '0 6px',
        borderRadius: 24,
        fontSize: 10,
        fontWeight: 500,
        letterSpacing: 0.4,
        ...METHOD_STYLE[method],
      }}
    >
      {method}
    </span>
  )
}

const SEVERITY_TONE: Record<AttentionSeverity, 'red' | 'amber'> = {
  'QUOTA OVER': 'red',
  'GRACE ENDS': 'amber',
  OVERDUE: 'red',
  GRACE: 'amber',
}

export function SeverityPill({ severity }: { severity: AttentionSeverity }) {
  const tone = SEVERITY_TONE[severity]
  const style: CSSProperties =
    tone === 'red'
      ? { background: 'var(--cds-tag-background-red, #750e13)', color: 'var(--cds-tag-color-red, #ff8389)' }
      : { background: 'var(--sc-tag-amber-bg)', color: 'var(--sc-tag-amber-tx)' }
  return (
    <span
      className="sc-mono"
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        height: 20,
        padding: '0 9px',
        borderRadius: 24,
        fontSize: 10,
        letterSpacing: 0.5,
        whiteSpace: 'nowrap',
        ...style,
      }}
    >
      {severity}
    </span>
  )
}

/** Small status dot used in the activity feed + connection indicator. */
export function StatusDot({
  tone,
  size = 8,
  pulse,
}: {
  tone: 'green' | 'amber' | 'red' | 'gray'
  size?: number
  pulse?: boolean
}) {
  const color =
    tone === 'green'
      ? 'var(--sc-support-success)'
      : tone === 'amber'
        ? 'var(--sc-support-warning)'
        : tone === 'red'
          ? 'var(--sc-support-error)'
          : 'var(--cds-text-helper)'
  return (
    <span
      style={{
        width: size,
        height: size,
        borderRadius: '50%',
        background: color,
        flex: 'none',
        display: 'inline-block',
        animation: pulse ? 'sc-pulse 1.4s ease-in-out infinite' : undefined,
      }}
    />
  )
}

/** Color-coded HTTP status number for the log table (2xx green … 5xx red). */
export function StatusCode({ status }: { status: number }) {
  const cls =
    status < 300
      ? 'var(--sc-status-2xx)'
      : status < 400
        ? 'var(--sc-status-3xx)'
        : status < 500
          ? 'var(--sc-status-4xx)'
          : 'var(--sc-status-5xx)'
  return (
    <span className="sc-mono" style={{ color: cls, fontWeight: 500 }}>
      {status}
    </span>
  )
}
