import { NavLink, Outlet, useNavigate, useParams } from 'react-router'
import { Button, OverflowMenu, OverflowMenuItem } from '@carbon/react'
import { Breadcrumbs } from '../../app/Breadcrumbs'
import { useStore, useDeployment } from '../../store/store'
import { useActions } from '../../providers/ActionsProvider'
import { useToast } from '../../providers/ToastProvider'
import { DeploymentStatusTag } from '../../components/tags/StatusTag'
import { EmptyState } from '../../components/EmptyState'

const TABS = [
  { label: 'Overview', to: 'overview' },
  { label: 'Usage', to: 'usage' },
  { label: 'Logs', to: 'logs' },
  { label: 'Settings', to: 'settings' },
]

export function DeploymentDetail() {
  const { id } = useParams()
  const dep = useDeployment(id)
  const clientName = useStore((s) => s.clients.find((c) => c.id === dep?.client_id)?.name)
  const actions = useActions()
  const toast = useToast()
  const navigate = useNavigate()

  if (!dep) {
    return (
      <div className="scns-page">
        <EmptyState
          title="Deployment not found"
          description="It may have been deleted or the URL is incorrect."
          action={{ label: 'Back to deployments', onClick: () => navigate('/deployments') }}
        />
      </div>
    )
  }

  const status = dep.state.status

  return (
    <div className="scns-page">
      <Breadcrumbs />
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16 }}>
        <div style={{ minWidth: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <h1 className="scns-mono" style={{ fontSize: 24, fontWeight: 400, margin: 0 }}>
              {dep.name}
            </h1>
            <DeploymentStatusTag state={dep.state} />
          </div>
          <div className="scns-helper" style={{ fontSize: 13, marginTop: 6 }}>
            {clientName} · <span className="scns-mono">{dep.package} {dep.version}</span> ·{' '}
            <span className="scns-mono">{dep.base_url}</span>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flex: 'none' }}>
          {status !== 'suspended' && (
            <Button kind="danger--tertiary" onClick={() => actions.suspend(dep)}>
              Suspend now
            </Button>
          )}
          {status !== 'active' ? (
            <Button kind="primary" onClick={() => actions.resume(dep)}>
              Resume deployment
            </Button>
          ) : (
            <Button kind="tertiary" onClick={() => actions.grace(dep)}>
              Move to grace
            </Button>
          )}
          <OverflowMenu size="md" flipped aria-label="More actions">
            <OverflowMenuItem
              itemText="Copy base_url"
              onClick={() => {
                navigator.clipboard?.writeText(dep.base_url)
                toast.success('Copied base_url', dep.base_url)
              }}
            />
            <OverflowMenuItem
              hasDivider
              isDelete
              itemText="Delete…"
              onClick={() => actions.deleteDeployment(dep, () => navigate('/deployments'))}
            />
          </OverflowMenu>
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

      <Outlet context={dep} />
    </div>
  )
}
