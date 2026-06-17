import { useState } from 'react'
import { Button, OverflowMenu, OverflowMenuItem, Tag } from '@carbon/react'
import { Add, Rocket } from '@carbon/icons-react'
import { useStore } from '../store/store'
import { useToast } from '../providers/ToastProvider'
import { PageHeader } from '../components/PageHeader'
import { DataTableShell, type ShellColumn } from '../components/DataTableShell'
import { useFirstLoad } from '../hooks/useFirstLoad'
import { PlanFormModal } from '../components/modals/PlanFormModal'
import { ProvisionModal } from '../components/modals/ProvisionModal'
import { formatCurrency, formatCompact } from '../lib/format'
import type { Plan } from '../store/types'

function limit(v: number | null | undefined): string {
  return v == null ? '∞' : formatCompact(v)
}

export function Catalog() {
  const plans = useStore((s) => s.plans)
  const deletePlan = useStore((s) => s.deletePlan)
  const toast = useToast()

  const [form, setForm] = useState<{ mode: 'create' | 'edit'; plan?: Plan } | null>(null)
  const [provisionPlanId, setProvisionPlanId] = useState<string | null>(null)
  const [provisionOpen, setProvisionOpen] = useState(false)

  const active = plans.filter((p) => p.status === 'active').length

  const openProvision = (planId?: string) => {
    setProvisionPlanId(planId ?? null)
    setProvisionOpen(true)
  }

  const columns: ShellColumn<Plan>[] = [
    {
      key: 'package',
      header: 'Package',
      sortable: true,
      sortValue: (p) => p.package,
      render: (p) => <span className="scns-mono">{p.package}</span>,
    },
    {
      key: 'name',
      header: 'Plan',
      sortable: true,
      sortValue: (p) => p.name,
      render: (p) => (
        <button type="button" className="scns-linkbtn" onClick={() => setForm({ mode: 'edit', plan: p })}>
          {p.name}
        </button>
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
          <Button kind="ghost" size="sm" renderIcon={Rocket} onClick={() => openProvision(p.id)} disabled={p.status !== 'active'}>
            Provision
          </Button>
          <OverflowMenu size="sm" flipped aria-label="Plan actions">
            <OverflowMenuItem itemText="Edit…" onClick={() => setForm({ mode: 'edit', plan: p })} />
            <OverflowMenuItem
              isDelete
              itemText="Delete…"
              onClick={() => {
                deletePlan(p.id)
                toast.success('Plan deleted', `${p.package} · ${p.name}`)
              }}
            />
          </OverflowMenu>
        </div>
      ),
    },
  ]

  return (
    <div className="scns-page">
      <PageHeader
        title="Catalog"
        subtitle={`${plans.length} plans · ${active} active`}
        actions={
          <Button kind="secondary" renderIcon={Rocket} onClick={() => openProvision()} disabled={active === 0}>
            New subscription
          </Button>
        }
        primaryAction={{ label: 'Create plan', icon: Add, onClick: () => setForm({ mode: 'create' }) }}
      />
      <DataTableShell
        rows={plans}
        columns={columns}
        getId={(p) => p.id}
        searchText={(p) => `${p.package} ${p.name}`}
        searchPlaceholder="Search plans"
        initialSort={{ key: 'package', dir: 'ASC' }}
        itemLabel="plans"
        loading={useFirstLoad()}
      />

      {form && <PlanFormModal open mode={form.mode} plan={form.plan} onClose={() => setForm(null)} />}
      {provisionOpen && (
        <ProvisionModal open planId={provisionPlanId ?? undefined} onClose={() => setProvisionOpen(false)} />
      )}
    </div>
  )
}
