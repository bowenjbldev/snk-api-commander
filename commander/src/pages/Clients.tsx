import { useMemo, useState, type ReactNode } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { Dropdown, Button, InlineNotification } from '@carbon/react'
import { Add } from '@carbon/icons-react'
import { useStore, useProvisionedPackages } from '../store/store'
import { useCan } from '../store/auth'
import { useToast } from '../providers/ToastProvider'
import { PageHeader } from '../components/PageHeader'
import { DataTableShell, type ShellColumn } from '../components/DataTableShell'
import { useFirstLoad } from '../hooks/useFirstLoad'
import { ClientStatusTag, PackageStatusTag } from '../components/tags/StatusTag'
import { Drawer } from '../components/Drawer'
import { ClientFormModal } from '../components/modals/ClientFormModal'
import { formatMonthYear, formatAbsoluteUTC, formatRelative } from '../lib/time'
import { formatCurrency } from '../lib/format'
import type { Client, Package, Invoice } from '../store/types'

const STATUS_ITEMS = [
  { id: 'all', label: 'Status: All' },
  { id: 'active', label: 'Status: Active' },
  { id: 'inactive', label: 'Status: Inactive' },
]

export function Clients() {
  const clients = useStore((s) => s.clients)
  const packages = useProvisionedPackages()
  const invoices = useStore((s) => s.invoices)
  const can = useCan()
  const toast = useToast()
  const navigate = useNavigate()

  const [searchParams] = useSearchParams()
  const [statusFilter, setStatusFilter] = useState('all')
  const clientParam = searchParams.get('client')
  const [openId, setOpenId] = useState<number | null>(clientParam != null ? Number(clientParam) : null)
  const [form, setForm] = useState<{ mode: 'create' | 'edit'; client?: Client } | null>(null)

  const depCount = useMemo(() => {
    const m = new Map<number, number>()
    for (const p of packages) m.set(p.client_id, (m.get(p.client_id) ?? 0) + 1)
    return m
  }, [packages])

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
      header: 'Packages',
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
        primaryAction={
          can('create clients')
            ? { label: 'Create client', icon: Add, onClick: () => setForm({ mode: 'create' }) }
            : undefined
        }
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
          packages={packages.filter((p) => p.client_id === open.id)}
          overdue={invoices.find((i) => i.client_id === open.id && i.status === 'overdue')}
          canEdit={can('update clients')}
          onClose={() => setOpenId(null)}
          onOpenPackage={(name) => {
            setOpenId(null)
            navigate(`/packages/${name}`)
          }}
          onEdit={(c) => setForm({ mode: 'edit', client: c })}
          onPlaceholder={(what) => toast.info(what, 'Coming online.')}
        />
      )}

      {form && (
        <ClientFormModal open mode={form.mode} client={form.client} onClose={() => setForm(null)} />
      )}
    </div>
  )
}

function ClientDrawer({
  client,
  packages,
  overdue,
  canEdit,
  onClose,
  onOpenPackage,
  onEdit,
  onPlaceholder,
}: {
  client: Client
  packages: Package[]
  overdue: Invoice | undefined
  canEdit: boolean
  onClose: () => void
  onOpenPackage: (name: string) => void
  onEdit: (c: Client) => void
  onPlaceholder: (what: string) => void
}) {
  const overduePackage = overdue ? packages.find((p) => p.id === overdue.package_id) : undefined
  const overduePackageLabel = overduePackage?.name ?? (overdue ? 'Unknown package' : '')

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
          {canEdit && (
            <Button kind="secondary" style={{ flex: 1, maxWidth: 'none' }} onClick={() => onEdit(client)}>
              Edit client
            </Button>
          )}
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
          title={`${overdue.number} · ${formatCurrency(overdue.amount)} overdue`}
          subtitle={`${formatRelative(overdue.due_at, Date.now()).replace(' ago', '')} overdue — ${overduePackageLabel} ${overdue.enforcement === 'suspended' ? 'auto-suspended' : overdue.enforcement === 'grace' ? 'in grace' : 'flagged'}.`}
          style={{ maxWidth: '100%', marginBottom: 24 }}
        />
      )}

      <div>
        <p className="scns-section-label">Packages · {packages.length}</p>
        <div style={{ display: 'flex', flexDirection: 'column' }}>
          {packages.length === 0 ? (
            <div className="scns-helper" style={{ padding: '12px 0', fontSize: 13 }}>
              No packages for this client.
            </div>
          ) : (
            packages.map((p) => (
              <button
                key={p.id}
                type="button"
                onClick={() => onOpenPackage(p.name)}
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
                    {p.name}
                  </div>
                  <div className="scns-mono scns-helper" style={{ fontSize: 11 }}>
                    {p.subdomain}
                  </div>
                </div>
                <PackageStatusTag pkg={p} />
              </button>
            ))
          )}
        </div>
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
