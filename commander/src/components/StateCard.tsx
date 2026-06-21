import type { ReactNode } from 'react'
import { Button } from '@carbon/react'
import { WarningAltFilled, ErrorFilled, CheckmarkFilled } from '@carbon/icons-react'
import type { Package } from '../store/types'
import { useCan } from '../store/auth'
import { useActions } from '../providers/ActionsProvider'
import { GraceCountdown } from './GraceCountdown'
import { RelativeTime } from './RelativeTime'
import { formatDateUTC } from '../lib/time'

export function StateCard({ pkg }: { pkg: Package }) {
  const actions = useActions()
  const can = useCan()
  const canControl = can('control packages')
  const { status } = pkg
  const accent =
    status === 'grace' ? 'var(--scns-warning)' : status === 'suspended' ? 'var(--scns-error)' : 'var(--scns-success)'
  const Icon = status === 'grace' ? WarningAltFilled : status === 'suspended' ? ErrorFilled : CheckmarkFilled
  const heading =
    status === 'grace'
      ? 'Grace period — payment overdue'
      : status === 'suspended'
        ? `Suspended — ${pkg.reason ?? 'manual'}`
        : 'Operational'

  return (
    <div className="scns-card" style={{ display: 'flex', borderLeft: `3px solid ${accent}`, marginBottom: 24 }}>
      <div style={{ flex: 1, padding: 20 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 16 }}>
          <Icon size={20} style={{ color: accent }} />
          <span style={{ fontSize: 16, fontWeight: 600 }}>{heading}</span>
        </div>

        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16, marginBottom: 16 }}>
          <Field label="Reason" value={<span className="scns-mono">{pkg.reason ?? '—'}</span>} />
          {status === 'grace' && pkg.grace_until && (
            <Field label="Deadline" value={<span className="scns-mono">{formatDateUTC(pkg.grace_until)}</span>} />
          )}
          <Field
            label="Changed by"
            value={
              <span>
                {pkg.changed_by} · <RelativeTime iso={pkg.changed_at} />
              </span>
            }
          />
        </div>

        {pkg.message && (
          <div style={{ marginBottom: 16 }}>
            <div className="scns-helper" style={{ fontSize: 12, marginBottom: 4 }}>
              Client-facing message
            </div>
            <blockquote
              style={{
                margin: 0,
                paddingLeft: 12,
                borderLeft: '2px solid var(--cds-border-subtle)',
                color: 'var(--cds-text-secondary)',
                fontStyle: 'italic',
              }}
            >
              “{pkg.message}”
            </blockquote>
          </div>
        )}

        {canControl && (
          <div style={{ display: 'flex', gap: 8 }}>
            {status !== 'active' && (
              <Button size="md" kind="primary" onClick={() => actions.resume(pkg)}>
                Resume now
              </Button>
            )}
            {status === 'grace' && (
              <Button size="md" kind="tertiary" onClick={() => actions.grace(pkg)}>
                Extend grace…
              </Button>
            )}
            {status !== 'suspended' && (
              <Button size="md" kind="danger--tertiary" onClick={() => actions.suspend(pkg)}>
                Suspend now
              </Button>
            )}
            {status === 'active' && (
              <Button size="md" kind="tertiary" onClick={() => actions.grace(pkg)}>
                Move to grace…
              </Button>
            )}
          </div>
        )}
      </div>

      <div style={{ width: 280, flex: 'none', borderLeft: '1px solid var(--cds-border-subtle)', padding: 20 }}>
        {status === 'grace' && pkg.grace_until ? (
          <>
            <div className="scns-helper" style={{ fontSize: 12, marginBottom: 8 }}>
              Suspends in
            </div>
            <GraceCountdown until={pkg.grace_until} mode="clock" />
            <div className="scns-helper" style={{ fontSize: 12, marginTop: 8 }}>
              users will receive HTTP 402
            </div>
          </>
        ) : (
          <>
            <div className="scns-helper" style={{ fontSize: 12, marginBottom: 8 }}>
              Status
            </div>
            <div style={{ fontSize: 28, color: accent }}>{status === 'suspended' ? 'Suspended' : 'Operational'}</div>
            <div className="scns-helper" style={{ fontSize: 12, marginTop: 8 }}>
              {status === 'suspended' ? 'users receive HTTP 402' : 'serving normally'}
            </div>
          </>
        )}
      </div>
    </div>
  )
}

function Field({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <div className="scns-helper" style={{ fontSize: 12, marginBottom: 4 }}>
        {label}
      </div>
      <div style={{ fontSize: 13 }}>{value}</div>
    </div>
  )
}
