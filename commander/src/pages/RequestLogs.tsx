import { useEffect, useMemo, useState } from 'react'
import {
  Button,
  Dropdown,
  Loading,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableHeader,
  TableRow,
} from '@carbon/react'
import { Renew } from '@carbon/icons-react'
import { useStore } from '../store/store'
import { EmptyState } from '../components/EmptyState'
import { MethodTag } from '../components/tags/MethodTag'
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

  return (
    <div className="scns-page">
      <div style={{ marginBottom: 24 }}>
        <h1 style={{ fontSize: 24, fontWeight: 400, margin: 0 }}>Request Logs</h1>
        <div className="scns-helper" style={{ marginTop: 6, fontSize: 13 }}>
          real request log per deployment · read-only
        </div>
      </div>

      {deployments.length === 0 ? (
        <EmptyState
          title="No deployments yet"
          description="Provision a deployment (scripts/subscription.sh add …) to see its request logs here."
        />
      ) : (
        <>
          <div style={{ display: 'flex', gap: 12, alignItems: 'flex-end', marginBottom: 16 }}>
            <Dropdown
              id="logs-deploy"
              titleText="Deployment"
              label="Select a deployment"
              items={items}
              selectedItem={selectedId}
              onChange={({ selectedItem }) => setSelectedId((selectedItem as string | null) ?? null)}
              size="sm"
              style={{ minWidth: 280 }}
            />
            <Button
              kind="ghost"
              size="sm"
              renderIcon={Renew}
              onClick={() => setTick((t) => t + 1)}
              disabled={state.loading || !selectedId}
            >
              Refresh
            </Button>
          </div>

          {state.loading ? (
            <Loading withOverlay={false} description="Loading logs…" />
          ) : state.error ? (
            <EmptyState
              title="Logs unavailable"
              description={`Could not reach the deploy's control plane: ${state.error}`}
            />
          ) : logs.length === 0 ? (
            <EmptyState
              title="No recent requests"
              description="This deployment has no request-log rows in the retention window."
            />
          ) : (
            <TableContainer>
              <Table size="xs" className="scns-logtable">
                <TableHead>
                  <TableRow>
                    <TableHeader>Time</TableHeader>
                    <TableHeader>Method</TableHeader>
                    <TableHeader>Route</TableHeader>
                    <TableHeader>Status</TableHeader>
                    <TableHeader style={{ textAlign: 'right' }}>ms</TableHeader>
                    <TableHeader>IP</TableHeader>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {logs.map((l) => (
                    <TableRow key={l.id}>
                      <TableCell style={{ color: 'var(--cds-text-helper)' }}>{logTime(l.occurred_at)}</TableCell>
                      <TableCell>
                        <MethodTag method={l.method} />
                      </TableCell>
                      <TableCell>{l.route}</TableCell>
                      <TableCell style={{ color: statusColor(l.status), fontWeight: 600 }}>{l.status}</TableCell>
                      <TableCell style={{ textAlign: 'right' }}>{formatInt(l.duration_ms)}</TableCell>
                      <TableCell style={{ color: 'var(--cds-text-helper)' }}>{l.ip}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </TableContainer>
          )}
        </>
      )}
    </div>
  )
}
