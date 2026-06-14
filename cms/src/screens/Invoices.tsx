import { useSearchParams } from 'react-router-dom'
import { OverflowMenu, OverflowMenuItem } from '@carbon/react'
import { OverflowMenuVertical } from '@carbon/icons-react'
import {
  PageHeader,
  DataGrid,
  Mono,
  Num,
  BillingStatusTag,
  EmptyState,
  type GridColumn,
} from '../components/ui'
import { useStore } from '../store/DataStore'
import { useModals } from '../components/modals/ModalHost'
import { useToast } from '../notify/ToastProvider'
import { useNowMs } from '../lib/time'
import { fmtUSD, dayMonth, daysOverdue } from '../lib/format'
import type { Invoice } from '../types'

export function Invoices() {
  const { invoices } = useStore()
  const m = useModals()
  const toast = useToast()
  const now = useNowMs()
  const [searchParams] = useSearchParams()

  const overdue = invoices.filter((i) => i.status === 'overdue')
  const overdueCount = overdue.length
  const overdueSum = overdue.reduce((acc, i) => acc + i.amount, 0)
  const pendingCount = invoices.filter((i) => i.status === 'pending').length
  const paidCount = invoices.filter((i) => i.status === 'paid').length

  const subtitle = (
    <span>
      {invoices.length} invoices ·{' '}
      <strong style={{ color: 'var(--sc-support-error)' }}>
        {overdueCount} overdue ({fmtUSD(overdueSum, { cents: true })})
      </strong>{' '}
      · {pendingCount} pending · {paidCount} paid
    </span>
  )

  const columns: GridColumn<Invoice>[] = [
    {
      key: 'number',
      header: 'Number',
      sortValue: (i) => i.number,
      render: (i) => <Mono color="var(--cds-link-primary)">{i.number}</Mono>,
    },
    {
      key: 'client',
      header: 'Client',
      render: (i) => i.clientName,
    },
    {
      key: 'deployment',
      header: 'Deployment',
      render: (i) => <Mono size={12}>{i.deploymentId}</Mono>,
    },
    {
      key: 'amount',
      header: 'Amount',
      align: 'right',
      sortValue: (i) => i.amount,
      render: (i) => <Num>{fmtUSD(i.amount, { cents: true })}</Num>,
    },
    {
      key: 'issued',
      header: 'Issued',
      sortValue: (i) => i.issuedAt,
      render: (i) => <Mono>{dayMonth(i.issuedAt)}</Mono>,
    },
    {
      key: 'due',
      header: 'Due',
      sortValue: (i) => i.dueAt,
      render: (i) => {
        const isLate = i.status === 'pending' && Date.parse(i.dueAt) < now
        return (
          <span>
            <Mono>{dayMonth(i.dueAt)}</Mono>
            {i.status === 'overdue' && (
              <Mono color="var(--sc-support-error)">
                {' · ' + daysOverdue(i.dueAt, now) + 'd overdue'}
              </Mono>
            )}
            {isLate && (
              <Mono color="var(--sc-support-warning)">
                {' · ' + daysOverdue(i.dueAt, now) + 'd late'}
              </Mono>
            )}
          </span>
        )
      },
    },
    {
      key: 'status',
      header: 'Status',
      render: (i) => (
        <span style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          <BillingStatusTag status={i.status} size="sm" />
          {i.enforcement === 'grace' && (
            <Mono size={11} color="var(--sc-support-warning)">
              {' → grace'}
            </Mono>
          )}
          {i.enforcement === 'suspended' && (
            <Mono size={11} color="var(--sc-support-error)">
              {' → suspended'}
            </Mono>
          )}
        </span>
      ),
    },
  ]

  return (
    <div className="sc-page">
      <PageHeader title="Invoices" subtitle={subtitle} />

      <DataGrid<Invoice>
        rows={invoices}
        getRowId={(i) => i.id}
        size="lg"
        initialSort={{ key: 'number', dir: 'DESC' }}
        searchText={(i) => `${i.number} ${i.clientName} ${i.deploymentId}`}
        searchPlaceholder="Search invoices…"
        selectedRowId={searchParams.get('focus')}
        columns={columns}
        emptyState={
          <EmptyState
            title="No invoices"
            description="No invoices match the current search."
          />
        }
        rowOverflow={(i) => (
          <OverflowMenu size="sm" flipped renderIcon={OverflowMenuVertical}>
            <OverflowMenuItem
              itemText="Open invoice"
              onClick={() => toast.success(`Opening ${i.number}`)}
            />
            <OverflowMenuItem
              itemText="Edit…"
              onClick={() => toast.success(`Editing ${i.number}`)}
            />
            <OverflowMenuItem
              itemText="Mark as paid"
              hasDivider
              onClick={() => m.markInvoicePaid(i)}
            />
            <OverflowMenuItem
              itemText="Mark as overdue…"
              onClick={() => m.openMarkOverdue(i)}
            />
            <OverflowMenuItem
              itemText="Delete…"
              hasDivider
              isDelete
              onClick={() => toast.success(i.number + ' deleted')}
            />
          </OverflowMenu>
        )}
      />
    </div>
  )
}
