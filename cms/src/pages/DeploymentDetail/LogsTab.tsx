import { useMemo } from 'react'
import { useOutletContext, Link } from 'react-router'
import { Table, TableBody, TableCell, TableContainer, TableHead, TableHeader, TableRow } from '@carbon/react'
import type { Deployment } from '../../store/types'
import { useStore } from '../../store/store'
import { MethodTag } from '../../components/tags/MethodTag'
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

export function LogsTab() {
  const dep = useOutletContext<Deployment>()
  const allLogs = useStore((s) => s.logs)
  const logs = useMemo(
    () => allLogs.filter((l) => l.deployment_id === dep.id).slice(0, 25),
    [allLogs, dep.id],
  )

  return (
    <div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
        <span className="scns-helper" style={{ fontSize: 13 }}>
          Recent requests for this deployment
        </span>
        <Link className="scns-link" to="/request-logs">
          Open full request logs →
        </Link>
      </div>
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
      {logs.length === 0 && (
        <p className="scns-helper" style={{ padding: '24px 0' }}>
          No recent requests recorded for this deployment.
        </p>
      )}
    </div>
  )
}
