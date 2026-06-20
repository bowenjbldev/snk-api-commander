import { useState } from 'react'
import { useOutletContext } from 'react-router'
import { Button, OverflowMenu, OverflowMenuItem, Tag } from '@carbon/react'
import { Add } from '@carbon/icons-react'
import { useStore } from '../../store/store'
import { useCan } from '../../store/auth'
import { useToast } from '../../providers/ToastProvider'
import { DataTableShell, type ShellColumn } from '../../components/DataTableShell'
import { useFirstLoad } from '../../hooks/useFirstLoad'
import { PlanFormModal } from '../../components/modals/PlanFormModal'
import { formatCurrency, formatCompact } from '../../lib/format'
import type { PackageContext, Plan } from '../../store/types'

function limit(v: number | null | undefined): string {
  return v == null ? '∞' : formatCompact(v)
}

export function PlansTab() {
  const { pkg, plans } = useOutletContext<PackageContext>()
  const deletePlan = useStore((s) => s.deletePlan)
  const toast = useToast()
  const can = useCan()

  const [form, setForm] = useState<{ mode: 'create' | 'edit'; plan?: Plan } | null>(null)

  const active = plans.filter((p) => p.status === 'active').length

  const columns: ShellColumn<Plan>[] = [
    {
      key: 'name',
      header: 'Plan',
      sortable: true,
      sortValue: (p) => p.name,
      render: (p) =>
        can('update plans') ? (
          <button type="button" className="scns-linkbtn" onClick={() => setForm({ mode: 'edit', plan: p })}>
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
                <OverflowMenuItem itemText="Edit…" onClick={() => setForm({ mode: 'edit', plan: p })} />
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

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16 }}>
        <div className="scns-helper" style={{ fontSize: 13 }}>
          {plans.length} {plans.length === 1 ? 'plan' : 'plans'} · {active} active
        </div>
        {can('create plans') && (
          <Button kind="primary" size="sm" renderIcon={Add} onClick={() => setForm({ mode: 'create' })}>
            New plan
          </Button>
        )}
      </div>

      <DataTableShell
        rows={plans}
        columns={columns}
        getId={(p) => p.id}
        searchText={(p) => p.name}
        searchPlaceholder="Search plans"
        initialSort={{ key: 'name', dir: 'ASC' }}
        itemLabel="plans"
        loading={useFirstLoad()}
      />

      {form && (
        <PlanFormModal open mode={form.mode} plan={form.plan} packageName={pkg.name} onClose={() => setForm(null)} />
      )}
    </div>
  )
}
