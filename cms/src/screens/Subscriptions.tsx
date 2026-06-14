import { useNavigate } from 'react-router-dom'
import { Tag } from '@carbon/react'
import {
  AmberTag,
  DataGrid,
  DeploymentStatusTag,
  Mono,
  Num,
  PageHeader,
  type GridColumn,
} from '../components/ui'
import { useStore } from '../store/DataStore'
import { subscriptions as allSubscriptions } from '../data/dataset'
import { fmtUSD } from '../lib/format'
import type { Subscription } from '../types'

interface Row extends Subscription {
  clientName: string
  deploymentName: string
}

function SubStatusTag({ status }: { status: Subscription['status'] }) {
  if (status === 'active')
    return (
      <Tag type="green" size="sm">
        Active
      </Tag>
    )
  if (status === 'past_due') return <AmberTag size="sm">Past due</AmberTag>
  return (
    <Tag type="gray" size="sm">
      Canceled
    </Tag>
  )
}

export function Subscriptions() {
  const navigate = useNavigate()
  const { deployments } = useStore()
  const byId = Object.fromEntries(deployments.map((d) => [d.id, d]))

  const rows: Row[] = allSubscriptions
    .filter((s) => byId[s.deploymentId])
    .map((s) => ({
      ...s,
      clientName: byId[s.deploymentId].clientName,
      deploymentName: byId[s.deploymentId].name,
    }))

  const mrr = rows
    .filter((s) => s.status !== 'canceled')
    .reduce((sum, s) => sum + s.pricePerMonth, 0)

  const columns: GridColumn<Row>[] = [
    {
      key: 'deployment',
      header: 'Deployment',
      sortValue: (r) => r.deploymentName,
      render: (r) => (
        <Mono color="var(--cds-link-primary)">{r.deploymentName}</Mono>
      ),
    },
    {
      key: 'client',
      header: 'Client',
      sortValue: (r) => r.clientName,
      render: (r) => r.clientName,
    },
    { key: 'plan', header: 'Plan', sortValue: (r) => r.plan, render: (r) => r.plan },
    {
      key: 'price',
      header: 'Price / mo',
      align: 'right',
      sortValue: (r) => r.pricePerMonth,
      render: (r) => <Num>{fmtUSD(r.pricePerMonth)}</Num>,
    },
    {
      key: 'state',
      header: 'State',
      render: (r) => <DeploymentStatusTag state={byId[r.deploymentId].state} size="sm" />,
    },
    {
      key: 'status',
      header: 'Subscription',
      sortValue: (r) => r.status,
      render: (r) => <SubStatusTag status={r.status} />,
    },
  ]

  return (
    <div className="sc-page">
      <PageHeader
        title="Subscriptions"
        subtitle={`${rows.length} subscriptions · MRR ${fmtUSD(mrr)}`}
      />
      <DataGrid
        rows={rows}
        getRowId={(r) => r.id}
        columns={columns}
        searchText={(r) => `${r.deploymentName} ${r.clientName} ${r.plan}`}
        searchPlaceholder="Search subscriptions…"
        initialSort={{ key: 'deployment', dir: 'ASC' }}
        onRowClick={(r) => navigate('/deployments/' + r.deploymentId)}
      />
    </div>
  )
}
