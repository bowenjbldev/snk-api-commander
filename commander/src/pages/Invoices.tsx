import { useMemo, useState } from 'react'
import { Dropdown, OverflowMenu, OverflowMenuItem } from '@carbon/react'
import { Add } from '@carbon/icons-react'
import { useStore } from '../store/store'
import { useCan } from '../store/auth'
import { useActions } from '../providers/ActionsProvider'
import { useToast } from '../providers/ToastProvider'
import { PageHeader } from '../components/PageHeader'
import { DataTableShell, type ShellColumn } from '../components/DataTableShell'
import { useFirstLoad } from '../hooks/useFirstLoad'
import { BillingTag } from '../components/tags/StatusTag'
import { InvoiceFormModal } from '../components/modals/InvoiceFormModal'
import { TypedConfirmModal } from '../components/modals/TypedConfirmModal'
import { formatCurrency } from '../lib/format'
import { formatDayMonth } from '../lib/time'
import type { Invoice } from '../store/types'

const STATUS_ITEMS = [
  { id: 'all', label: 'Status: All' },
  { id: 'paid', label: 'Status: Paid' },
  { id: 'pending', label: 'Status: Pending' },
  { id: 'overdue', label: 'Status: Overdue' },
]

const DAY = 86_400_000

export function Invoices() {
  const invoices = useStore((s) => s.invoices)
  const clients = useStore((s) => s.clients)
  const addInvoice = useStore((s) => s.addInvoice)
  const updateInvoice = useStore((s) => s.updateInvoice)
  const deleteInvoice = useStore((s) => s.deleteInvoice)
  const actions = useActions()
  const toast = useToast()
  const can = useCan()
  const [statusFilter, setStatusFilter] = useState('all')
  const [form, setForm] = useState<{ mode: 'create' | 'edit'; invoice?: Invoice } | null>(null)
  const [toDelete, setToDelete] = useState<Invoice | null>(null)

  const canCreate = can('create invoices')
  const canUpdate = can('update invoices')
  const canDelete = can('delete invoices')

  const clientName = useMemo(() => {
    const m = new Map(clients.map((c) => [c.id, c.name]))
    return (id: string) => m.get(id) ?? id
  }, [clients])

  const filtered = useMemo(
    () => invoices.filter((i) => statusFilter === 'all' || i.status === statusFilter),
    [invoices, statusFilter],
  )

  const summary = useMemo(() => {
    const overdue = invoices.filter((i) => i.status === 'overdue')
    return {
      overdueCount: overdue.length,
      overdueTotal: overdue.reduce((s, i) => s + i.amount, 0),
      pending: invoices.filter((i) => i.status === 'pending').length,
      paid: invoices.filter((i) => i.status === 'paid').length,
    }
  }, [invoices])

  const daysLate = (due: string) => Math.floor((Date.now() - new Date(due).getTime()) / DAY)

  const columns: ShellColumn<Invoice>[] = [
    {
      key: 'number',
      header: 'Number',
      sortable: true,
      sortValue: (i) => i.id,
      render: (i) => <span className="scns-mono" style={{ color: 'var(--cds-link-primary)' }}>{i.id}</span>,
    },
    { key: 'client', header: 'Client', sortable: true, sortValue: (i) => clientName(i.client_id), render: (i) => clientName(i.client_id) },
    {
      key: 'deployment',
      header: 'Deployment',
      sortable: true,
      sortValue: (i) => i.deployment_id,
      render: (i) => <span className="scns-mono" style={{ fontSize: 12 }}>{i.deployment_id}</span>,
    },
    {
      key: 'amount',
      header: 'Amount',
      sortable: true,
      sortValue: (i) => i.amount,
      align: 'right',
      render: (i) => <span className="scns-num">{formatCurrency(i.amount)}</span>,
    },
    { key: 'issued', header: 'Issued', sortable: true, sortValue: (i) => i.issued_at, render: (i) => <span className="scns-mono" style={{ fontSize: 12 }}>{formatDayMonth(i.issued_at)}</span> },
    {
      key: 'due',
      header: 'Due',
      sortable: true,
      sortValue: (i) => i.due_at,
      render: (i) => {
        const late = daysLate(i.due_at)
        const isOverdue = i.status === 'overdue'
        const isLatePending = i.status === 'pending' && late > 0
        const color = isOverdue ? 'var(--scns-error)' : isLatePending ? 'var(--scns-warn-text)' : 'var(--cds-text-primary)'
        return (
          <span className="scns-mono" style={{ fontSize: 12, color }}>
            {formatDayMonth(i.due_at)}
            {isOverdue && ` · ${late}d overdue`}
            {isLatePending && ` · ${late}d late`}
            {isOverdue && i.enforcement && (
              <span style={{ marginLeft: 8, color: i.enforcement === 'suspended' ? 'var(--scns-error)' : 'var(--scns-amber-text)' }}>
                → {i.enforcement === 'suspended' ? 'suspended' : 'grace'}
              </span>
            )}
          </span>
        )
      },
    },
    { key: 'status', header: 'Status', sortable: true, sortValue: (i) => i.status, width: 130, render: (i) => <BillingTag status={i.status} /> },
  ]

  return (
    <div className="scns-page">
      <PageHeader
        title="Invoices"
        subtitle={
          <>
            {invoices.length} invoices ·{' '}
            <span style={{ color: 'var(--scns-error)' }}>
              {summary.overdueCount} overdue ({formatCurrency(summary.overdueTotal)})
            </span>{' '}
            · {summary.pending} pending · {summary.paid} paid
          </>
        }
        primaryAction={
          canCreate
            ? { label: 'Create invoice', icon: Add, onClick: () => setForm({ mode: 'create' }) }
            : undefined
        }
      />
      <DataTableShell
        rows={filtered}
        columns={columns}
        getId={(i) => i.id}
        searchText={(i) => `${i.id} ${clientName(i.client_id)} ${i.deployment_id}`}
        searchPlaceholder="Search invoices"
        initialSort={{ key: 'number', dir: 'DESC' }}
        itemLabel="invoices"
        loading={useFirstLoad()}
        filters={
          <Dropdown
            id="filter-invoice-status"
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
        rowActions={(i) => (
          <OverflowMenu size="sm" flipped aria-label={`Actions for ${i.id}`} onClick={(e) => e.stopPropagation()}>
            {canUpdate && <OverflowMenuItem itemText="Edit…" onClick={() => setForm({ mode: 'edit', invoice: i })} />}
            {i.status !== 'paid' && <OverflowMenuItem hasDivider={canUpdate} itemText="Mark as paid" onClick={() => actions.markPaid(i)} />}
            {i.status !== 'overdue' && (
              <OverflowMenuItem hasDivider={i.status === 'paid' || canUpdate} itemText="Mark as overdue…" onClick={() => actions.markOverdue(i)} />
            )}
            {canDelete && <OverflowMenuItem hasDivider isDelete itemText="Delete…" onClick={() => setToDelete(i)} />}
          </OverflowMenu>
        )}
      />

      {form && (
        <InvoiceFormModal
          open
          mode={form.mode}
          invoice={form.invoice}
          onClose={() => setForm(null)}
          onSubmit={(value) => {
            if (form.mode === 'edit' && form.invoice) {
              updateInvoice(form.invoice.id, value)
              toast.success('Invoice updated', form.invoice.id)
            } else {
              const id = addInvoice(value as Parameters<typeof addInvoice>[0])
              toast.success('Invoice created', id)
            }
          }}
        />
      )}

      {toDelete && (
        <TypedConfirmModal
          open
          title={`Delete invoice ${toDelete.id}?`}
          label="Invoices"
          confirmWord={toDelete.id}
          confirmLabel="Delete invoice"
          body={
            <p>
              This permanently removes invoice <span className="scns-mono">{toDelete.id}</span> for{' '}
              {clientName(toDelete.client_id)} ({formatCurrency(toDelete.amount)}). This cannot be undone.
            </p>
          }
          onClose={() => setToDelete(null)}
          onConfirm={() => {
            deleteInvoice(toDelete.id)
            toast.success('Invoice deleted', toDelete.id)
            setToDelete(null)
          }}
        />
      )}
    </div>
  )
}
