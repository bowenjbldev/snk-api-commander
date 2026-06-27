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
import type { Package, LogEntry, PackageContext } from '../../store/types'
import { EmptyState } from '../../components/EmptyState'
import { MethodTag } from '../../components/tags/MethodTag'
import { packagesApi } from '../../lib/api'
import { formatInt } from '../../lib/format'
import { logTime } from '../../lib/time'
import { statusColor } from '../../lib/statusColor'

interface LogsLoad {
  loading: boolean
  logs: LogEntry[] | null
  error: string | null
}

export function LogsTab() {
  const { packageRow } = useOutletContext<PackageContext>()

  if (!packageRow) {
    return (
      <EmptyState
        title="No live instance yet — provision one to see logs"
        description="Recent request logs appear here once this package is deployed and serving traffic."
      />
    )
  }

  return <LogsView pkg={packageRow} />
}

// Real recent requests for this package — read in-process from commander_request_logs.
function LogsView({ pkg }: { pkg: Package }) {
  const [tick, setTick] = useState(0)
  const [state, setState] = useState<LogsLoad>({ loading: true, logs: null, error: null })

  useEffect(() => {
    let cancelled = false
    setState({ loading: true, logs: null, error: null })
    packagesApi.logs(pkg.name, { limit: 50 })
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
  }, [pkg.name, tick])

  const logs = state.logs ?? []

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <span className="scns-helper" style={{ fontSize: 13 }}>
          Recent requests for this package ·{' '}
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
          description={`Could not load logs: ${state.error}`}
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
                  <TableCell style={{ color: 'var(--cds-text-helper)' }}>{logTime(l.created_at)}</TableCell>
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
