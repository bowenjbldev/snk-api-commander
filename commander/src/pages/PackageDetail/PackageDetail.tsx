import { useMemo, useState } from 'react'
import { NavLink, Outlet, useNavigate, useParams } from 'react-router'
import { MenuButton, MenuItem, MenuItemDivider, Tag } from '@carbon/react'
import { Breadcrumbs } from '../../app/Breadcrumbs'
import { useStore } from '../../store/store'
import { useCan } from '../../store/auth'
import { useActions } from '../../providers/ActionsProvider'
import { PackageStatusTag } from '../../components/tags/StatusTag'
import { EmptyState } from '../../components/EmptyState'
import { CopyableCode } from '../../components/CopyableCode'
import { ChangePlanModal } from '../../components/modals/ChangePlanModal'
import { packageBaseUrl } from '../../lib/package'
import type { PackageContext } from '../../store/types'

const TABS = [
  { label: 'Overview', to: 'overview' },
  { label: 'Plans', to: 'plans' },
  { label: 'Quotas', to: 'quotas' },
  { label: 'Usage', to: 'usage' },
  { label: 'Logs', to: 'logs' },
  { label: 'Insights', to: 'insights' },
  { label: 'Settings', to: 'settings' },
]

export function PackageDetail() {
  const { id } = useParams()
  const packages = useStore((s) => s.packages)
  const pkg = useMemo(
    () => (id != null ? packages.find((p) => p.id === Number(id)) : undefined),
    [packages, id],
  )
  const packageRow = pkg?.provisioned_at != null ? pkg : undefined
  const plansAll = useStore((s) => s.plans)
  const plans = useMemo(() => plansAll.filter((p) => p.package === pkg?.name), [plansAll, pkg?.name])
  const subscriptions = useStore((s) => s.subscriptions)
  const subscription = packageRow
    ? subscriptions.find((s) => s.id === packageRow.subscription_id)
    : undefined
  const navigate = useNavigate()
  const actions = useActions()
  const canControl = useCan()('control packages')
  const [changePlanOpen, setChangePlanOpen] = useState(false)

  if (!pkg) {
    return (
      <div className="scns-page">
        <EmptyState
          title="Package not found"
          description="It may not be registered yet, or the URL is incorrect."
          action={{ label: 'Back to packages', onClick: () => navigate('/packages') }}
        />
      </div>
    )
  }

  const ctx: PackageContext = { pkg, packageRow, plans }

  // Page-level Actions menu: instance lifecycle (resume/grace/suspend) + change plan. These act on
  // the whole live instance, so they live in the page header (not on the catalog card). Live only.
  const actionsMenu =
    packageRow && canControl ? (
      <MenuButton label="Actions" kind="tertiary" size="md" menuAlignment="bottom-end">
        <MenuItem label="Change plan…" onClick={() => setChangePlanOpen(true)} />
        <MenuItemDivider />
        {packageRow.status !== 'active' && (
          <MenuItem label="Resume now" onClick={() => actions.resume(packageRow)} />
        )}
        {packageRow.status === 'active' && (
          <MenuItem label="Move to grace…" onClick={() => actions.grace(packageRow)} />
        )}
        {packageRow.status === 'grace' && (
          <MenuItem label="Extend grace…" onClick={() => actions.grace(packageRow)} />
        )}
        {packageRow.status !== 'suspended' && <MenuItemDivider />}
        {packageRow.status !== 'suspended' && (
          <MenuItem label="Suspend now" kind="danger" onClick={() => actions.suspend(packageRow)} />
        )}
      </MenuButton>
    ) : null

  return (
    <div className="scns-page">
      <Breadcrumbs />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16 }}>
        <div style={{ minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
            <h1 style={{ fontSize: 24, fontWeight: 400, margin: 0 }}>{pkg.label ?? pkg.name}</h1>
            <div className="scns-title-tags" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
              <Tag type={pkg.listing_status === 'active' ? 'green' : 'gray'} size="sm">
                {pkg.listing_status}
              </Tag>
              {packageRow && <PackageStatusTag pkg={packageRow} />}
            </div>
          </div>
          <div className="scns-helper" style={{ marginTop: 6 }}>
            Business package
            {pkg.label && pkg.label !== pkg.name ? <> · <span className="scns-mono">{pkg.name}</span></> : null}
            {' · '}{plans.length} {plans.length === 1 ? 'plan' : 'plans'} ·{' '}
            {packageRow ? 'live deployment' : 'catalog only'}
          </div>
          {packageRow && (
            <div style={{ marginTop: 6 }}>
              <CopyableCode value={packageBaseUrl(packageRow)} />
            </div>
          )}
        </div>
        {actionsMenu && <div style={{ flex: 'none' }}>{actionsMenu}</div>}
      </div>

      <nav
        style={{
          display: 'flex',
          gap: 24,
          borderBottom: '1px solid var(--scns-border)',
          margin: '16px 0 24px',
        }}
      >
        {TABS.map((t) => (
          <NavLink
            key={t.to}
            to={t.to}
            style={({ isActive }) => ({
              padding: '10px 0',
              textDecoration: 'none',
              color: isActive ? 'var(--cds-text-primary)' : 'var(--cds-text-secondary)',
              borderBottom: isActive ? '2px solid var(--cds-border-interactive)' : '2px solid transparent',
              fontWeight: isActive ? 600 : 400,
            })}
          >
            {t.label}
          </NavLink>
        ))}
      </nav>

      <Outlet context={ctx} />

      {changePlanOpen && packageRow && (
        <ChangePlanModal
          open
          packageName={pkg.name}
          plans={plans}
          currentPlanId={subscription?.plan_id ?? null}
          onClose={() => setChangePlanOpen(false)}
          onChanged={() => setChangePlanOpen(false)}
        />
      )}
    </div>
  )
}
