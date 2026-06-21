import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useOutletContext, Link } from 'react-router'
import {
  Button,
  Dropdown,
  StructuredListWrapper,
  StructuredListBody,
  StructuredListRow,
  StructuredListCell,
  Tag,
  TextArea,
  TextInput,
} from '@carbon/react'
import { Edit } from '@carbon/icons-react'
import type { Package, PackageContext } from '../../store/types'
import { packageBaseUrl } from '../../lib/package'
import { useStore, usePackageQuotas } from '../../store/store'
import { useCan } from '../../store/auth'
import { StateCard } from '../../components/StateCard'
import { CopyableCode } from '../../components/CopyableCode'
import { QuotaBar } from '../../components/QuotaBar'
import { RelativeTime } from '../../components/RelativeTime'
import { EmptyState } from '../../components/EmptyState'
import { ProvisionModal } from '../../components/modals/ProvisionModal'
import { ChangePlanModal } from '../../components/modals/ChangePlanModal'
import { formatDateUTC } from '../../lib/time'
import { formatCurrency, formatInt, formatMb, formatPercent } from '../../lib/format'

const STATUS_ITEMS: Package['listing_status'][] = ['active', 'inactive']

export function OverviewTab() {
  const { pkg, packageRow, plans } = useOutletContext<PackageContext>()
  const updatePackage = useStore((s) => s.updatePackage)
  const can = useCan()
  const canEdit = can('update plans')
  const canProvision = can('provision packages')
  const canControl = can('control packages')
  const [provisionOpen, setProvisionOpen] = useState(false)
  const [changePlanOpen, setChangePlanOpen] = useState(false)
  const client = useStore((s) => (packageRow ? s.clients.find((c) => c.id === packageRow.client_id) : undefined))
  const subscription = useStore((s) =>
    packageRow ? s.subscriptions.find((sub) => sub.id === packageRow.subscription_id) : undefined,
  )
  const quotas = usePackageQuotas(packageRow?.id)
  // Historial de planes: todos los términos del package, vigente primero.
  const allSubscriptions = useStore((s) => s.subscriptions)
  const history = useMemo(
    () =>
      packageRow
        ? allSubscriptions
            .filter((s) => s.package_id === packageRow.id)
            .sort((a, b) => b.started_at.localeCompare(a.started_at) || b.id - a.id)
        : [],
    [allSubscriptions, packageRow],
  )

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
      {/* Package metadata (editable) */}
      <PackageMetaCard
        pkg={pkg}
        plansCount={plans.length}
        canEdit={canEdit}
        onSave={(patch) => updatePackage(pkg.name, patch)}
      />

      {/* Instance info */}
      {!packageRow ? (
        <div className="scns-card">
          <EmptyState
            title="This package has no live instance yet"
            description={
              plans.length === 0
                ? 'Create a plan first (Plans tab), then provision a live instance to see its state, details and quotas here.'
                : 'Provision a live instance to see its state, details and quotas here.'
            }
            action={
              canProvision && plans.length > 0
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
        </div>
      ) : (
        <>
          <StateCard pkg={packageRow} />

          {/* Details */}
          <div className="scns-card" style={{ padding: 16 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
              <span style={{ fontSize: 16, fontWeight: 600 }}>Details</span>
              {canControl && (
                <Button kind="ghost" size="sm" onClick={() => setChangePlanOpen(true)}>
                  Change plan
                </Button>
              )}
            </div>
            <StructuredListWrapper isCondensed>
              <StructuredListBody>
                <KVRow
                  label="Client"
                  value={
                    client ? (
                      <Link className="scns-link" to={`/clients/${client.id}`}>{client.name}</Link>
                    ) : (
                      <span className="scns-helper">Unknown client</span>
                    )
                  }
                />
                <KVRow label="Package" value={<span className="scns-mono">{packageRow.name}</span>} />
                <KVRow
                  label="Subscription"
                  value={
                    subscription ? (
                      <span>
                        {subscription.plan} · {formatCurrency(subscription.price_monthly)}/mo
                      </span>
                    ) : (
                      '—'
                    )
                  }
                />
                <KVRow label="Created" value={<span className="scns-mono">{formatDateUTC(packageRow.created_at)}</span>} />
                <KVRow label="Last seen" value={<RelativeTime iso={packageRow.last_seen} />} />
              </StructuredListBody>
            </StructuredListWrapper>
            <div style={{ marginTop: 12 }}>
              <div className="scns-helper" style={{ fontSize: 12, marginBottom: 6 }}>
                base_url
              </div>
              <CopyableCode value={packageBaseUrl(packageRow)} />
            </div>
          </div>

          {/* Quotas */}
          <div className="scns-card" style={{ padding: 16 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
              <span style={{ fontSize: 16, fontWeight: 600 }}>Quotas</span>
              <Link className="scns-link" to={`/packages/${packageRow.id}/quotas`}>
                Edit limits {'->'}
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

          {/* Plan history */}
          <div className="scns-card" style={{ padding: 16 }}>
            <div style={{ fontSize: 16, fontWeight: 600, marginBottom: 12 }}>Plan history</div>
            {history.length === 0 ? (
              <EmptyState title="No plan history" />
            ) : (
              <StructuredListWrapper isCondensed>
                <StructuredListBody>
                  {history.map((sub) => (
                    <StructuredListRow key={sub.id}>
                      <StructuredListCell>
                        <span className="scns-mono">{sub.plan}</span> · {formatCurrency(sub.price_monthly)}/mo
                      </StructuredListCell>
                      <StructuredListCell>
                        <span className="scns-mono scns-helper" style={{ fontSize: 12 }}>
                          {formatDateUTC(sub.started_at)} → {sub.ended_at ? formatDateUTC(sub.ended_at) : 'current'}
                        </span>
                      </StructuredListCell>
                      <StructuredListCell>
                        {sub.id === packageRow.subscription_id ? (
                          <Tag type="blue" size="sm">current</Tag>
                        ) : (
                          <span className="scns-helper" style={{ fontSize: 12 }}>{sub.billing_status}</span>
                        )}
                      </StructuredListCell>
                    </StructuredListRow>
                  ))}
                </StructuredListBody>
              </StructuredListWrapper>
            )}
          </div>

          {changePlanOpen && (
            <ChangePlanModal
              open
              packageName={pkg.name}
              plans={plans}
              currentPlanId={subscription?.plan_id ?? null}
              onClose={() => setChangePlanOpen(false)}
              onChanged={() => setChangePlanOpen(false)}
            />
          )}
        </>
      )}
    </div>
  )
}

interface MetaPatch {
  label: string | null
  description: string | null
  listing_status: Package['listing_status']
}

function PackageMetaCard({
  pkg,
  plansCount,
  canEdit,
  onSave,
}: {
  pkg: Package
  plansCount: number
  canEdit: boolean
  onSave: (patch: MetaPatch) => void
}) {
  const [editing, setEditing] = useState(false)
  const [label, setLabel] = useState(pkg.label ?? '')
  const [description, setDescription] = useState(pkg.description ?? '')
  const [status, setStatus] = useState<Package['listing_status']>(pkg.listing_status)

  // Keep the local draft in sync if the package changes underneath while not editing.
  useEffect(() => {
    if (!editing) {
      setLabel(pkg.label ?? '')
      setDescription(pkg.description ?? '')
      setStatus(pkg.listing_status)
    }
  }, [pkg.label, pkg.description, pkg.listing_status, editing])

  const save = () => {
    onSave({
      label: label.trim() || null,
      description: description.trim() || null,
      listing_status: status,
    })
    setEditing(false)
  }

  const cancel = () => {
    setLabel(pkg.label ?? '')
    setDescription(pkg.description ?? '')
    setStatus(pkg.listing_status)
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
            <KVRow label="Status" value={<span className="scns-mono">{pkg.listing_status}</span>} />
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
