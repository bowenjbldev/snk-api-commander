import {
  Breadcrumb,
  BreadcrumbItem,
  Button,
  OverflowMenu,
  OverflowMenuItem,
  Tab,
  TabList,
  TabPanel,
  TabPanels,
  Tabs,
} from '@carbon/react'
import { ArrowRight, OverflowMenuVertical } from '@carbon/icons-react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  Card,
  DeploymentStatusTag,
  EmptyState,
  Mono,
} from '../../components/ui'
import { useStore } from '../../store/DataStore'
import { useModals } from '../../components/modals/ModalHost'
import { OverviewTab } from './OverviewTab'
import { UsageTab } from './UsageTab'

export function DeploymentDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const m = useModals()
  const { deployments, quotas } = useStore()
  const dep = deployments.find((d) => d.id === id)

  if (!dep) {
    return (
      <div className="sc-page">
        <EmptyState
          title="Deployment not found"
          action={
            <Button as={Link} to="/deployments">
              Back
            </Button>
          }
        />
      </div>
    )
  }

  const status = dep.state.status

  return (
    <div className="sc-page">
      <Breadcrumb noTrailingSlash style={{ marginBottom: 16 }}>
        <BreadcrumbItem>
          <Link to="/deployments">Deployments</Link>
        </BreadcrumbItem>
        <BreadcrumbItem isCurrentPage>{dep.name}</BreadcrumbItem>
      </Breadcrumb>

      <div
        style={{
          display: 'flex',
          alignItems: 'flex-start',
          justifyContent: 'space-between',
          gap: 16,
          flexWrap: 'wrap',
          marginBottom: 24,
        }}
      >
        <div style={{ minWidth: 0 }}>
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 12,
              flexWrap: 'wrap',
            }}
          >
            <h1
              className="sc-mono"
              style={{
                margin: 0,
                fontSize: 24,
                fontWeight: 400,
                lineHeight: 1.2,
              }}
            >
              {dep.name}
            </h1>
            <DeploymentStatusTag state={dep.state} />
          </div>
          <div
            style={{
              marginTop: 8,
              fontSize: 13,
              color: 'var(--cds-text-helper)',
              display: 'flex',
              alignItems: 'center',
              gap: 8,
              flexWrap: 'wrap',
            }}
          >
            <Link
              to={`/clients?focus=${dep.clientId}`}
              style={{ color: 'var(--cds-link-primary)' }}
            >
              {dep.clientName}
            </Link>
            <span>·</span>
            <Mono>
              {dep.packageName} {dep.packageVersion}
            </Mono>
            <span>·</span>
            <Mono color="var(--cds-link-primary)">{dep.baseUrl}</Mono>
          </div>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          {status !== 'suspended' && (
            <Button kind="danger--tertiary" onClick={() => m.openSuspend(dep)}>
              Suspend now
            </Button>
          )}
          {status !== 'active' && (
            <Button kind="primary" onClick={() => m.openResume(dep)}>
              Resume deployment
            </Button>
          )}
          <OverflowMenu
            size="sm"
            flipped
            renderIcon={OverflowMenuVertical}
            aria-label="Deployment actions"
          >
            {status === 'active' && (
              <OverflowMenuItem
                itemText="Move to grace…"
                onClick={() => m.openGrace(dep)}
              />
            )}
            {status === 'grace' && (
              <OverflowMenuItem
                itemText="Extend grace…"
                onClick={() => m.openGrace(dep, 'extend')}
              />
            )}
            <OverflowMenuItem
              itemText="Rotate secret…"
              onClick={() => m.openRotateSecret(dep)}
            />
            <OverflowMenuItem
              itemText="Delete…"
              isDelete
              hasDivider
              onClick={() => m.openDelete(dep, () => navigate('/deployments'))}
            />
          </OverflowMenu>
        </div>
      </div>

      <Tabs>
        <TabList contained={false} aria-label="Detail tabs">
          <Tab>Overview</Tab>
          <Tab>Usage</Tab>
          <Tab>Logs</Tab>
          <Tab>Settings</Tab>
        </TabList>
        <TabPanels>
          <TabPanel style={{ paddingInline: 0 }}>
            <div style={{ marginTop: 24 }}>
              <OverviewTab dep={dep} quotas={quotas} />
            </div>
          </TabPanel>

          <TabPanel style={{ paddingInline: 0 }}>
            <div style={{ marginTop: 24 }}>
              <UsageTab deploymentId={dep.id} />
            </div>
          </TabPanel>

          <TabPanel style={{ paddingInline: 0 }}>
            <div style={{ marginTop: 24 }}>
              <p
                style={{
                  margin: '0 0 16px',
                  fontSize: 13,
                  color: 'var(--cds-text-secondary)',
                }}
              >
                Full request log filtered to this deployment.
              </p>
              <Button
                as={Link}
                to={`/logs?deployment=${dep.id}`}
                kind="tertiary"
                renderIcon={ArrowRight}
              >
                Open in Request Logs
              </Button>
            </div>
          </TabPanel>

          <TabPanel style={{ paddingInline: 0 }}>
            <div
              style={{
                marginTop: 24,
                display: 'flex',
                flexDirection: 'column',
                gap: 24,
                maxWidth: 640,
              }}
            >
              <Card title="Settings">
                <div
                  style={{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 14,
                  }}
                >
                  <div>
                    <div
                      style={{
                        fontSize: 12,
                        color: 'var(--cds-text-helper)',
                        marginBottom: 4,
                      }}
                    >
                      Deployment name
                    </div>
                    <Mono>{dep.name}</Mono>
                  </div>
                  <div>
                    <div
                      style={{
                        fontSize: 12,
                        color: 'var(--cds-text-helper)',
                        marginBottom: 4,
                      }}
                    >
                      Package
                    </div>
                    <Mono>
                      {dep.packageName} {dep.packageVersion}
                    </Mono>
                  </div>
                </div>
              </Card>

              <Card title="Danger zone" accent="var(--sc-support-error)">
                <Button
                  kind="danger"
                  onClick={() =>
                    m.openDelete(dep, () => navigate('/deployments'))
                  }
                >
                  Delete deployment
                </Button>
                <div
                  style={{
                    fontSize: 12,
                    color: 'var(--cds-text-helper)',
                    marginTop: 10,
                  }}
                >
                  Requires typed confirmation.
                </div>
              </Card>
            </div>
          </TabPanel>
        </TabPanels>
      </Tabs>
    </div>
  )
}
