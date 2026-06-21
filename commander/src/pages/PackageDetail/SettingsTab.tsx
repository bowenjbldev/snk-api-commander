import { useNavigate, useOutletContext } from 'react-router'
import {
  Button,
  StructuredListWrapper,
  StructuredListBody,
  StructuredListRow,
  StructuredListCell,
} from '@carbon/react'
import type { Package, PackageContext } from '../../store/types'
import { useStore } from '../../store/store'
import { useCan } from '../../store/auth'
import { useActions } from '../../providers/ActionsProvider'
import { CopyableCode } from '../../components/CopyableCode'
import { EmptyState } from '../../components/EmptyState'
import { formatDateUTC } from '../../lib/time'
import { packageBaseUrl, packageFqdn } from '../../lib/package'

export function SettingsTab() {
  const { packageRow } = useOutletContext<PackageContext>()

  if (!packageRow) {
    return (
      <EmptyState
        title="No live instance yet — provision one to manage its settings"
        description="Deployment settings and the danger zone appear here once this package is deployed."
      />
    )
  }

  return <SettingsView pkg={packageRow} />
}

function SettingsView({ pkg }: { pkg: Package }) {
  const navigate = useNavigate()
  const actions = useActions()
  const can = useCan()
  const subscription = useStore((s) => s.subscriptions.find((sub) => sub.id === pkg.subscription_id))

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 24, maxWidth: 640 }}>
      <div className="scns-card" style={{ padding: 16 }}>
        <div style={{ fontSize: 16, fontWeight: 600, marginBottom: 8 }}>Package</div>
        <StructuredListWrapper isCondensed>
          <StructuredListBody>
            <StructuredListRow>
              <StructuredListCell noWrap style={{ width: 160, color: 'var(--cds-text-secondary)' }}>
                Name
              </StructuredListCell>
              <StructuredListCell>
                <span className="scns-mono">{pkg.name}</span>
              </StructuredListCell>
            </StructuredListRow>
            <StructuredListRow>
              <StructuredListCell noWrap style={{ width: 160, color: 'var(--cds-text-secondary)' }}>
                Subdomain
              </StructuredListCell>
              <StructuredListCell>
                <span className="scns-mono">{packageFqdn(pkg)}</span>
              </StructuredListCell>
            </StructuredListRow>
            <StructuredListRow>
              <StructuredListCell noWrap style={{ width: 160, color: 'var(--cds-text-secondary)' }}>
                Package
              </StructuredListCell>
              <StructuredListCell>
                <span className="scns-mono">{pkg.name}</span>
              </StructuredListCell>
            </StructuredListRow>
            <StructuredListRow>
              <StructuredListCell noWrap style={{ width: 160, color: 'var(--cds-text-secondary)' }}>
                Subscription
              </StructuredListCell>
              <StructuredListCell>
                {subscription ? subscription.plan : '—'}
              </StructuredListCell>
            </StructuredListRow>
            <StructuredListRow>
              <StructuredListCell noWrap style={{ width: 160, color: 'var(--cds-text-secondary)' }}>
                Created
              </StructuredListCell>
              <StructuredListCell>
                <span className="scns-mono">{formatDateUTC(pkg.created_at)}</span>
              </StructuredListCell>
            </StructuredListRow>
          </StructuredListBody>
        </StructuredListWrapper>
        <div style={{ marginTop: 12 }}>
          <div className="scns-helper" style={{ fontSize: 12, marginBottom: 6 }}>
            base_url
          </div>
          <CopyableCode value={packageBaseUrl(pkg)} />
        </div>
      </div>

      {can('delete packages') && (
        <div className="scns-card" style={{ padding: 16, borderLeft: '3px solid var(--scns-error)' }}>
          <div style={{ fontSize: 16, fontWeight: 600, marginBottom: 8 }}>Danger zone</div>
          <p className="scns-helper" style={{ fontSize: 13, marginBottom: 12 }}>
            De-provisioning removes the package's live instance — its database, quotas and subscription link. This cannot be undone.
          </p>
          <Button kind="danger--tertiary" onClick={() => actions.deprovision(pkg, () => navigate('/packages'))}>
            De-provision package…
          </Button>
        </div>
      )}
    </div>
  )
}
