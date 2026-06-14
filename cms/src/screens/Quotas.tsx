import { useMemo, useState, type CSSProperties } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Dropdown, Select, SelectItem, Tag, Toggle } from '@carbon/react'
import {
  PageHeader,
  AmberTag,
  Mono,
  QuotaBar,
  OverBadge,
  InlineEdit,
  RelativeTime,
  DataGrid,
  EmptyState,
  quotaTone,
  type GridColumn,
} from '../components/ui'
import { useStore } from '../store/DataStore'
import { useToast } from '../notify/ToastProvider'
import { useNowMs } from '../lib/time'
import { fmtInt, pct } from '../lib/format'
import type { Quota, QuotaMetric, QuotaPolicy } from '../types'

const ALL = '__all__'

const METRICS: QuotaMetric[] = [
  'requests_day',
  'disk_db_mb',
  'disk_storage_mb',
  'users_total',
]

const POLICIES: QuotaPolicy[] = ['none', 'warn', 'suspend']

/** Live utilisation fraction; unlimited limits collapse to 0. */
function frac(q: Quota): number {
  return q.limit != null ? q.current / q.limit : 0
}

const POLICY_COLOR: Record<QuotaPolicy, string> = {
  none: 'var(--cds-text-helper)',
  warn: 'var(--sc-support-warning)',
  suspend: 'var(--sc-support-error)',
}

export function Quotas() {
  const { quotas, updateQuotaLimit, updateQuotaPolicy } = useStore()
  const toast = useToast()
  useNowMs()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()

  const [deploymentFilter, setDeploymentFilter] = useState<string>(
    () => searchParams.get('deployment') ?? ALL,
  )
  const [metricFilter, setMetricFilter] = useState<string>(ALL)
  const [onlyBreaches, setOnlyBreaches] = useState(false)

  // Distinct deployment ids present in the quota set (sorted).
  const deploymentIds = useMemo(
    () => [...new Set(quotas.map((q) => q.deploymentId))].sort(),
    [quotas],
  )

  const overCount = useMemo(
    () => quotas.filter((q) => q.limit != null && frac(q) > 1).length,
    [quotas],
  )
  const warnCount = useMemo(
    () =>
      quotas.filter((q) => {
        if (q.limit == null) return false
        const f = frac(q)
        return f >= 0.8 && f <= 1
      }).length,
    [quotas],
  )

  const filteredRows = useMemo(() => {
    return quotas.filter((q) => {
      if (deploymentFilter !== ALL && q.deploymentId !== deploymentFilter)
        return false
      if (metricFilter !== ALL && q.metric !== metricFilter) return false
      if (onlyBreaches) {
        const f = frac(q)
        const over = q.limit != null && f > 1
        if (!(over || (q.limit != null && f >= 0.8))) return false
      }
      return true
    })
  }, [quotas, deploymentFilter, metricFilter, onlyBreaches])

  const deploymentItems = [ALL, ...deploymentIds]
  const metricItems = [ALL, ...METRICS]
  const itemLabel = (id: string) => (id === ALL ? 'All' : id)

  const toolbarContent = (
    <>
      <Dropdown
        id="quota-deployment-filter"
        size="lg"
        type="inline"
        titleText="Deployment"
        label="Deployment"
        items={deploymentItems}
        selectedItem={deploymentFilter}
        itemToString={itemLabel}
        onChange={({ selectedItem }) =>
          setDeploymentFilter(selectedItem ?? ALL)
        }
      />
      <Dropdown
        id="quota-metric-filter"
        size="lg"
        type="inline"
        titleText="Metric"
        label="Metric"
        items={metricItems}
        selectedItem={metricFilter}
        itemToString={itemLabel}
        onChange={({ selectedItem }) => setMetricFilter(selectedItem ?? ALL)}
      />
      <Toggle
        id="quota-only-breaches"
        size="sm"
        labelText=""
        labelA="Only breaches"
        labelB="Only breaches"
        hideLabel
        toggled={onlyBreaches}
        onToggle={setOnlyBreaches}
      />
    </>
  )

  const columns: GridColumn<Quota>[] = [
    {
      key: 'deployment',
      header: 'Deployment',
      sortValue: (q) => q.deploymentId,
      render: (q) => (
        <span
          onClick={() => navigate('/deployments/' + q.deploymentId)}
          style={{ cursor: 'pointer' }}
        >
          <Mono color="var(--cds-link-primary)">{q.deploymentId}</Mono>
        </span>
      ),
    },
    {
      key: 'metric',
      header: 'Metric',
      sortValue: (q) => q.metric,
      render: (q) => <Mono>{q.metric}</Mono>,
    },
    {
      key: 'current',
      header: 'Current usage',
      sortValue: (q) => (q.limit ? q.current / q.limit : 0),
      render: (q) => {
        const tone = quotaTone(q.current, q.limit)
        const over = q.limit != null && q.current / q.limit > 1
        return (
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <QuotaBar current={q.current} limit={q.limit} width={200} />
            <Mono>{fmtInt(q.current)}</Mono>
            {over && q.limit != null ? (
              <OverBadge fraction={q.current / q.limit} />
            ) : q.limit == null ? (
              <Mono color="var(--cds-text-helper)">no limit</Mono>
            ) : (
              <Mono
                color={
                  tone === 'warn'
                    ? 'var(--sc-support-warning)'
                    : 'var(--cds-text-secondary)'
                }
              >
                {pct(q.current / q.limit)}
              </Mono>
            )}
          </div>
        )
      },
    },
    {
      key: 'limit',
      header: 'Limit',
      sortValue: (q) => (q.limit == null ? -1 : q.limit),
      render: (q) => (
        <InlineEdit
          value={q.limit == null ? '' : fmtInt(q.limit)}
          placeholder="Unlimited"
          isPlaceholder={q.limit == null}
          ariaLabel={q.deploymentId + ' ' + q.metric + ' limit'}
          parse={(raw) =>
            raw === '' || !isNaN(Number(raw.replace(/,/g, '')))
          }
          onCommit={(raw) => {
            const v =
              raw.trim() === ''
                ? null
                : Math.round(Number(raw.replace(/,/g, '')))
            updateQuotaLimit(q.id, v)
            toast.success('Limit updated', q.deploymentId + ' · ' + q.metric)
          }}
        />
      ),
    },
    {
      key: 'onExceed',
      header: 'On exceed',
      sortValue: (q) => q.onExceed,
      width: 132,
      render: (q) => {
        // Fixed width so the native select never collapses to "n."/"w." when the
        // Limit cell expands during inline edit; color cues the policy severity.
        const selectStyle: CSSProperties = {
          width: 116,
          color: POLICY_COLOR[q.onExceed],
        }
        return (
          <div
            style={selectStyle}
            className={`sc-mono sc-policy-select sc-policy-${q.onExceed}`}
          >
            <Select
              id={`quota-policy-${q.id}`}
              size="sm"
              labelText=""
              hideLabel
              value={q.onExceed}
              onChange={(e) => {
                const policy = e.target.value as QuotaPolicy
                updateQuotaPolicy(q.id, policy)
                toast.success('Policy updated', q.deploymentId + ' · ' + policy)
              }}
            >
              {POLICIES.map((p) => (
                <SelectItem key={p} value={p} text={p} />
              ))}
            </Select>
          </div>
        )
      },
    },
    {
      key: 'exceeded',
      header: 'Exceeded since',
      sortValue: (q) => q.exceededSince ?? '',
      render: (q) =>
        q.exceededSince ? (
          <Mono color="var(--sc-support-error)">
            <RelativeTime iso={q.exceededSince} mono />
          </Mono>
        ) : (
          <span style={{ color: 'var(--cds-text-helper)' }}>—</span>
        ),
    },
  ]

  const right = (
    <>
      {overCount > 0 && <Tag type="red">{overCount} OVER</Tag>}
      {warnCount > 0 && <AmberTag>{warnCount} ≥ 80%</AmberTag>}
    </>
  )

  return (
    <div className="sc-page">
      <PageHeader
        title="Quotas"
        subtitle="Limits are inline-editable · empty = unlimited · bars turn amber at 80%, red with an OVER badge past 100%"
        right={right}
      />
      <DataGrid
        rows={filteredRows}
        getRowId={(q) => q.id}
        size="lg"
        columns={columns}
        searchText={(q) => `${q.deploymentId} ${q.metric}`}
        searchPlaceholder="Search…"
        initialSort={{ key: 'deployment', dir: 'ASC' }}
        toolbarContent={toolbarContent}
        emptyState={
          <EmptyState
            title="No quotas match"
            description="Adjust the deployment, metric, or breach filters."
          />
        }
      />
    </div>
  )
}
