import { useMemo, useState } from 'react'
import { useOutletContext } from 'react-router'
import { Button, OverflowMenu, OverflowMenuItem, Tag } from '@carbon/react'
import { Add } from '@carbon/icons-react'
import { useStore } from '../../store/store'
import { useCan } from '../../store/auth'
import { useToast } from '../../providers/ToastProvider'
import { DataTableShell, type ShellColumn } from '../../components/DataTableShell'
import { Card } from '../../components/Card'
import { PlanCreatePanel } from '../../components/PlanCreatePanel'
import { useFirstLoad } from '../../hooks/useFirstLoad'
import { PlanFormModal } from '../../components/modals/PlanFormModal'
import { formatCurrency, formatCompact } from '../../lib/format'
import { formatDayMonth } from '../../lib/time'
import type { PackageContext, Plan } from '../../store/types'

function limit(v: number | null | undefined): string {
  return v == null ? '∞' : formatCompact(v)
}

export function PlansTab() {
  const { pkg, packageRow, plans } = useOutletContext<PackageContext>()
  const deletePlan = useStore((s) => s.deletePlan)
  const toast = useToast()
  const can = useCan()

  // Create → side panel (drawer); edit → modal (opened from the row).
  const [createOpen, setCreateOpen] = useState(false)
  const [editPlan, setEditPlan] = useState<Plan | null>(null)

  // Subscription terms this live instance has gone through, current first.
  const allSubscriptions = useStore((s) => s.subscriptions)
  const history = useMemo(
    () =>
      packageRow
        ? allSubscriptions
            .filter((s) => s.package_id === packageRow.id)
            .sort((a, b) => b.started_at.localeCompare(a.started_at) || b.id - a.id)
        : [],
    [allSubscriptions, packageRow],
  )

  const columns: ShellColumn<Plan>[] = [
    {
      key: 'name',
      header: 'Plan',
      sortable: true,
      sortValue: (p) => p.name,
      render: (p) =>
        can('update plans') ? (
          <button type="button" className="scns-linkbtn" onClick={() => setEditPlan(p)}>
            {p.name}
          </button>
        ) : (
          <span>{p.name}</span>
        ),
    },
    {
      key: 'price',
      header: 'Price / mo',
      sortable: true,
      sortValue: (p) => p.price_monthly,
      align: 'right',
      render: (p) => <span className="scns-num">{formatCurrency(p.price_monthly)}</span>,
    },
    {
      key: 'quotas',
      header: 'Quota limits (req·db·storage·users)',
      render: (p) => (
        <span className="scns-mono" style={{ fontSize: 12 }}>
          {limit(p.q_requests_day)} · {limit(p.q_disk_db_mb)}mb · {limit(p.q_disk_storage_mb)}mb · {limit(p.q_users_total)}
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      sortable: true,
      sortValue: (p) => p.status,
      width: 110,
      render: (p) => (
        <Tag type={p.status === 'active' ? 'green' : 'gray'} size="sm">
          {p.status === 'active' ? 'Active' : 'Inactive'}
        </Tag>
      ),
    },
    {
      key: 'actions',
      header: '',
      width: 100,
      render: (p) => (
        <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
          {(can('update plans') || can('delete plans')) && (
            <OverflowMenu size="sm" flipped aria-label="Plan actions">
              {can('update plans') && (
                <OverflowMenuItem itemText="Edit…" onClick={() => setEditPlan(p)} />
              )}
              {can('delete plans') && (
                <OverflowMenuItem
                  isDelete
                  itemText="Delete…"
                  onClick={() => {
                    deletePlan(p.id)
                    toast.success('Plan deleted', `${p.package} · ${p.name}`)
                  }}
                />
              )}
            </OverflowMenu>
          )}
        </div>
      ),
    },
  ]

  const tableBlock = (
    <DataTableShell
      rows={plans}
      columns={columns}
      getId={(p) => p.id}
      searchText={(p) => p.name}
      searchPlaceholder="Search plans"
      initialSort={{ key: 'name', dir: 'ASC' }}
      itemLabel="plans"
      loading={useFirstLoad()}
      primaryAction={
        can('create plans') ? (
          <Button kind="primary" size="lg" renderIcon={Add} onClick={() => setCreateOpen(true)}>
            New plan
          </Button>
        ) : undefined
      }
    />
  )

  return (
    <div>
      {packageRow ? (
        <div className="scns-client-grid">
          <div style={{ minWidth: 0 }}>{tableBlock}</div>
          <aside className="scns-client-rail">
            <Card title="Plan history" subtitle="subscription terms" bodyPadding="8px 16px 12px">
              {history.length === 0 ? (
                <div className="scns-helper" style={{ fontSize: 13, padding: '4px 0' }}>No plan history.</div>
              ) : (
                <table className="scns-minitable">
                  <thead>
                    <tr>
                      <th>Plan</th>
                      <th>Period</th>
                      <th>Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {history.map((sub) => (
                      <tr key={sub.id}>
                        <td>
                          <span className="scns-mono">{sub.plan}</span>{' '}
                          <span className="scns-helper" style={{ fontSize: 11 }}>{formatCurrency(sub.price_monthly)}</span>
                        </td>
                        <td>
                          <span className="scns-mono scns-helper" style={{ fontSize: 11 }}>
                            {formatDayMonth(sub.started_at)} → {sub.ended_at ? formatDayMonth(sub.ended_at) : 'now'}
                          </span>
                        </td>
                        <td>
                          {sub.id === packageRow.subscription_id ? (
                            <Tag type="blue" size="sm">current</Tag>
                          ) : (
                            <span className="scns-helper" style={{ fontSize: 11 }}>{sub.billing_status}</span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </Card>
          </aside>
        </div>
      ) : (
        tableBlock
      )}

      {/* Always mounted so CreateSidePanel restores the page content width on slide-out close. */}
      <PlanCreatePanel open={createOpen} packageName={pkg.name} onClose={() => setCreateOpen(false)} />
      {editPlan && (
        <PlanFormModal open mode="edit" plan={editPlan} packageName={pkg.name} onClose={() => setEditPlan(null)} />
      )}
    </div>
  )
}
