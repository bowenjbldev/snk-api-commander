import { useMemo } from 'react'
import { NavLink, Outlet, useNavigate, useParams } from 'react-router'
import { Tag } from '@carbon/react'
import { Breadcrumbs } from '../../app/Breadcrumbs'
import { useStore } from '../../store/store'
import { PackageStatusTag } from '../../components/tags/StatusTag'
import { EmptyState } from '../../components/EmptyState'
import type { PackageContext } from '../../store/types'

const TABS = [
  { label: 'Overview', to: 'overview' },
  { label: 'Plans', to: 'plans' },
  { label: 'Quotas', to: 'quotas' },
  { label: 'Usage', to: 'usage' },
  { label: 'Logs', to: 'logs' },
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
  const navigate = useNavigate()

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

  return (
    <div className="scns-page">
      <Breadcrumbs />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16 }}>
        <div style={{ minWidth: 0 }}>
          <h1 style={{ fontSize: 24, fontWeight: 400, margin: 0 }}>{pkg.label ?? pkg.name}</h1>
          <div className="scns-helper" style={{ fontSize: 13, marginTop: 6 }}>
            <span className="scns-mono">{pkg.name}</span> · {plans.length} {plans.length === 1 ? 'plan' : 'plans'} ·{' '}
            {packageRow ? 'provisioned' : 'not provisioned'}
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 12 }}>
            <Tag type={pkg.listing_status === 'active' ? 'green' : 'gray'} size="sm">
              {pkg.listing_status}
            </Tag>
            {packageRow && <PackageStatusTag pkg={packageRow} />}
          </div>
        </div>
      </div>

      <nav
        style={{
          display: 'flex',
          gap: 24,
          borderBottom: '1px solid var(--cds-border-subtle)',
          margin: '24px 0',
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
    </div>
  )
}
