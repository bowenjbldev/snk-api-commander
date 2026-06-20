import { useState, type ReactNode } from 'react'
import { useOutletContext } from 'react-router'
import { Button } from '@carbon/react'
import { WarningAltFilled, ErrorFilled, CheckmarkFilled } from '@carbon/icons-react'
import type { Deployment, PackageContext } from '../../store/types'
import { useCan } from '../../store/auth'
import { useActions } from '../../providers/ActionsProvider'
import { GraceCountdown } from '../../components/GraceCountdown'
import { RelativeTime } from '../../components/RelativeTime'
import { QuotaBar } from '../../components/QuotaBar'
import { EmptyState } from '../../components/EmptyState'
import { ProvisionModal } from '../../components/modals/ProvisionModal'
import { formatDateUTC } from '../../lib/time'
import { formatInt } from '../../lib/format'
import { deploymentBaseUrl } from '../../lib/deployment'

// The package's deployed instance(s). It's 1:1 for now, so `deployment` from the package context is
// the single instance (or undefined → empty state with a Provision CTA).
export function DeploymentsTab() {
  const { pkg, deployment, plans } = useOutletContext<PackageContext>()
  const can = useCan()
  const [provisionOpen, setProvisionOpen] = useState(false)

  if (!deployment) {
    const canProvision = can('provision deployments')
    const noPlans = plans.length === 0
    return (
      <>
        <EmptyState
          title="Not deployed yet"
          description={
            canProvision
              ? noPlans
                ? 'Create a plan first (Plans tab) before provisioning an instance of this package.'
                : 'Provision a live instance of this package for a client.'
              : 'This package has no live instance.'
          }
          action={
            canProvision && !noPlans
              ? { label: 'Provision', onClick: () => setProvisionOpen(true) }
              : undefined
          }
        />
        {provisionOpen && (
          <ProvisionModal
            open
            packageName={pkg.name}
            plans={plans}
            onClose={() => setProvisionOpen(false)}
            onProvisioned={() => setProvisionOpen(false)}
          />
        )}
      </>
    )
  }

  return <DeploymentCard dep={deployment} />
}

function DeploymentCard({ dep }: { dep: Deployment }) {
  const actions = useActions()
  const can = useCan()
  const canControl = can('control deployments')
  const canDelete = can('delete deployments')
  const { status } = dep.package

  const accent =
    status === 'grace' ? 'var(--scns-warning)' : status === 'suspended' ? 'var(--scns-error)' : 'var(--scns-success)'
  const Icon = status === 'grace' ? WarningAltFilled : status === 'suspended' ? ErrorFilled : CheckmarkFilled
  const heading =
    status === 'grace'
      ? 'Grace period — payment overdue'
      : status === 'suspended'
        ? `Suspended — ${dep.package.reason ?? 'manual'}`
        : 'Operational'

  return (
    <div className="scns-card" style={{ display: 'flex', borderLeft: `3px solid ${accent}`, marginBottom: 24 }}>
        <div style={{ flex: 1, padding: 20 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 16 }}>
            <Icon size={20} style={{ color: accent }} />
            <span style={{ fontSize: 16, fontWeight: 600 }}>{heading}</span>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', gap: 16, marginBottom: 16 }}>
            <Field label="Reason" value={<span className="scns-mono">{dep.package.reason ?? '—'}</span>} />
            {status === 'grace' && dep.package.grace_until && (
              <Field label="Deadline" value={<span className="scns-mono">{formatDateUTC(dep.package.grace_until)}</span>} />
            )}
            <Field
              label="Changed by"
              value={
                <span>
                  {dep.package.changed_by} · <RelativeTime iso={dep.package.changed_at} />
                </span>
              }
            />
          </div>

          {dep.package.message && (
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
                “{dep.package.message}”
              </blockquote>
            </div>
          )}

          <div
            style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(3, 1fr)',
              gap: 16,
              marginBottom: canControl || canDelete ? 16 : 0,
            }}
          >
            <Field label="Requests (24h)" value={<span className="scns-num">{formatInt(dep.requests_24h)}</span>} />
            <Field label="Quota health" value={<QuotaBar current={dep.quota_health} limit={1} width={120} showLabel />} />
            <Field label="Last seen" value={<RelativeTime iso={dep.last_seen} />} />
            <Field
              label="Base URL"
              value={
                <a className="scns-mono" href={deploymentBaseUrl(dep)} target="_blank" rel="noreferrer">
                  {deploymentBaseUrl(dep)}
                </a>
              }
            />
          </div>

          {(canControl || canDelete) && (
            <div style={{ display: 'flex', gap: 8 }}>
              {canControl && status !== 'active' && (
                <Button size="md" kind="primary" onClick={() => actions.resume(dep)}>
                  Resume now
                </Button>
              )}
              {canControl && status === 'grace' && (
                <Button size="md" kind="tertiary" onClick={() => actions.grace(dep)}>
                  Extend grace…
                </Button>
              )}
              {canControl && status === 'active' && (
                <Button size="md" kind="tertiary" onClick={() => actions.grace(dep)}>
                  Move to grace…
                </Button>
              )}
              {canControl && status !== 'suspended' && (
                <Button size="md" kind="danger--tertiary" onClick={() => actions.suspend(dep)}>
                  Suspend now
                </Button>
              )}
              {canDelete && (
                <Button size="md" kind="danger--ghost" onClick={() => actions.deleteDeployment(dep)}>
                  Delete deployment
                </Button>
              )}
            </div>
          )}
        </div>

        <div style={{ width: 280, flex: 'none', borderLeft: '1px solid var(--cds-border-subtle)', padding: 20 }}>
          {status === 'grace' && dep.package.grace_until ? (
            <>
              <div className="scns-helper" style={{ fontSize: 12, marginBottom: 8 }}>
                Suspends in
              </div>
              <GraceCountdown until={dep.package.grace_until} mode="clock" />
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
