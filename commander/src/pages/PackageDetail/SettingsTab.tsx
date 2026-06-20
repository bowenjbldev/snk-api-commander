import { useNavigate, useOutletContext, Link } from 'react-router'
import {
  Button,
  StructuredListWrapper,
  StructuredListBody,
  StructuredListRow,
  StructuredListCell,
} from '@carbon/react'
import type { Deployment, PackageContext } from '../../store/types'
import { useStore } from '../../store/store'
import { useCan } from '../../store/auth'
import { useActions } from '../../providers/ActionsProvider'
import { CopyableCode } from '../../components/CopyableCode'
import { EmptyState } from '../../components/EmptyState'
import { formatDateUTC } from '../../lib/time'

export function SettingsTab() {
  const { deployment } = useOutletContext<PackageContext>()

  if (!deployment) {
    return (
      <EmptyState
        title="No deployment yet — provision one to manage its settings"
        description="Deployment settings and the danger zone appear here once this package is deployed."
      />
    )
  }

  return <SettingsView dep={deployment} />
}

function SettingsView({ dep }: { dep: Deployment }) {
  const navigate = useNavigate()
  const actions = useActions()
  const can = useCan()
  const subscription = useStore((s) => s.subscriptions.find((sub) => sub.id === dep.subscription_id))

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 24, maxWidth: 640 }}>
      <div className="scns-card" style={{ padding: 16 }}>
        <div style={{ fontSize: 16, fontWeight: 600, marginBottom: 8 }}>Deployment</div>
        <StructuredListWrapper isCondensed>
          <StructuredListBody>
            <StructuredListRow>
              <StructuredListCell noWrap style={{ width: 160, color: 'var(--cds-text-secondary)' }}>
                Name
              </StructuredListCell>
              <StructuredListCell>
                <span className="scns-mono">{dep.name}</span>
              </StructuredListCell>
            </StructuredListRow>
            <StructuredListRow>
              <StructuredListCell noWrap style={{ width: 160, color: 'var(--cds-text-secondary)' }}>
                Subdomain
              </StructuredListCell>
              <StructuredListCell>
                <span className="scns-mono">{dep.subdomain}</span>
              </StructuredListCell>
            </StructuredListRow>
            <StructuredListRow>
              <StructuredListCell noWrap style={{ width: 160, color: 'var(--cds-text-secondary)' }}>
                Package
              </StructuredListCell>
              <StructuredListCell>
                <span className="scns-mono">{dep.package}</span>
              </StructuredListCell>
            </StructuredListRow>
            <StructuredListRow>
              <StructuredListCell noWrap style={{ width: 160, color: 'var(--cds-text-secondary)' }}>
                Subscription
              </StructuredListCell>
              <StructuredListCell>
                {subscription ? <Link className="scns-link" to="/subscriptions">{subscription.plan}</Link> : '—'}
              </StructuredListCell>
            </StructuredListRow>
            <StructuredListRow>
              <StructuredListCell noWrap style={{ width: 160, color: 'var(--cds-text-secondary)' }}>
                Created
              </StructuredListCell>
              <StructuredListCell>
                <span className="scns-mono">{formatDateUTC(dep.created_at)}</span>
              </StructuredListCell>
            </StructuredListRow>
          </StructuredListBody>
        </StructuredListWrapper>
        <div style={{ marginTop: 12 }}>
          <div className="scns-helper" style={{ fontSize: 12, marginBottom: 6 }}>
            base_url
          </div>
          <CopyableCode value={dep.base_url} />
        </div>
      </div>

      {can('delete deployments') && (
        <div className="scns-card" style={{ padding: 16, borderLeft: '3px solid var(--scns-error)' }}>
          <div style={{ fontSize: 16, fontWeight: 600, marginBottom: 8 }}>Danger zone</div>
          <p className="scns-helper" style={{ fontSize: 13, marginBottom: 12 }}>
            Deleting removes the deployment, its quotas and subscription link. This cannot be undone.
          </p>
          <Button kind="danger--tertiary" onClick={() => actions.deleteDeployment(dep, () => navigate('/packages'))}>
            Delete deployment…
          </Button>
        </div>
      )}
    </div>
  )
}
