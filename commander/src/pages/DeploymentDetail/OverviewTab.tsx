import type { ReactNode } from 'react'
import { useOutletContext, Link } from 'react-router'
import {
  StructuredListWrapper,
  StructuredListBody,
  StructuredListRow,
  StructuredListCell,
} from '@carbon/react'
import type { Deployment } from '../../store/types'
import { useStore, useDeploymentQuotas } from '../../store/store'
import { StateCard } from './StateCard'
import { CopyableCode } from '../../components/CopyableCode'
import { QuotaBar } from '../../components/QuotaBar'
import { RelativeTime } from '../../components/RelativeTime'
import { EmptyState } from '../../components/EmptyState'
import { formatDateUTC } from '../../lib/time'
import { formatCurrency, formatInt, formatMb, formatPercent } from '../../lib/format'

export function OverviewTab() {
  const dep = useOutletContext<Deployment>()
  const client = useStore((s) => s.clients.find((c) => c.id === dep.client_id))
  const subscription = useStore((s) => s.subscriptions.find((sub) => sub.id === dep.subscription_id))
  const quotas = useDeploymentQuotas(dep.id)

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
      <StateCard dep={dep} />

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

      {/* Quotas */}
      <div className="scns-card" style={{ padding: 16 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
          <span style={{ fontSize: 16, fontWeight: 600 }}>Quotas</span>
          <Link className="scns-link" to="/quotas">
            Edit limits →
          </Link>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: 24 }}>
          {quotas.length === 0 ? (
            <div style={{ gridColumn: '1 / -1' }}>
              <EmptyState title="No quotas configured" />
            </div>
          ) : (
            quotas.map((q) => {
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
            })
          )}
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
