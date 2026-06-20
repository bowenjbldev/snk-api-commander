import { useMemo, useState } from 'react'
import { useNavigate } from 'react-router'
import { Dropdown, Toggle, Tag } from '@carbon/react'
import { useStore } from '../store/store'
import { useToast } from '../providers/ToastProvider'
import { PageHeader } from '../components/PageHeader'
import { DataTableShell, type ShellColumn } from '../components/DataTableShell'
import { useFirstLoad } from '../hooks/useFirstLoad'
import { QuotaBar } from '../components/QuotaBar'
import { InlineEdit } from '../components/InlineEdit'
import { RelativeTime } from '../components/RelativeTime'
import { formatInt, formatMb } from '../lib/format'
import type { OnExceed, QuotaMetric } from '../store/types'

const METRIC_ITEMS = [
  { id: 'all', label: 'Metric: All' },
  { id: 'requests_day', label: 'requests_day' },
  { id: 'disk_db_mb', label: 'disk_db_mb' },
  { id: 'disk_storage_mb', label: 'disk_storage_mb' },
  { id: 'users_total', label: 'users_total' },
]
const POLICY_ITEMS: { id: OnExceed; label: string }[] = [
  { id: 'none', label: 'none' },
  { id: 'warn', label: 'warn' },
  { id: 'suspend', label: 'suspend' },
]
const POLICY_COLOR: Record<OnExceed, string> = {
  none: 'var(--cds-text-helper)',
  warn: 'var(--scns-warn-text)',
  suspend: 'var(--scns-error)',
}

function ratioOf(q: QuotaMetric): number {
  return q.limit_value == null || q.limit_value === 0 ? 0 : q.current_value / q.limit_value
}
function fmtVal(q: QuotaMetric): string {
  return q.unit === 'mb' ? formatMb(q.current_value) : formatInt(q.current_value)
}
function fmtLimit(q: QuotaMetric): string {
  if (q.limit_value == null) return ''
  return q.unit === 'mb' ? formatMb(q.limit_value) : formatInt(q.limit_value)
}

export function Quotas() {
  const quotas = useStore((s) => s.quotas)
  const deployments = useStore((s) => s.deployments)
  const editQuotaLimit = useStore((s) => s.editQuotaLimit)
  const setQuotaPolicy = useStore((s) => s.setQuotaPolicy)
  const toast = useToast()
  const navigate = useNavigate()

  const [deployFilter, setDeployFilter] = useState('all')
  const [metricFilter, setMetricFilter] = useState('all')
  const [onlyBreaches, setOnlyBreaches] = useState(false)

  const deployItems = useMemo(
    () => [{ id: 'all', label: 'Deployment: All' }, ...deployments.map((d) => ({ id: String(d.id), label: d.package.name }))],
    [deployments],
  )

  const deploymentName = useMemo(() => {
    const m = new Map(deployments.map((d) => [d.id, d.package.name]))
    return (id: number) => m.get(id) ?? String(id)
  }, [deployments])

  const filtered = useMemo(
    () =>
      quotas.filter((q) => {
        if (deployFilter !== 'all' && String(q.deployment_id) !== deployFilter) return false
        if (metricFilter !== 'all' && q.metric_key !== metricFilter) return false
        if (onlyBreaches && ratioOf(q) < 0.8) return false
        return true
      }),
    [quotas, deployFilter, metricFilter, onlyBreaches],
  )

  const overCount = quotas.filter((q) => ratioOf(q) > 1).length
  const warnCount = quotas.filter((q) => ratioOf(q) >= 0.8 && ratioOf(q) <= 1).length

  const columns: ShellColumn<QuotaMetric>[] = [
    {
      key: 'deployment',
      header: 'Deployment',
      sortable: true,
      sortValue: (q) => deploymentName(q.deployment_id),
      width: 180,
      render: (q) => (
        <button
          type="button"
          className="scns-mono"
          style={{ background: 'none', border: 'none', padding: 0, cursor: 'pointer', color: 'var(--cds-link-primary)' }}
          onClick={() => {
            const pkg = deployments.find((d) => d.id === q.deployment_id)?.package.name
            navigate(pkg ? `/packages/${pkg}` : '/packages')
          }}
        >
          {deploymentName(q.deployment_id)}
        </button>
      ),
    },
    {
      key: 'metric',
      header: 'Metric',
      sortable: true,
      sortValue: (q) => q.metric_key,
      width: 160,
      render: (q) => <span className="scns-mono" style={{ fontSize: 12 }}>{q.metric_key}</span>,
    },
    {
      key: 'usage',
      header: 'Current usage',
      sortable: true,
      sortValue: (q) => ratioOf(q),
      render: (q) => (
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <QuotaBar current={q.current_value} limit={q.limit_value} width={200} height={8} showLabel />
          <span className="scns-num" style={{ fontSize: 11, color: 'var(--cds-text-secondary)' }}>
            {fmtVal(q)}
          </span>
        </div>
      ),
    },
    {
      key: 'limit',
      header: 'Limit',
      width: 150,
      render: (q) => (
        <InlineEdit
          display={fmtLimit(q)}
          rawValue={q.limit_value == null ? '' : String(q.limit_value)}
          placeholder="Unlimited"
          ariaLabel={`${q.deployment_id} ${q.metric_key} limit`}
          align="left"
          onCommit={(raw) => {
            const next = raw.trim() === '' ? null : Math.max(0, Math.round(Number(raw.replace(/[^0-9.]/g, ''))))
            if (raw.trim() !== '' && Number.isNaN(Number(raw.replace(/[^0-9.]/g, '')))) {
              toast.error('Invalid limit', 'Enter a number or leave empty for unlimited.')
              return
            }
            editQuotaLimit(q.id, next)
            toast.success('Limit updated', `${q.metric_key} → ${next == null ? 'Unlimited' : formatInt(next)}`)
          }}
        />
      ),
    },
    {
      key: 'policy',
      header: 'On exceed',
      width: 150,
      render: (q) => (
        <Dropdown
          id={`policy-${q.id}`}
          type="inline"
          size="sm"
          hideLabel
          titleText="On exceed"
          label={q.on_exceed}
          items={POLICY_ITEMS}
          itemToString={(i) => i?.label ?? ''}
          selectedItem={POLICY_ITEMS.find((p) => p.id === q.on_exceed)}
          onChange={({ selectedItem }) => {
            if (selectedItem) {
              setQuotaPolicy(q.id, selectedItem.id)
              toast.success('Policy updated', `${q.metric_key} → ${selectedItem.id}`)
            }
          }}
          style={{ color: POLICY_COLOR[q.on_exceed] }}
        />
      ),
    },
    {
      key: 'exceeded',
      header: 'Exceeded since',
      sortable: true,
      sortValue: (q) => q.exceeded_at ?? '',
      align: 'right',
      render: (q) =>
        q.exceeded_at ? (
          <RelativeTime iso={q.exceeded_at} style={{ color: 'var(--scns-error)' }} />
        ) : (
          <span className="scns-helper">—</span>
        ),
    },
  ]

  return (
    <div className="scns-page">
      <PageHeader
        title="Quotas"
        subtitle="Limits are inline-editable · empty = unlimited · bars turn amber at 80%, red with an OVER badge past 100%"
        actions={
          <div style={{ display: 'flex', gap: 8 }}>
            {overCount > 0 && <Tag type="red">{overCount} OVER</Tag>}
            {warnCount > 0 && <span className="scns-tag scns-tag--amber">{warnCount} ≥ 80%</span>}
          </div>
        }
      />
      <DataTableShell
        rows={filtered}
        columns={columns}
        getId={(q) => q.id}
        searchText={(q) => `${q.deployment_id} ${q.metric_key}`}
        searchPlaceholder="Search quotas"
        itemLabel="quotas"
        loading={useFirstLoad()}
        filters={
          <>
            <Dropdown
              id="filter-quota-deploy"
              type="inline"
              size="lg"
              hideLabel
              titleText="Deployment"
              label="Deployment: All"
              items={deployItems}
              itemToString={(i) => i?.label ?? ''}
              selectedItem={deployItems.find((i) => i.id === deployFilter)}
              onChange={({ selectedItem }) => setDeployFilter(selectedItem?.id ?? 'all')}
            />
            <Dropdown
              id="filter-quota-metric"
              type="inline"
              size="lg"
              hideLabel
              titleText="Metric"
              label="Metric: All"
              items={METRIC_ITEMS}
              itemToString={(i) => i?.label ?? ''}
              selectedItem={METRIC_ITEMS.find((i) => i.id === metricFilter)}
              onChange={({ selectedItem }) => setMetricFilter(selectedItem?.id ?? 'all')}
            />
            <div style={{ display: 'flex', alignItems: 'center', padding: '0 16px' }}>
              <Toggle
                id="only-breaches"
                size="sm"
                labelText=""
                labelA="Only breaches"
                labelB="Only breaches"
                toggled={onlyBreaches}
                onToggle={setOnlyBreaches}
              />
            </div>
          </>
        }
      />
    </div>
  )
}
