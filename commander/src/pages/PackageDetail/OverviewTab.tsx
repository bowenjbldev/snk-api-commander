import { useEffect, useState, type ReactNode } from 'react'
import { useOutletContext, Link } from 'react-router'
import {
  Button,
  Dropdown,
  StructuredListWrapper,
  StructuredListBody,
  StructuredListRow,
  StructuredListCell,
  TextArea,
  TextInput,
} from '@carbon/react'
import { Edit } from '@carbon/icons-react'
import type { PackageContext, RegisteredPackage } from '../../store/types'
import { useStore, useDeploymentQuotas } from '../../store/store'
import { useCan } from '../../store/auth'
import { StateCard } from '../../components/StateCard'
import { CopyableCode } from '../../components/CopyableCode'
import { QuotaBar } from '../../components/QuotaBar'
import { RelativeTime } from '../../components/RelativeTime'
import { EmptyState } from '../../components/EmptyState'
import { formatDateUTC } from '../../lib/time'
import { formatCurrency, formatInt, formatMb, formatPercent } from '../../lib/format'

const STATUS_ITEMS: RegisteredPackage['status'][] = ['active', 'inactive']

export function OverviewTab() {
  const { pkg, deployment, plans } = useOutletContext<PackageContext>()
  const updatePackage = useStore((s) => s.updatePackage)
  const can = useCan()
  const canEdit = can('update plans')
  const client = useStore((s) => (deployment ? s.clients.find((c) => c.id === deployment.client_id) : undefined))
  const subscription = useStore((s) =>
    deployment ? s.subscriptions.find((sub) => sub.id === deployment.subscription_id) : undefined,
  )
  const quotas = useDeploymentQuotas(deployment?.id)

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
      {/* Package metadata (editable) */}
      <PackageMetaCard
        pkg={pkg}
        plansCount={plans.length}
        canEdit={canEdit}
        onSave={(patch) => updatePackage(pkg.name, patch)}
      />

      {/* Deployment info */}
      {!deployment ? (
        <div className="scns-card">
          <EmptyState
            title="This package has no deployment yet"
            description="Provision a deployment to see its state, details and quotas here."
          />
        </div>
      ) : (
        <>
          <StateCard dep={deployment} />

          {/* Details */}
          <div className="scns-card" style={{ padding: 16 }}>
            <div style={{ fontSize: 16, fontWeight: 600, marginBottom: 8 }}>Details</div>
            <StructuredListWrapper isCondensed>
              <StructuredListBody>
                <KVRow
                  label="Client"
                  value={<Link className="scns-link" to="/clients">{client?.name ?? deployment.client_id}</Link>}
                />
                <KVRow label="Package" value={<span className="scns-mono">{deployment.package}</span>} />
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
                <KVRow label="Created" value={<span className="scns-mono">{formatDateUTC(deployment.created_at)}</span>} />
                <KVRow label="Last seen" value={<RelativeTime iso={deployment.last_seen} />} />
              </StructuredListBody>
            </StructuredListWrapper>
            <div style={{ marginTop: 12 }}>
              <div className="scns-helper" style={{ fontSize: 12, marginBottom: 6 }}>
                base_url
              </div>
              <CopyableCode value={deployment.base_url} />
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
        </>
      )}
    </div>
  )
}

interface MetaPatch {
  label: string | null
  description: string | null
  status: RegisteredPackage['status']
}

function PackageMetaCard({
  pkg,
  plansCount,
  canEdit,
  onSave,
}: {
  pkg: RegisteredPackage
  plansCount: number
  canEdit: boolean
  onSave: (patch: MetaPatch) => void
}) {
  const [editing, setEditing] = useState(false)
  const [label, setLabel] = useState(pkg.label ?? '')
  const [description, setDescription] = useState(pkg.description ?? '')
  const [status, setStatus] = useState<RegisteredPackage['status']>(pkg.status)

  // Keep the local draft in sync if the package changes underneath while not editing.
  useEffect(() => {
    if (!editing) {
      setLabel(pkg.label ?? '')
      setDescription(pkg.description ?? '')
      setStatus(pkg.status)
    }
  }, [pkg.label, pkg.description, pkg.status, editing])

  const save = () => {
    onSave({
      label: label.trim() || null,
      description: description.trim() || null,
      status,
    })
    setEditing(false)
  }

  const cancel = () => {
    setLabel(pkg.label ?? '')
    setDescription(pkg.description ?? '')
    setStatus(pkg.status)
    setEditing(false)
  }

  return (
    <div className="scns-card" style={{ padding: 16 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <span style={{ fontSize: 16, fontWeight: 600 }}>Package</span>
        {canEdit && !editing && (
          <Button kind="ghost" size="sm" renderIcon={Edit} onClick={() => setEditing(true)}>
            Edit
          </Button>
        )}
      </div>

      {editing ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16, maxWidth: 480 }}>
          <TextInput
            id="pkg-label"
            labelText="Label"
            placeholder={pkg.name}
            value={label}
            onChange={(e) => setLabel(e.target.value)}
          />
          <TextArea
            id="pkg-description"
            labelText="Description"
            rows={3}
            value={description}
            onChange={(e) => setDescription(e.target.value)}
          />
          <Dropdown
            id="pkg-status"
            titleText="Status"
            label="Status"
            items={STATUS_ITEMS}
            selectedItem={status}
            itemToString={(item) => item ?? ''}
            onChange={({ selectedItem }) => selectedItem && setStatus(selectedItem)}
          />
          <div style={{ display: 'flex', gap: 8 }}>
            <Button size="md" kind="primary" onClick={save}>
              Save
            </Button>
            <Button size="md" kind="secondary" onClick={cancel}>
              Cancel
            </Button>
          </div>
        </div>
      ) : (
        <StructuredListWrapper isCondensed>
          <StructuredListBody>
            <KVRow label="Name" value={<span className="scns-mono">{pkg.name}</span>} />
            <KVRow label="Label" value={pkg.label ?? '—'} />
            <KVRow
              label="Description"
              value={pkg.description ? <span>{pkg.description}</span> : '—'}
            />
            <KVRow label="Status" value={<span className="scns-mono">{pkg.status}</span>} />
            <KVRow label="Plans" value={<span className="scns-num">{plansCount}</span>} />
          </StructuredListBody>
        </StructuredListWrapper>
      )}
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
