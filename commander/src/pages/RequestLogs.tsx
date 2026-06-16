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
import { deploymentLogs } from '../lib/api'
import { formatInt } from '../lib/format'
import type { LogEntry } from '../store/types'

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

// Real request logs: pick a deployment, fetch its recent core_request_logs (proxied via signed
// /control/logs). No cross-fleet synthetic tail — raw logs are per-deploy and high-volume.
export function RequestLogs() {
  const deployments = useStore((s) => s.deployments)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [tick, setTick] = useState(0)
  const [state, setState] = useState<LogsLoad>({ loading: false, logs: null, error: null })
  const firstLoad = useFirstLoad()

  // Default to the first deployment once the registry loads.
  useEffect(() => {
    if (selectedId == null && deployments.length > 0) setSelectedId(deployments[0].id)
  }, [deployments, selectedId])

  useEffect(() => {
    if (!selectedId) return
    let cancelled = false
    setState({ loading: true, logs: null, error: null })
    deploymentLogs(selectedId, { limit: 100 })
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

  const items = useMemo(() => deployments.map((d) => d.id), [deployments])
  const logs = state.logs ?? []

  const columns: ShellColumn<LogEntry>[] = [
    {
      key: 'time',
      header: 'Time',
      width: 140,
      render: (l) => <span className="scns-mono" style={{ color: 'var(--cds-text-helper)', fontSize: 12 }}>{logTime(l.occurred_at)}</span>,
    },
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
        subtitle="Real request log per deployment · read-only"
      />

      <div className="scns-card">
        <DataTableShell
          rows={logs}
          columns={columns}
          getId={(l) => l.id}
          searchText={(l) => `${l.method} ${l.route} ${l.status} ${l.ip}`}
          searchPlaceholder="Search logs"
          itemLabel="logs"
          loading={state.loading || firstLoad}
          toolbarExtra={
            <>
              <Dropdown
                id="logs-deploy"
                titleText="Deployment"
                hideLabel
                label="Select a deployment"
                items={items}
                selectedItem={selectedId}
                onChange={({ selectedItem }) => setSelectedId((selectedItem as string | null) ?? null)}
                size="lg"
                type="inline"
                style={{ width: 280 }}
              />
              <Button
                kind="ghost"
                size="md"
                renderIcon={Renew}
                onClick={() => setTick((t) => t + 1)}
                disabled={state.loading || !selectedId}
              >
                Refresh
              </Button>
            </>
          }
          empty={
            deployments.length === 0 ? (
              <EmptyState title="No deployments yet" />
            ) : state.error ? (
              <EmptyState
                title="Logs unavailable"
                description={`Could not reach the deploy's control plane: ${state.error}`}
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
