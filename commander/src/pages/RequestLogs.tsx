import { useEffect, useMemo, useState } from 'react'
import {
  Button,
  Dropdown,
} from '@carbon/react'
import { Renew } from '@carbon/icons-react'
import { useStore } from '../store/store'
import { PageHeader } from '../components/PageHeader'
import { DataTableShell, type ShellColumn } from '../components/DataTableShell'
import { EmptyState } from '../components/EmptyState'
import { MethodTag } from '../components/tags/MethodTag'
import { useFirstLoad } from '../hooks/useFirstLoad'
import { platformLogs } from '../lib/api'
import { formatInt } from '../lib/format'
import type { LogEntry } from '../store/types'

// Sentinel for the "no deployment filter" Dropdown option (cross-fleet view).
const ALL_DEPLOYMENTS = 'All deployments'

function logTime(iso: string): string {
  return `${new Date(iso).toISOString().slice(11, 23)} UTC`
}
function statusColor(s: number): string {
  if (s < 300) return 'var(--scns-success)'
  if (s < 400) return 'var(--cds-text-helper)'
  if (s < 500) return 'var(--scns-warn-text)'
  return 'var(--scns-error)'
}

interface LogsLoad {
  loading: boolean
  logs: LogEntry[] | null
  error: string | null
}

// Platform-wide request log: read commander_request_logs in-process (no /control, no proxy). By
// default we tail every package; the deployment Dropdown is an optional filter (deployment_id).
export function RequestLogs() {
  const deployments = useStore((s) => s.deployments)
  // null == "All deployments" (cross-fleet). A non-null id filters to one package.
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [tick, setTick] = useState(0)
  const [state, setState] = useState<LogsLoad>({ loading: false, logs: null, error: null })
  const firstLoad = useFirstLoad()

  useEffect(() => {
    let cancelled = false
    setState({ loading: true, logs: null, error: null })
    platformLogs({ limit: 100, ...(selectedId ? { deployment_id: selectedId } : {}) })
      .then((res) => {
        if (!cancelled) setState({ loading: false, logs: res.data ?? [], error: null })
      })
      .catch((e: unknown) => {
        if (!cancelled)
          setState({ loading: false, logs: null, error: e instanceof Error ? e.message : 'Failed to load logs' })
      })
    return () => {
      cancelled = true
    }
  }, [selectedId, tick])

  // El backend filtra logs por commander_packages.subdomain (== log.deployment_id), no por el id
  // numerico. El dropdown muestra el nombre del package y manda el subdominio como valor.
  const items = useMemo(
    () => [
      { id: '', label: ALL_DEPLOYMENTS },
      ...deployments.map((d) => ({ id: d.package.subdomain, label: d.package.name })),
    ],
    [deployments],
  )
  const logs = state.logs ?? []

  const columns: ShellColumn<LogEntry>[] = [
    {
      key: 'time',
      header: 'Time',
      width: 140,
      render: (l) => <span className="scns-mono" style={{ color: 'var(--cds-text-helper)', fontSize: 12 }}>{logTime(l.occurred_at)}</span>,
    },
    // The origin column only earns its width in the cross-fleet view; hide it when filtered.
    ...(selectedId
      ? []
      : [
          {
            key: 'deployment',
            header: 'Deployment',
            width: 180,
            render: (l: LogEntry) => <span className="scns-mono" style={{ fontSize: 12 }}>{l.deployment_id}</span>,
          } satisfies ShellColumn<LogEntry>,
        ]),
    {
      key: 'method',
      header: 'Method',
      width: 100,
      render: (l) => <MethodTag method={l.method} />,
    },
    {
      key: 'route',
      header: 'Route',
      render: (l) => <span className="scns-mono" style={{ fontSize: 12 }}>{l.route}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      width: 100,
      render: (l) => <span style={{ color: statusColor(l.status), fontWeight: 600 }}>{l.status}</span>,
    },
    {
      key: 'ms',
      header: 'ms',
      align: 'right',
      width: 100,
      render: (l) => <span className="scns-num">{formatInt(l.duration_ms)}</span>,
    },
    {
      key: 'ip',
      header: 'IP',
      width: 160,
      render: (l) => <span className="scns-mono" style={{ color: 'var(--cds-text-helper)', fontSize: 12 }}>{l.ip}</span>,
    },
  ]

  return (
    <div className="scns-page">
      <PageHeader
        title="Request Logs"
        subtitle="Platform-wide request log · read-only"
      />

      <div className="scns-card">
        <DataTableShell
          rows={logs}
          columns={columns}
          getId={(l) => l.id}
          searchText={(l) => `${l.deployment_id} ${l.method} ${l.route} ${l.status} ${l.ip}`}
          searchPlaceholder="Search logs"
          itemLabel="logs"
          loading={state.loading || firstLoad}
          toolbarExtra={
            <>
              <Dropdown
                id="logs-deploy"
                titleText="Deployment"
                hideLabel
                label="Filter by deployment"
                items={items}
                itemToString={(i) => i?.label ?? ''}
                selectedItem={items.find((i) => i.id === (selectedId ?? '')) ?? items[0]}
                onChange={({ selectedItem }) =>
                  setSelectedId(selectedItem && selectedItem.id ? selectedItem.id : null)
                }
                size="lg"
                type="inline"
                style={{ width: 280 }}
              />
              <Button
                kind="ghost"
                size="md"
                renderIcon={Renew}
                onClick={() => setTick((t) => t + 1)}
                disabled={state.loading}
              >
                Refresh
              </Button>
            </>
          }
          empty={
            state.error ? (
              <EmptyState
                title="Logs unavailable"
                description={`Could not load request logs: ${state.error}`}
              />
            ) : (
              <EmptyState title="No recent requests" />
            )
          }
        />
      </div>
    </div>
  )
}
