import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router'
import { Dropdown, Tag } from '@carbon/react'
import { Rocket } from '@carbon/icons-react'
import { useStore } from '../store/store'
import { PageHeader } from '../components/PageHeader'
import { DataTableShell, type ShellColumn } from '../components/DataTableShell'
import { useFirstLoad } from '../hooks/useFirstLoad'
import { BillingTag } from '../components/tags/StatusTag'
import { ProvisionModal } from '../components/modals/ProvisionModal'
import { formatCurrency, formatCurrencyShort } from '../lib/format'
import type { Subscription } from '../store/types'

const STATUS_ITEMS = [
  { id: 'all', label: 'Billing: All' },
  { id: 'paid', label: 'Billing: Paid' },
  { id: 'pending', label: 'Billing: Pending' },
  { id: 'overdue', label: 'Billing: Overdue' },
]

export function Subscriptions() {
  const subscriptions = useStore((s) => s.subscriptions)
  const clients = useStore((s) => s.clients)
  const deployments = useStore((s) => s.deployments)
  const navigate = useNavigate()
  const [statusFilter, setStatusFilter] = useState('all')
  const [provisionOpen, setProvisionOpen] = useState(false)

  const clientName = useMemo(() => {
    const m = new Map(clients.map((c) => [c.id, c.name]))
    return (id: number) => m.get(id) ?? String(id)
  }, [clients])

  const filtered = useMemo(
    () => subscriptions.filter((s) => statusFilter === 'all' || s.billing_status === statusFilter),
    [subscriptions, statusFilter],
  )
  const mrr = useMemo(() => subscriptions.reduce((s, sub) => s + sub.price_monthly, 0), [subscriptions])

  const columns: ShellColumn<Subscription>[] = [
    {
      key: 'deployment',
      header: 'Deployment',
      sortable: true,
      sortValue: (s) => s.deployment_id,
      render: (s) => (
        <button
          type="button"
          className="scns-mono"
          style={{ background: 'none', border: 'none', padding: 0, cursor: 'pointer', color: 'var(--cds-link-primary)' }}
          onClick={() => {
            const pkg = deployments.find((d) => d.id === s.deployment_id)?.package.name
            navigate(pkg ? `/packages/${pkg}` : '/packages')
          }}
        >
          {s.deployment_id}
        </button>
      ),
    },
    { key: 'client', header: 'Client', sortable: true, sortValue: (s) => clientName(s.client_id), render: (s) => clientName(s.client_id) },
    { key: 'plan', header: 'Plan', sortable: true, sortValue: (s) => s.plan, render: (s) => s.plan },
    {
      key: 'price',
      header: 'Price / mo',
      sortable: true,
      sortValue: (s) => s.price_monthly,
      align: 'right',
      render: (s) => <span className="scns-num">{formatCurrency(s.price_monthly)}</span>,
    },
    { key: 'billing', header: 'Billing', sortable: true, sortValue: (s) => s.billing_status, width: 130, render: (s) => <BillingTag status={s.billing_status} /> },
    {
      key: 'auto',
      header: 'Auto-suspend',
      sortable: true,
      sortValue: (s) => String(s.auto_suspend),
      render: (s) => <Tag type={s.auto_suspend ? 'blue' : 'gray'} size="sm">{s.auto_suspend ? 'On' : 'Off'}</Tag>,
    },
  ]

  return (
    <div className="scns-page">
      <PageHeader
        title="Subscriptions"
        subtitle={`${subscriptions.length} subscriptions · ${formatCurrencyShort(mrr)}/mo total`}
        primaryAction={{ label: 'New subscription', icon: Rocket, onClick: () => setProvisionOpen(true) }}
      />
      <DataTableShell
        rows={filtered}
        columns={columns}
        getId={(s) => s.id}
        searchText={(s) => `${s.deployment_id} ${clientName(s.client_id)} ${s.plan}`}
        searchPlaceholder="Search subscriptions"
        initialSort={{ key: 'deployment', dir: 'ASC' }}
        itemLabel="subscriptions"
        loading={useFirstLoad()}
        filters={
          <Dropdown
            id="filter-sub-status"
            type="inline"
            size="lg"
            hideLabel
            titleText="Filter by billing"
            label="Billing: All"
            items={STATUS_ITEMS}
            itemToString={(i) => i?.label ?? ''}
            selectedItem={STATUS_ITEMS.find((i) => i.id === statusFilter)}
            onChange={({ selectedItem }) => setStatusFilter(selectedItem?.id ?? 'all')}
          />
        }
      />
      {provisionOpen && <ProvisionModal open onClose={() => setProvisionOpen(false)} />}
    </div>
  )
}
