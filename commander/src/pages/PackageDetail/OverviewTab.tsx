import { useState, type ReactNode } from 'react'
import { useOutletContext, useNavigate, Link } from 'react-router'
import {
  Button,
  InlineNotification,
  OverflowMenu,
  OverflowMenuItem,
  Tag,
  TextArea,
  TextInput,
  Toggle,
} from '@carbon/react'
import { Save } from '@carbon/icons-react'
import type { Client, Package, PackageContext, QuotaMetric, Subscription } from '../../store/types'
import { useStore, usePackageQuotas } from '../../store/store'
import { useCan } from '../../store/auth'
import { useToast } from '../../providers/ToastProvider'
import { Card } from '../../components/Card'
import { QuotaBar } from '../../components/QuotaBar'
import { RelativeTime } from '../../components/RelativeTime'
import { EmptyState } from '../../components/EmptyState'
import { ProvisionModal } from '../../components/modals/ProvisionModal'
import { formatDateUTC, formatCountdownShort, msUntil } from '../../lib/time'
import { formatCurrency, formatInt, formatMb, formatPercent } from '../../lib/format'

export function OverviewTab() {
  const { pkg, packageRow, plans } = useOutletContext<PackageContext>()
  const updatePackage = useStore((s) => s.updatePackage)
  const navigate = useNavigate()
  const can = useCan()
  const canEdit = can('update plans')
  const canProvision = can('provision packages')
  const [provisionOpen, setProvisionOpen] = useState(false)
  const client = useStore((s) => (packageRow ? s.clients.find((c) => c.id === packageRow.client_id) : undefined))
  const subscription = useStore((s) =>
    packageRow ? s.subscriptions.find((sub) => sub.id === packageRow.subscription_id) : undefined,
  )
  const quotas = usePackageQuotas(packageRow?.id)

  const metaForm = (
    <PackageMetaForm
      key={pkg.name}
      pkg={pkg}
      canEdit={canEdit}
      onSave={(patch) => updatePackage(pkg.name, patch)}
    />
  )

  // No live instance yet → just the catalog form + a provisioning prompt.
  if (!packageRow) {
    return (
      <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
        {metaForm}
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
      </div>
    )
  }

  // Critical billing state (grace / suspended) — the only runtime info worth surfacing now that the
  // Operational card is gone. Mirrors the overdue banner on the client record page.
  const reasonText = reasonLabel(packageRow.reason)
  const stateBanner =
    packageRow.status === 'grace' ? (
      <InlineNotification
        kind="warning"
        lowContrast
        hideCloseButton
        title={`Grace period${reasonText ? ` — ${reasonText}` : ''}`}
        subtitle={
          (packageRow.grace_until
            ? `Suspends in ${formatCountdownShort(msUntil(packageRow.grace_until, Date.now()))} · ${formatDateUTC(packageRow.grace_until)}.`
            : 'Suspends when the grace period ends.') +
          (packageRow.message ? ` ${packageRow.message}` : ' Users will be blocked once it ends.')
        }
        style={{ maxWidth: '100%', margin: '0 0 24px' }}
      />
    ) : packageRow.status === 'suspended' ? (
      <InlineNotification
        kind="error"
        lowContrast
        hideCloseButton
        title={`Suspended${reasonText ? ` — ${reasonText}` : ''}`}
        subtitle={
          `${packageRow.message ? `${packageRow.message} ` : 'Users are currently blocked. '}Changed by ${packageRow.changed_by}.`
        }
        style={{ maxWidth: '100%', margin: '0 0 24px' }}
      />
    ) : null

  // Live instance → primary column (form, quotas, history) + a Details reference rail,
  // same split as the client record page.
  return (
    <>
    {stateBanner}
    <div className="scns-client-grid">
      <div style={{ display: 'flex', flexDirection: 'column', gap: 24, minWidth: 0 }}>
        {metaForm}
      </div>

      <aside className="scns-client-rail">
        <DetailsCardList
          client={client}
          subscription={subscription}
          packageRow={packageRow}
          onViewClient={client ? () => navigate(`/clients/${client.id}`) : undefined}
        />
        <QuotasCardMini quotas={quotas} onEditLimits={() => navigate(`/packages/${packageRow.id}/quotas`)} />
      </aside>
    </div>
    </>
  )
}

interface MetaPatch {
  label: string | null
  description: string | null
  listing_status: Package['listing_status']
}

// Always-on catalog form: the fields ARE the package's registry view. A floating Save lights up
// only when there are unsaved edits. Mirrors the field treatment of the client record form.
function PackageMetaForm({
  pkg,
  canEdit,
  onSave,
}: {
  pkg: Package
  canEdit: boolean
  onSave: (patch: MetaPatch) => void
}) {
  const toast = useToast()
  const [label, setLabel] = useState(pkg.label ?? '')
  const [description, setDescription] = useState(pkg.description ?? '')
  const [active, setActive] = useState(pkg.listing_status === 'active')

  const reset = () => {
    setLabel(pkg.label ?? '')
    setDescription(pkg.description ?? '')
    setActive(pkg.listing_status === 'active')
  }

  const dirty =
    label.trim() !== (pkg.label ?? '') ||
    description.trim() !== (pkg.description ?? '') ||
    active !== (pkg.listing_status === 'active')

  const save = () => {
    onSave({
      label: label.trim() || null,
      description: description.trim() || null,
      listing_status: active ? 'active' : 'inactive',
    })
    toast.success('Package updated', pkg.label ?? pkg.name)
  }

  const ro = !canEdit

  const menu = canEdit ? (
    <OverflowMenu size="sm" flipped aria-label="Package actions">
      <OverflowMenuItem itemText="Discard changes" disabled={!dirty} onClick={reset} />
    </OverflowMenu>
  ) : undefined

  return (
    <>
      <Card title="Package" action={menu} bodyClassName="scns-client-form" bodyPadding={24}>
        <p className="scns-section-label">Catalog</p>
        <div className="scns-form-grid">
          <TextInput
            id="pk-name"
            labelText="Name"
            value={pkg.name}
            disabled
            helperText="Business key — not editable"
          />
          <TextInput
            id="pk-label"
            labelText="Label (optional)"
            placeholder={pkg.name}
            value={label}
            disabled={ro}
            onChange={(e) => setLabel(e.target.value)}
          />
          <TextArea
            id="pk-description"
            labelText="Description (optional)"
            rows={2}
            value={description}
            disabled={ro}
            onChange={(e) => setDescription(e.target.value)}
          />
          <div style={{ display: 'flex', alignItems: 'flex-end', paddingBottom: 4 }}>
            <Toggle
              id="pk-listing"
              labelText="Listing"
              labelA="Inactive"
              labelB="Active"
              toggled={active}
              disabled={ro}
              onToggle={setActive}
            />
          </div>
        </div>
      </Card>

      {canEdit && (
        <div className="scns-save-fab">
          <Button kind="primary" size="lg" renderIcon={Save} onClick={save} disabled={!dirty}>
            Save changes
          </Button>
        </div>
      )}
    </>
  )
}

// ── Details card — instance reference (client, subscription, connection, activity) ──────────────
interface DetailsProps {
  client?: Client
  subscription?: Subscription
  packageRow: Package
  onViewClient?: () => void
}

// Turn the technical state reason code into plain language for the banner title.
function reasonLabel(reason: string | null): string | null {
  if (!reason) return null
  if (reason.startsWith('quota_exceeded')) return 'usage limit exceeded'
  if (reason === 'payment_overdue') return 'payment overdue'
  if (reason === 'abuse') return 'flagged for abuse'
  if (reason === 'manual') return 'manual hold'
  return reason.replace(/_/g, ' ')
}

function clientValue(client?: Client): ReactNode {
  return client ? (
    <Link className="scns-link" to={`/clients/${client.id}`}>{client.name}</Link>
  ) : (
    <span className="scns-helper">Unknown client</span>
  )
}
function subValue(sub?: Subscription): ReactNode {
  return sub ? (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
      <Tag type="cool-gray" size="sm">{sub.plan}</Tag>
      <span className="scns-num scns-helper" style={{ fontSize: 12 }}>{formatCurrency(sub.price_monthly)}/mo</span>
    </span>
  ) : (
    <span className="scns-helper">—</span>
  )
}
function provisionedValue(p: Package): ReactNode {
  return <span className="scns-reltime">{formatDateUTC(p.provisioned_at ?? p.created_at)}</span>
}
function lastSeenValue(p: Package): ReactNode {
  return p.last_seen ? <RelativeTime iso={p.last_seen} /> : <span className="scns-helper">Never</span>
}
// One row per field, label left / value right, divided by hairlines (mini-table idiom).
function DetailsCardList({ client, subscription, packageRow, onViewClient }: DetailsProps) {
  return (
    <Card
      title="Details"
      bodyPadding="4px 16px 12px"
      action={
        onViewClient ? (
          <OverflowMenu size="sm" flipped aria-label="Details actions">
            <OverflowMenuItem itemText="View client" onClick={onViewClient} />
          </OverflowMenu>
        ) : undefined
      }
    >
      <div className="scns-kvlist">
        <div className="scns-kvlist__row">
          <span className="scns-kvlist__k">Client</span>
          <span className="scns-kvlist__v">{clientValue(client)}</span>
        </div>
        <div className="scns-kvlist__row">
          <span className="scns-kvlist__k">Subscription</span>
          <span className="scns-kvlist__v">{subValue(subscription)}</span>
        </div>
        <div className="scns-kvlist__row">
          <span className="scns-kvlist__k">Provisioned</span>
          <span className="scns-kvlist__v">{provisionedValue(packageRow)}</span>
        </div>
        <div className="scns-kvlist__row">
          <span className="scns-kvlist__k">Last seen</span>
          <span className="scns-kvlist__v">{lastSeenValue(packageRow)}</span>
        </div>
      </div>
    </Card>
  )
}

// Compact quota rail card: one stacked bar per metric (no 4-col grid — it lives in the 360px rail).
function QuotasCardMini({ quotas, onEditLimits }: { quotas: QuotaMetric[]; onEditLimits: () => void }) {
  return (
    <Card
      title="Quotas"
      bodyPadding="8px 16px 16px"
      action={
        <OverflowMenu size="sm" flipped aria-label="Quotas actions">
          <OverflowMenuItem itemText="Edit limits" onClick={onEditLimits} />
        </OverflowMenu>
      }
    >
      {quotas.length === 0 ? (
        <div className="scns-helper" style={{ fontSize: 13, padding: '4px 0' }}>No quotas configured.</div>
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {quotas.map((q) => {
            const ratio = q.limit_value == null || q.limit_value === 0 ? 0 : q.current_value / q.limit_value
            const fmt = (v: number) => (q.unit === 'mb' ? formatMb(v) : formatInt(v))
            return (
              <div key={q.id}>
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 6 }}>
                  <span className="scns-mono" style={{ fontSize: 12 }}>{q.metric_key}</span>
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
      )}
    </Card>
  )
}
