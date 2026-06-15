import type { ReactNode } from 'react'
import { useOutletContext, Link } from 'react-router'
import {
  Button,
  StructuredListWrapper,
  StructuredListBody,
  StructuredListRow,
  StructuredListCell,
} from '@carbon/react'
import type { Deployment } from '../../store/types'
import { useStore, useDeploymentQuotas } from '../../store/store'
import { useActions } from '../../providers/ActionsProvider'
import { StateCard } from './StateCard'
import { CopyableCode } from '../../components/CopyableCode'
import { QuotaBar } from '../../components/QuotaBar'
import { RelativeTime } from '../../components/RelativeTime'
import { formatDateUTC, formatRelative } from '../../lib/time'
import { formatCurrency, formatInt, formatMb, formatPercent } from '../../lib/format'

export function OverviewTab() {
  const dep = useOutletContext<Deployment>()
  const actions = useActions()
  const client = useStore((s) => s.clients.find((c) => c.id === dep.client_id))
  const subscription = useStore((s) => s.subscriptions.find((sub) => sub.id === dep.subscription_id))
  const quotas = useDeploymentQuotas(dep.id)

  const maskedSecret = `sk_ctl_${'•'.repeat(20)}${dep.control_secret_suffix}`
  const fullSecret = `sk_ctl_live_9c44ab21f08e7d3b${dep.control_secret_suffix}`

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
      <StateCard dep={dep} />

      <div className="scns-grid-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 24 }}>
        {/* Details */}
        <div className="scns-card" style={{ padding: 16 }}>
          <div style={{ fontSize: 16, fontWeight: 600, marginBottom: 8 }}>Details</div>
          <StructuredListWrapper isCondensed>
            <StructuredListBody>
              <KVRow label="Client" value={<Link className="scns-link" to="/clients">{client?.name ?? dep.client_id}</Link>} />
              <KVRow label="Package" value={<span className="scns-mono">{dep.package} {dep.version}</span>} />
              <KVRow
                label="Subscription"
                value={
                  subscription ? (
                    <Link className="scns-link" to="/subscriptions">
                      {subscription.plan} · {formatCurrency(subscription.price_monthly)}/mo
                    </Link>
                  ) : (
                    '—'
                  )
                }
              />
              <KVRow label="Created" value={<span className="scns-mono">{formatDateUTC(dep.created_at)}</span>} />
              <KVRow label="Last seen" value={<RelativeTime iso={dep.last_seen} />} />
            </StructuredListBody>
          </StructuredListWrapper>
          <div style={{ marginTop: 12 }}>
            <div className="scns-helper" style={{ fontSize: 12, marginBottom: 6 }}>
              base_url
            </div>
            <CopyableCode value={dep.base_url} />
          </div>
        </div>

        {/* Control secret */}
        <div className="scns-card" style={{ padding: 16 }}>
          <div style={{ fontSize: 16, fontWeight: 600, marginBottom: 8 }}>Control secret</div>
          <p className="scns-helper" style={{ fontSize: 13, marginBottom: 12 }}>
            Used to sign requests to this deployment’s <span className="scns-mono">/control</span> API.
          </p>
          <CopyableCode value={fullSecret} masked={maskedSecret} secret />
          <div className="scns-mono scns-helper" style={{ fontSize: 11, marginTop: 8 }}>
            last rotated {formatRelative(dep.control_secret_rotated_at, Date.now())}
          </div>
          <Button kind="tertiary" size="sm" style={{ marginTop: 12 }} onClick={() => actions.rotateSecret(dep)}>
            Rotate secret…
          </Button>
          <p className="scns-helper" style={{ fontSize: 12, marginTop: 8 }}>
            Rotating immediately invalidates the previous secret. Requires typed confirmation.
          </p>
        </div>
      </div>

      {/* Quotas */}
      <div className="scns-card" style={{ padding: 16 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
          <span style={{ fontSize: 16, fontWeight: 600 }}>Quotas</span>
          <Link className="scns-link" to="/quotas">
            Edit limits →
          </Link>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 24 }}>
          {quotas.map((q) => {
            const ratio = q.limit_value == null || q.limit_value === 0 ? 0 : q.current_value / q.limit_value
            const fmt = (v: number) => (q.unit === 'mb' ? formatMb(v) : formatInt(v))
            return (
              <div key={q.id}>
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}>
                  <span className="scns-mono" style={{ fontSize: 12 }}>
                    {q.metric_key}
                  </span>
                  <span className="scns-num" style={{ fontSize: 12 }}>
                    {q.limit_value == null ? '—' : formatPercent(ratio)}
                  </span>
                </div>
                <QuotaBar current={q.current_value} limit={q.limit_value} height={6} />
                <div className="scns-mono scns-helper" style={{ fontSize: 11, marginTop: 6 }}>
                  {fmt(q.current_value)} / {q.limit_value == null ? 'no limit' : fmt(q.limit_value)}
                </div>
              </div>
            )
          })}
        </div>
      </div>
    </div>
  )
}

function KVRow({ label, value }: { label: string; value: ReactNode }) {
  return (
    <StructuredListRow>
      <StructuredListCell noWrap style={{ color: 'var(--cds-text-secondary)', width: 140 }}>
        {label}
      </StructuredListCell>
      <StructuredListCell>{value}</StructuredListCell>
    </StructuredListRow>
  )
}
