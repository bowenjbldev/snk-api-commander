import { useMemo, useState } from 'react'
import {
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableHeader,
  TableRow,
  TableToolbar,
  TableToolbarContent,
  Search,
  Dropdown,
  Toggle,
  CodeSnippet,
} from '@carbon/react'
import { useStore } from '../store/store'
import { useLiveTail } from '../hooks/useLiveTail'
import { MethodTag } from '../components/tags/MethodTag'
import { DetailPanel } from '../components/DetailPanel'
import { formatBytes, formatInt } from '../lib/format'
import type { LogEntry } from '../store/types'

function logTime(iso: string): string {
  return `${new Date(iso).toISOString().slice(11, 23)} UTC`
}
function statusColor(status: number): string {
  if (status < 300) return 'var(--scns-success)'
  if (status < 400) return 'var(--cds-text-helper)'
  if (status < 500) return 'var(--scns-warn-text)'
  return 'var(--scns-error)'
}

const CLASSES = [2, 3, 4, 5]

export function RequestLogs() {
  const logs = useStore((s) => s.logs)
  const deployments = useStore((s) => s.deployments)

  const [live, setLive] = useState(true)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [routeQuery, setRouteQuery] = useState('')
  const [deployFilter, setDeployFilter] = useState('all')
  const [methodFilter, setMethodFilter] = useState('all')
  const [classes, setClasses] = useState<Set<number>>(new Set(CLASSES))

  // Live tail pauses on toggle-off OR when a row is selected.
  useLiveTail(live, selectedId != null)

  const deployItems = useMemo(
    () => [{ id: 'all', label: 'Deployment: All' }, ...deployments.map((d) => ({ id: d.id, label: d.id }))],
    [deployments],
  )
  const methodItems = [
    { id: 'all', label: 'Method: All' },
    { id: 'GET', label: 'GET' },
    { id: 'POST', label: 'POST' },
  ]
  const rangeItems = [
    { id: '15m', label: 'last 15 min' },
    { id: '1h', label: 'last 1 hour' },
    { id: '24h', label: 'last 24 hours' },
  ]

  const filtered = useMemo(
    () =>
      logs.filter((l) => {
        if (routeQuery && !l.route.toLowerCase().includes(routeQuery.toLowerCase())) return false
        if (deployFilter !== 'all' && l.deployment_id !== deployFilter) return false
        if (methodFilter !== 'all' && l.method !== methodFilter) return false
        if (!classes.has(Math.floor(l.status / 100))) return false
        return true
      }),
    [logs, routeQuery, deployFilter, methodFilter, classes],
  )

  const selected = selectedId ? logs.find((l) => l.id === selectedId) ?? null : null

  const toggleClass = (c: number) =>
    setClasses((prev) => {
      const next = new Set(prev)
      if (next.has(c)) next.delete(c)
      else next.add(c)
      return next
    })

  return (
    <div className="scns-page">
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: 24 }}>
        <div>
          <h1 style={{ fontSize: 24, fontWeight: 400, margin: 0 }}>Request Logs</h1>
          <div className="scns-helper" style={{ marginTop: 6, fontSize: 13 }}>
            raw request log · retention 30 days · read-only
          </div>
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <span
            className={`${live ? 'scns-pulse' : ''}`}
            style={{ width: 8, height: 8, borderRadius: '50%', background: live ? '#42be65' : 'var(--cds-text-helper)' }}
            aria-hidden="true"
          />
          <Toggle
            id="live-tail"
            size="sm"
            labelText=""
            labelA="Live tail"
            labelB="Live tail"
            toggled={live}
            onToggle={setLive}
          />
          <span className="scns-mono scns-helper" style={{ fontSize: 11 }}>
            {selectedId ? 'paused · row selected' : live ? 'streaming · ~43 req/min' : 'paused'}
          </span>
        </div>
      </div>

      <div style={{ display: 'flex', gap: 16, alignItems: 'flex-start' }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <TableContainer>
            <TableToolbar>
              <TableToolbarContent>
                <Dropdown
                  id="logs-deploy"
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
                  id="logs-method"
                  type="inline"
                  size="lg"
                  hideLabel
                  titleText="Method"
                  label="Method: All"
                  items={methodItems}
                  itemToString={(i) => i?.label ?? ''}
                  selectedItem={methodItems.find((i) => i.id === methodFilter)}
                  onChange={({ selectedItem }) => setMethodFilter(selectedItem?.id ?? 'all')}
                />
                <Dropdown
                  id="logs-range"
                  type="inline"
                  size="lg"
                  hideLabel
                  titleText="Time range"
                  label="last 15 min"
                  items={rangeItems}
                  itemToString={(i) => i?.label ?? ''}
                  selectedItem={rangeItems[0]}
                  onChange={() => {}}
                />
                <div style={{ display: 'flex', gap: 4, alignItems: 'center', padding: '0 8px' }}>
                  {CLASSES.map((c) => {
                    const on = classes.has(c)
                    return (
                      <button
                        key={c}
                        type="button"
                        onClick={() => toggleClass(c)}
                        className="scns-mono"
                        style={{
                          fontSize: 11,
                          padding: '2px 8px',
                          borderRadius: 24,
                          cursor: 'pointer',
                          border: '1px solid var(--cds-border-subtle)',
                          background: on ? 'var(--cds-layer-accent)' : 'transparent',
                          color: on ? statusColor(c * 100) : 'var(--cds-text-placeholder)',
                        }}
                        aria-pressed={on}
                      >
                        {c}xx
                      </button>
                    )
                  })}
                </div>
                <Search
                  size="lg"
                  labelText="Search routes"
                  placeholder="Search routes"
                  value={routeQuery}
                  onChange={(e) => setRouteQuery(e.target.value)}
                  style={{ maxWidth: 280 }}
                />
              </TableToolbarContent>
            </TableToolbar>
            <div style={{ maxHeight: 'calc(100vh - 280px)', overflow: 'auto' }}>
              <Table size="xs" className="scns-logtable">
                <TableHead>
                  <TableRow>
                    <TableHeader>Time</TableHeader>
                    <TableHeader>Deployment</TableHeader>
                    <TableHeader>Method</TableHeader>
                    <TableHeader>Route</TableHeader>
                    <TableHeader>Status</TableHeader>
                    <TableHeader style={{ textAlign: 'right' }}>ms</TableHeader>
                    <TableHeader>User</TableHeader>
                    <TableHeader style={{ textAlign: 'right' }}>Bytes</TableHeader>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {filtered.map((l) => (
                    <TableRow
                      key={l.id}
                      className={`scns-logrow${selectedId === l.id ? ' scns-logrow--sel' : ''}`}
                      isSelected={selectedId === l.id}
                      onClick={() => setSelectedId(l.id)}
                    >
                      <TableCell style={{ color: 'var(--cds-text-helper)' }}>{logTime(l.occurred_at)}</TableCell>
                      <TableCell>{l.deployment_id}</TableCell>
                      <TableCell>
                        <MethodTag method={l.method} />
                      </TableCell>
                      <TableCell style={{ maxWidth: 240, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        {l.route}
                      </TableCell>
                      <TableCell style={{ color: statusColor(l.status), fontWeight: 600 }}>{l.status}</TableCell>
                      <TableCell style={{ textAlign: 'right', color: l.status >= 500 || l.duration_ms > 1000 ? 'var(--scns-error)' : undefined }}>
                        {formatInt(l.duration_ms)}
                      </TableCell>
                      <TableCell style={{ color: l.user_id ? undefined : 'var(--cds-text-placeholder)' }}>
                        {l.user_id ?? '—'}
                      </TableCell>
                      <TableCell style={{ textAlign: 'right' }}>{formatBytes(l.response_bytes)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
          </TableContainer>
        </div>

        {selected && <LogDetail entry={selected} onClose={() => setSelectedId(null)} />}
      </div>
    </div>
  )
}

function LogDetail({ entry, onClose }: { entry: LogEntry; onClose: () => void }) {
  return (
    <DetailPanel
      title="Request detail"
      onClose={onClose}
      headerExtra={<span className="scns-mono" style={{ color: statusColor(entry.status), fontWeight: 600 }}>{entry.status}</span>}
    >
      <div className="scns-mono" style={{ fontSize: 12, display: 'flex', flexDirection: 'column', gap: 6 }}>
        <Row k="occurred_at" v={entry.occurred_at} />
        <Row k="deployment" v={entry.deployment_id} />
        <Row k="method · route" v={`${entry.method} ${entry.route}`} />
        <Row k="duration_ms" v={String(entry.duration_ms)} />
        <Row k="user_id" v={entry.user_id ?? '—'} />
        <Row k="token_id" v={entry.token_id} />
        <Row k="ip" v={entry.ip} />
        <Row k="request_bytes" v={formatInt(entry.request_bytes)} />
        <Row k="response_bytes" v={formatInt(entry.response_bytes)} />
      </div>
      {entry.response_body != null && (
        <div style={{ marginTop: 16 }}>
          <p className="scns-section-label">Response body</p>
          <CodeSnippet type="multi" feedback="Copied">
            {JSON.stringify(entry.response_body, null, 2)}
          </CodeSnippet>
        </div>
      )}
      {entry.context && (
        <div
          style={{
            marginTop: 16,
            padding: 12,
            background: 'var(--cds-layer-accent)',
            fontSize: 12,
          }}
        >
          {entry.context}.{' '}
          <a className="scns-link" href="/quotas">
            View quota →
          </a>
        </div>
      )}
    </DetailPanel>
  )
}

function Row({ k, v }: { k: string; v: string }) {
  return (
    <div style={{ display: 'flex', gap: 8 }}>
      <span className="scns-helper" style={{ width: 120, flex: 'none' }}>
        {k}
      </span>
      <span style={{ wordBreak: 'break-all' }}>{v}</span>
    </div>
  )
}
