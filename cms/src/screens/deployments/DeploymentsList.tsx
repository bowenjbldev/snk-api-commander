import { useMemo, useState, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { Button, Dropdown, OverflowMenu, OverflowMenuItem } from '@carbon/react'
import { Add, OverflowMenuVertical } from '@carbon/icons-react'
import {
  PageHeader,
  DataGrid,
  DeploymentStatusTag,
  Mono,
  Num,
  QuotaBar,
  OverBadge,
  quotaTone,
  RelativeTime,
  type GridColumn,
} from '../../components/ui'
import { useStore } from '../../store/DataStore'
import { useModals } from '../../components/modals/ModalHost'
import { useToast } from '../../notify/ToastProvider'
import { fmtInt, pct } from '../../lib/format'
import type { Deployment, DeploymentStatus } from '../../types'

type StateFilter = 'All' | DeploymentStatus
const STATE_ITEMS: StateFilter[] = ['All', 'active', 'grace', 'suspended']
const STATE_LABEL: Record<StateFilter, string> = {
  All: 'All states',
  active: 'Operational',
  grace: 'Grace',
  suspended: 'Suspended',
}

export function DeploymentsList() {
  const { deployments } = useStore()
  const m = useModals()
  const toast = useToast()
  const navigate = useNavigate()

  const [stateFilter, setStateFilter] = useState<StateFilter>('All')
  const [clientFilter, setClientFilter] = useState<string>('All')

  const clientItems = useMemo<string[]>(() => {
    const names = Array.from(new Set(deployments.map((d) => d.clientName)))
    names.sort((a, b) => a.localeCompare(b))
    return ['All', ...names]
  }, [deployments])

  const counts = useMemo(() => {
    let a = 0
    let g = 0
    let s = 0
    for (const d of deployments) {
      if (d.state.status === 'active') a++
      else if (d.state.status === 'grace') g++
      else s++
    }
    return { total: deployments.length, a, g, s }
  }, [deployments])

  const filteredRows = useMemo(
    () =>
      deployments.filter((d) => {
        if (stateFilter !== 'All' && d.state.status !== stateFilter) return false
        if (clientFilter !== 'All' && d.clientName !== clientFilter) return false
        return true
      }),
    [deployments, stateFilter, clientFilter],
  )

  const subtitle = `${counts.total} deployments · ${counts.a} operational · ${counts.g} in grace · ${counts.s} suspended`

  const columns: GridColumn<Deployment>[] = [
    {
      key: 'name',
      header: 'Deployment',
      width: 280,
      sortValue: (d) => d.name,
      render: (d) => (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
          <Mono color="var(--cds-link-primary)">{d.name}</Mono>
          <Mono size={11} color="var(--cds-text-helper)">
            {d.subdomain}
          </Mono>
        </div>
      ),
    },
    {
      key: 'client',
      header: 'Client',
      sortValue: (d) => d.clientName,
      render: (d) => d.clientName,
    },
    {
      key: 'package',
      header: 'Package',
      render: (d) => (
        <Mono size={12}>{d.packageName + ' ' + d.packageVersion}</Mono>
      ),
    },
    {
      key: 'state',
      header: 'State',
      sortValue: (d) => d.state.status,
      render: (d) => <DeploymentStatusTag state={d.state} size="sm" />,
    },
    {
      key: 'req',
      header: 'Req (24h)',
      align: 'right',
      sortValue: (d) => d.req24h,
      render: (d) => <Num>{fmtInt(d.req24h)}</Num>,
    },
    {
      key: 'quota',
      header: 'Quota health',
      sortValue: (d) => d.quotaHealth,
      render: (d) => {
        const tone = quotaTone(d.quotaHealth, 1)
        return (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <QuotaBar current={d.quotaHealth} limit={1} width={56} />
            {tone === 'over' ? (
              <OverBadge fraction={d.quotaHealth} />
            ) : (
              <Mono
                color={
                  tone === 'warn'
                    ? 'var(--sc-support-warning)'
                    : 'var(--cds-text-secondary)'
                }
              >
                {pct(d.quotaHealth)}
              </Mono>
            )}
          </div>
        )
      },
    },
    {
      key: 'lastSeen',
      header: 'Last seen',
      sortValue: (d) => d.lastSeen,
      render: (d) => <RelativeTime iso={d.lastSeen} />,
    },
  ]

  const rowOverflow = (d: Deployment): ReactNode => {
    const items: ReactNode[] = [
      <OverflowMenuItem
        key="open"
        itemText="Open deployment"
        onClick={() => navigate('/deployments/' + d.id)}
      />,
      <OverflowMenuItem
        key="copy"
        itemText="Copy base_url"
        onClick={() => {
          navigator.clipboard?.writeText(d.baseUrl)
          toast.success('Copied base_url')
        }}
      />,
    ]
    if (d.state.status !== 'suspended') {
      items.push(
        <OverflowMenuItem
          key="suspend"
          itemText="Suspend…"
          hasDivider
          onClick={() => m.openSuspend(d)}
        />,
        <OverflowMenuItem
          key="grace"
          itemText="Move to grace…"
          onClick={() => m.openGrace(d)}
        />,
      )
    }
    if (d.state.status !== 'active') {
      items.push(
        <OverflowMenuItem
          key="resume"
          itemText="Resume"
          onClick={() => m.openResume(d)}
        />,
      )
    }
    items.push(
      <OverflowMenuItem
        key="delete"
        itemText="Delete…"
        hasDivider
        isDelete
        onClick={() => m.openDelete(d)}
      />,
    )
    return (
      <OverflowMenu
        size="sm"
        flipped
        aria-label="Row actions"
        renderIcon={OverflowMenuVertical}
      >
        {items}
      </OverflowMenu>
    )
  }

  const toolbarContent = (
    <>
      <Dropdown<StateFilter>
        id="deployments-state-filter"
        type="inline"
        size="sm"
        label="All states"
        titleText="State"
        hideLabel
        items={STATE_ITEMS}
        selectedItem={stateFilter}
        itemToString={(item) => (item ? STATE_LABEL[item] : '')}
        onChange={({ selectedItem }) =>
          setStateFilter(selectedItem ?? 'All')
        }
      />
      <Dropdown<string>
        id="deployments-client-filter"
        type="inline"
        size="sm"
        label="All clients"
        titleText="Client"
        hideLabel
        items={clientItems}
        selectedItem={clientFilter}
        itemToString={(item) => (item === 'All' ? 'All clients' : (item ?? ''))}
        onChange={({ selectedItem }) =>
          setClientFilter(selectedItem ?? 'All')
        }
      />
    </>
  )

  return (
    <div className="sc-page">
      <PageHeader
        title="Deployments"
        subtitle={subtitle}
        right={
          <Button
            kind="primary"
            renderIcon={Add}
            onClick={() =>
              toast.success(
                'Create deployment',
                'Opens the create form (not yet built).',
              )
            }
          >
            Create deployment
          </Button>
        }
      />

      <DataGrid<Deployment>
        rows={filteredRows}
        getRowId={(d) => d.id}
        columns={columns}
        selectable
        size="lg"
        initialSort={{ key: 'name', dir: 'ASC' }}
        searchText={(d) =>
          `${d.name} ${d.subdomain} ${d.clientName} ${d.packageName}`
        }
        searchPlaceholder="Search deployments…"
        onRowClick={(d) => navigate('/deployments/' + d.id)}
        toolbarContent={toolbarContent}
        batchActions={(ids, clear) => {
          const sel = filteredRows.filter((d) => ids.includes(d.id))
          return [
            {
              label: 'Suspend',
              onClick: () => {
                m.openSuspendBatch(sel)
                clear()
              },
            },
            {
              label: 'Grace…',
              onClick: () => {
                m.openGraceBatch(sel)
                clear()
              },
            },
            {
              label: 'Resume',
              onClick: () => {
                m.openResumeBatch(sel)
                clear()
              },
            },
          ]
        }}
        rowOverflow={rowOverflow}
      />
    </div>
  )
}
