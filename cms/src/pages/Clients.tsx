import { useMemo, useState, type ReactNode } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { Dropdown, Button, InlineNotification } from '@carbon/react'
import { Add } from '@carbon/icons-react'
import { useStore } from '../store/store'
import { useToast } from '../providers/ToastProvider'
import { PageHeader } from '../components/PageHeader'
import { DataTableShell, type ShellColumn } from '../components/DataTableShell'
import { useFirstLoad } from '../hooks/useFirstLoad'
import { ClientStatusTag, DeploymentStatusTag } from '../components/tags/StatusTag'
import { Drawer } from '../components/Drawer'
import { ClientFormModal } from '../components/modals/ClientFormModal'
import { CreateDeploymentModal } from '../components/modals/CreateDeploymentModal'
import { formatMonthYear, formatAbsoluteUTC, formatRelative } from '../lib/time'
import { formatCurrency } from '../lib/format'
import type { Client, Deployment, Invoice } from '../store/types'

const STATUS_ITEMS = [
  { id: 'all', label: 'Status: All' },
  { id: 'active', label: 'Status: Active' },
  { id: 'inactive', label: 'Status: Inactive' },
]

export function Clients() {
  const clients = useStore((s) => s.clients)
  const deployments = useStore((s) => s.deployments)
  const invoices = useStore((s) => s.invoices)
  const toast = useToast()
  const navigate = useNavigate()

  const [searchParams] = useSearchParams()
  const [statusFilter, setStatusFilter] = useState('all')
  const [openId, setOpenId] = useState<string | null>(searchParams.get('client'))
  const [form, setForm] = useState<{ mode: 'create' | 'edit'; client?: Client } | null>(null)
  const [newDepClient, setNewDepClient] = useState<string | null>(null)

  const depCount = useMemo(() => {
    const m = new Map<string, number>()
    for (const d of deployments) m.set(d.client_id, (m.get(d.client_id) ?? 0) + 1)
    return m
  }, [deployments])

  const filtered = useMemo(
    () => clients.filter((c) => statusFilter === 'all' || c.status === statusFilter),
    [clients, statusFilter],
  )

  const active = clients.filter((c) => c.status === 'active').length
  const open = openId ? clients.find((c) => c.id === openId) ?? null : null

  const columns: ShellColumn<Client>[] = [
    {
      key: 'name',
      header: 'Name',
      sortable: true,
      sortValue: (c) => c.name,
      render: (c) => (
        <button type="button" className="scns-linkbtn" onClick={() => setOpenId(c.id)}>
          {c.name}
        </button>
      ),
    },
    { key: 'company', header: 'Company', sortable: true, sortValue: (c) => c.company, render: (c) => c.company },
    {
      key: 'email',
      header: 'Email',
      sortable: true,
      sortValue: (c) => c.email,
      render: (c) => <span className="scns-mono" style={{ fontSize: 12 }}>{c.email}</span>,
    },
    {
      key: 'deployments',
      header: 'Deployments',
      sortable: true,
      sortValue: (c) => depCount.get(c.id) ?? 0,
      align: 'right',
      render: (c) => <span className="scns-num">{depCount.get(c.id) ?? 0}</span>,
    },
    { key: 'status', header: 'Status', sortable: true, sortValue: (c) => c.status, width: 130, render: (c) => <ClientStatusTag status={c.status} /> },
    {
      key: 'created',
      header: 'Created',
      sortable: true,
      sortValue: (c) => c.created_at,
      render: (c) => (
        <span className="scns-reltime" title={formatAbsoluteUTC(c.created_at)}>
          {formatMonthYear(c.created_at)}
        </span>
      ),
    },
  ]

  return (
    <div className="scns-page">
      <PageHeader
        title="Clients"
        subtitle={`${clients.length} clients · ${active} active`}
        primaryAction={{ label: 'Create client', icon: Add, onClick: () => setForm({ mode: 'create' }) }}
      />
      <DataTableShell
        rows={filtered}
        columns={columns}
        getId={(c) => c.id}
        searchText={(c) => `${c.name} ${c.company} ${c.email}`}
        searchPlaceholder="Search clients"
        initialSort={{ key: 'name', dir: 'ASC' }}
        itemLabel="clients"
        loading={useFirstLoad()}
        onRowClick={(c) => setOpenId(c.id)}
        filters={
          <Dropdown
            id="filter-client-status"
            type="inline"
            size="lg"
            label="Status: All"
            titleText="Filter by status"
            hideLabel
            items={STATUS_ITEMS}
            itemToString={(i) => i?.label ?? ''}
            selectedItem={STATUS_ITEMS.find((i) => i.id === statusFilter)}
            onChange={({ selectedItem }) => setStatusFilter(selectedItem?.id ?? 'all')}
          />
        }
      />

      {open && (
        <ClientDrawer
          client={open}
          deployments={deployments.filter((d) => d.client_id === open.id)}
          overdue={invoices.find((i) => i.client_id === open.id && i.status === 'overdue')}
          onClose={() => setOpenId(null)}
          onOpenDeployment={(id) => {
            setOpenId(null)
            navigate(`/deployments/${id}`)
          }}
          onEdit={(c) => setForm({ mode: 'edit', client: c })}
          onNewDeployment={(id) => setNewDepClient(id)}
          onPlaceholder={(what) => toast.info(what, 'Coming online.')}
        />
      )}

      {form && (
        <ClientFormModal open mode={form.mode} client={form.client} onClose={() => setForm(null)} />
      )}
      <CreateDeploymentModal
        open={newDepClient != null}
        presetClientId={newDepClient ?? undefined}
        onClose={() => setNewDepClient(null)}
        onCreated={(id) => {
          setOpenId(null)
          navigate(`/deployments/${id}`)
        }}
      />
    </div>
  )
}

function ClientDrawer({
  client,
  deployments,
  overdue,
  onClose,
  onOpenDeployment,
  onEdit,
  onNewDeployment,
  onPlaceholder,
}: {
  client: Client
  deployments: Deployment[]
  overdue: Invoice | undefined
  onClose: () => void
  onOpenDeployment: (id: string) => void
  onEdit: (c: Client) => void
  onNewDeployment: (id: string) => void
  onPlaceholder: (what: string) => void
}) {
  return (
    <Drawer
      open
      ariaLabel={`${client.name} details`}
      onClose={onClose}
      header={
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <span style={{ fontSize: 18, fontWeight: 600 }}>{client.name}</span>
            <ClientStatusTag status={client.status} />
          </div>
          <div className="scns-helper" style={{ fontSize: 12, marginTop: 4 }}>
            client since {formatMonthYear(client.created_at)} · {client.company}
          </div>
        </div>
      }
      footer={
        <>
          <Button kind="secondary" style={{ flex: 1, maxWidth: 'none' }} onClick={() => onEdit(client)}>
            Edit client
          </Button>
          <Button kind="primary" style={{ flex: 1, maxWidth: 'none' }} onClick={() => onPlaceholder('Open full record')}>
            Open full record
          </Button>
        </>
      }
    >
      <div style={{ marginBottom: 24 }}>
        <p className="scns-section-label">Contact</p>
        <KV label="Email" value={<span className="scns-mono">{client.email}</span>} />
        {client.phone && <KV label="Phone" value={<span className="scns-mono">{client.phone}</span>} />}
        {client.notes && <KV label="Notes" value={client.notes} />}
      </div>

      {overdue && (
        <InlineNotification
          kind="error"
          lowContrast
          hideCloseButton
          title={`${overdue.id} · ${formatCurrency(overdue.amount)} overdue`}
          subtitle={`${formatRelative(overdue.due_at, Date.now()).replace(' ago', '')} overdue — ${overdue.deployment_id} ${overdue.enforcement === 'suspended' ? 'auto-suspended' : overdue.enforcement === 'grace' ? 'in grace' : 'flagged'}.`}
          style={{ maxWidth: '100%', marginBottom: 24 }}
        />
      )}

      <div>
        <p className="scns-section-label">Deployments · {deployments.length}</p>
        <div style={{ display: 'flex', flexDirection: 'column' }}>
          {deployments.map((d) => (
            <button
              key={d.id}
              type="button"
              onClick={() => onOpenDeployment(d.id)}
              style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                gap: 8,
                padding: '10px 0',
                borderTop: '1px solid var(--cds-border-subtle)',
                background: 'none',
                border: 'none',
                borderBottom: 'none',
                cursor: 'pointer',
                textAlign: 'left',
                width: '100%',
              }}
            >
              <div>
                <div className="scns-mono" style={{ color: 'var(--cds-link-primary)' }}>
                  {d.name}
                </div>
                <div className="scns-mono scns-helper" style={{ fontSize: 11 }}>
                  {d.package} {d.version}
                </div>
              </div>
              <DeploymentStatusTag state={d.state} />
            </button>
          ))}
        </div>
        <Button
          kind="tertiary"
          size="sm"
          renderIcon={Add}
          style={{ marginTop: 16 }}
          onClick={() => onNewDeployment(client.id)}
        >
          New deployment for this client
        </Button>
      </div>
    </Drawer>
  )
}

function KV({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div style={{ display: 'flex', gap: 12, padding: '6px 0' }}>
      <div className="scns-helper" style={{ width: 64, flex: 'none', fontSize: 13 }}>
        {label}
      </div>
      <div style={{ fontSize: 13 }}>{value}</div>
    </div>
  )
}
