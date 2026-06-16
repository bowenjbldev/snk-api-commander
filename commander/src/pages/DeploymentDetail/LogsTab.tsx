import { useEffect, useState } from 'react'
import { useOutletContext, Link } from 'react-router'
import {
  Button,
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
import type { Deployment, LogEntry } from '../../store/types'
import { EmptyState } from '../../components/EmptyState'
import { MethodTag } from '../../components/tags/MethodTag'
import { deploymentLogs } from '../../lib/api'
import { formatInt } from '../../lib/format'

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

// Real recent requests for this deployment — proxied from its signed /control/logs (core_request_logs).
export function LogsTab() {
  const dep = useOutletContext<Deployment>()
  const [tick, setTick] = useState(0)
  const [state, setState] = useState<LogsLoad>({ loading: true, logs: null, error: null })

  useEffect(() => {
    let cancelled = false
    setState({ loading: true, logs: null, error: null })
    deploymentLogs(dep.id, { limit: 50 })
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
  }, [dep.id, tick])

  const logs = state.logs ?? []

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <span className="scns-helper" style={{ fontSize: 13 }}>
          Recent requests for this deployment ·{' '}
          <Link className="scns-link" to="/request-logs">
            full request logs →
          </Link>
        </span>
        <Button kind="ghost" size="sm" renderIcon={Renew} onClick={() => setTick((t) => t + 1)} disabled={state.loading}>
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
          description="No request-log rows in the retention window. Metering records each request; a freshly provisioned deploy fills in as traffic arrives."
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
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}
    </div>
  )
}
