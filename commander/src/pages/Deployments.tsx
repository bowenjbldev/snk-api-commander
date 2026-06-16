import { useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { Dropdown, OverflowMenu, OverflowMenuItem } from '@carbon/react'
import { useStore } from '../store/store'
import { useActions } from '../providers/ActionsProvider'
import { useToast } from '../providers/ToastProvider'
import { PageHeader } from '../components/PageHeader'
import { DataTableShell, type ShellColumn } from '../components/DataTableShell'
import { useFirstLoad } from '../hooks/useFirstLoad'
import { DeploymentStatusTag } from '../components/tags/StatusTag'
import { QuotaBar } from '../components/QuotaBar'
import { RelativeTime } from '../components/RelativeTime'
import { formatInt } from '../lib/format'
import type { Deployment } from '../store/types'

const STATE_ITEMS = [
  { id: 'all', label: 'State: All' },
  { id: 'active', label: 'State: Operational' },
  { id: 'grace', label: 'State: Grace' },
  { id: 'suspended', label: 'State: Suspended' },
]

export function Deployments() {
  const deployments = useStore((s) => s.deployments)
  const clients = useStore((s) => s.clients)
  const actions = useActions()
  const toast = useToast()
  const navigate = useNavigate()

  const [stateFilter, setStateFilter] = useState('all')
  const [clientFilter, setClientFilter] = useState('all')

  const clientName = useMemo(() => {
    const m = new Map(clients.map((c) => [c.id, c.name]))
    return (id: string) => m.get(id) ?? id
  }, [clients])

  const byId = useMemo(() => new Map(deployments.map((d) => [d.id, d])), [deployments])

  const clientItems = useMemo(
    () => [{ id: 'all', label: 'Client: All' }, ...clients.map((c) => ({ id: c.id, label: `Client: ${c.name}` }))],
    [clients],
  )

  const filtered = useMemo(
    () =>
      deployments.filter(
        (d) =>
          (stateFilter === 'all' || d.state.status === stateFilter) &&
          (clientFilter === 'all' || d.client_id === clientFilter),
      ),
    [deployments, stateFilter, clientFilter],
  )

  const counts = useMemo(() => {
    let active = 0,
      grace = 0,
      suspended = 0
    for (const d of deployments) {
      if (d.state.status === 'active') active++
      else if (d.state.status === 'grace') grace++
      else suspended++
    }
    return { active, grace, suspended }
  }, [deployments])

  const toTargets = (ids: string[]) => ids.map((id) => byId.get(id)).filter(Boolean) as Deployment[]

  const columns: ShellColumn<Deployment>[] = [
    {
      key: 'name',
      header: 'Deployment',
      sortable: true,
      sortValue: (d) => d.name,
      width: 240,
      render: (d) => (
        <div style={{ lineHeight: 1.3 }}>
          <Link to={`/deployments/${d.id}`} className="scns-mono scns-link">
            {d.name}
          </Link>
          <div className="scns-mono scns-helper" style={{ fontSize: 11 }}>
            {d.subdomain}
          </div>
        </div>
      ),
    },
    { key: 'client', header: 'Client', sortable: true, sortValue: (d) => clientName(d.client_id), render: (d) => clientName(d.client_id) },
    {
      key: 'package',
      header: 'Package',
      sortable: true,
      sortValue: (d) => d.package,
      render: (d) => (
        <span className="scns-mono" style={{ fontSize: 12 }}>
          {d.package} {d.version}
        </span>
      ),
    },
    { key: 'state', header: 'State', sortable: true, sortValue: (d) => d.state.status, width: 170, render: (d) => <DeploymentStatusTag state={d.state} /> },
    {
      key: 'req',
      header: 'Req (24h)',
      sortable: true,
      sortValue: (d) => d.requests_24h,
      align: 'right',
      render: (d) => <span className="scns-num">{formatInt(d.requests_24h)}</span>,
    },
    {
      key: 'quota',
      header: 'Quota health',
      sortable: true,
      sortValue: (d) => d.quota_health,
      width: 150,
      render: (d) => <QuotaBar current={d.quota_health} limit={1} width={56} showLabel />,
    },
    {
      key: 'seen',
      header: 'Last seen',
      sortable: true,
      sortValue: (d) => d.last_seen,
      render: (d) => <RelativeTime iso={d.last_seen} />,
    },
  ]

  return (
    <div className="scns-page">
      <PageHeader
        title="Deployments"
        subtitle={
          <>
            {deployments.length} deployments · {counts.active} operational · {counts.grace} in grace ·{' '}
            {counts.suspended} suspended
          </>
        }
      />
      <DataTableShell
        rows={filtered}
        columns={columns}
        getId={(d) => d.id}
        searchText={(d) => `${d.name} ${d.subdomain} ${clientName(d.client_id)} ${d.package}`}
        searchPlaceholder="Search deployments"
        initialSort={{ key: 'name', dir: 'ASC' }}
        itemLabel="deployments"
        loading={useFirstLoad()}
        onRowClick={(d) => navigate(`/deployments/${d.id}`)}
        filters={
          <>
            <Dropdown
              id="filter-state"
              type="inline"
              size="lg"
              label="State: All"
              titleText="Filter by state"
              hideLabel
              items={STATE_ITEMS}
              itemToString={(i) => i?.label ?? ''}
              selectedItem={STATE_ITEMS.find((i) => i.id === stateFilter)}
              onChange={({ selectedItem }) => setStateFilter(selectedItem?.id ?? 'all')}
            />
            <Dropdown
              id="filter-client"
              type="inline"
              size="lg"
              label="Client: All"
              titleText="Filter by client"
              hideLabel
              items={clientItems}
              itemToString={(i) => i?.label ?? ''}
              selectedItem={clientItems.find((i) => i.id === clientFilter)}
              onChange={({ selectedItem }) => setClientFilter(selectedItem?.id ?? 'all')}
            />
          </>
        }
        batchActions={[
          { label: 'Suspend', onClick: (ids) => actions.suspend(toTargets(ids)) },
          { label: 'Grace…', onClick: (ids) => actions.grace(toTargets(ids)) },
          { label: 'Resume', onClick: (ids) => actions.resume(toTargets(ids)) },
        ]}
        rowActions={(d) => (
          <OverflowMenu size="sm" flipped aria-label={`Actions for ${d.name}`} onClick={(e) => e.stopPropagation()}>
            <OverflowMenuItem itemText="Open deployment" onClick={() => navigate(`/deployments/${d.id}`)} />
            <OverflowMenuItem
              itemText="Copy base_url"
              onClick={() => {
                navigator.clipboard?.writeText(d.base_url)
                toast.success('Copied base_url', d.base_url)
              }}
            />
            {d.state.status !== 'active' && (
              <OverflowMenuItem hasDivider itemText="Resume" onClick={() => actions.resume(d)} />
            )}
            <OverflowMenuItem hasDivider={d.state.status === 'active'} itemText="Suspend…" onClick={() => actions.suspend(d)} />
            <OverflowMenuItem itemText="Move to grace…" onClick={() => actions.grace(d)} />
            <OverflowMenuItem hasDivider isDelete itemText="Delete…" onClick={() => actions.deleteDeployment(d)} />
          </OverflowMenu>
        )}
      />
    </div>
  )
}
