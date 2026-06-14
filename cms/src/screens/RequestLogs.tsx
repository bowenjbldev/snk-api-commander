import { useEffect, useMemo, useRef, useState } from 'react'
import type { CSSProperties } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import {
  Dropdown,
  IconButton,
  Search,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableHeader,
  TableRow,
  Toggle,
} from '@carbon/react'
import { Close } from '@carbon/icons-react'
import type { HttpMethod, RequestLog } from '../types'
import { useStore } from '../store/DataStore'
import { useToast } from '../notify/ToastProvider'
import { simNowMs } from '../lib/time'
import { absoluteUTCSeconds, clockMs, fmtInt, fmtLogBytes } from '../lib/format'
import {
  KV,
  MethodTag,
  Mono,
  PageHeader,
  StatusCode,
  StatusDot,
} from '../components/ui'
import { requestLogs } from '../data/dataset'

// --- local helpers -------------------------------------------------------
const ALL = '__all__'
type StatusClass = '2xx' | '3xx' | '4xx' | '5xx'
const STATUS_CLASSES: StatusClass[] = ['2xx', '3xx', '4xx', '5xx']
const METHODS: HttpMethod[] = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']
const TIME_RANGES = ['last 15 min', 'last 1 hour', 'last 6 hours', 'last 24 hours']
const MAX_ROWS = 200

function statusClassOf(status: number): StatusClass {
  if (status < 300) return '2xx'
  if (status < 400) return '3xx'
  if (status < 500) return '4xx'
  return '5xx'
}

// Synthetic samples for the live tail.
const TAIL_ROUTES: { route: string; method: HttpMethod }[] = [
  { route: '/v1/track/SHP-88512', method: 'GET' },
  { route: '/v1/telemetry/batch', method: 'POST' },
  { route: '/v1/metrics/query?window=1h', method: 'GET' },
  { route: '/v1/orders', method: 'POST' },
  { route: '/v1/fleet/status', method: 'GET' },
  { route: '/v1/auth/token', method: 'POST' },
  { route: '/v1/ledger/entries?from=2026-06-01', method: 'GET' },
  { route: '/v1/assets/d31c.png', method: 'GET' },
  { route: '/v1/users/me', method: 'GET' },
  { route: '/v1/routes/optimize', method: 'POST' },
]

function pick<T>(arr: readonly T[]): T {
  return arr[Math.floor(Math.random() * arr.length)]
}

/** Status weighted heavily to 2xx, with occasional 3xx/4xx/5xx. */
function weightedStatus(): number {
  const r = Math.random()
  if (r < 0.82) return pick([200, 200, 201, 204])
  if (r < 0.9) return 304
  if (r < 0.98) return pick([400, 401, 404, 429])
  return pick([500, 502, 503])
}

let tailSeq = 0
function genLog(deploymentIds: string[]): RequestLog {
  const sample = pick(TAIL_ROUTES)
  const status = weightedStatus()
  const isError = status >= 400
  const userId = Math.random() < 0.15 ? null : `usr_${Math.floor(Math.random() * 9000) + 100}`
  tailSeq += 1
  return {
    id: `log-live-${tailSeq}`,
    occurredAt: new Date(simNowMs()).toISOString(),
    deploymentId: deploymentIds.length ? pick(deploymentIds) : 'kestrel-track',
    method: sample.method,
    route: sample.route,
    status,
    durationMs: isError && status >= 500 ? Math.floor(Math.random() * 1800) + 600 : Math.floor(Math.random() * 480) + 6,
    userId,
    tokenId: userId ? `tok_live_${Math.random().toString(16).slice(2, 6)}` : '—',
    ip: `${Math.floor(Math.random() * 200) + 10}.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}.${Math.floor(Math.random() * 255)}`,
    requestBytes: Math.floor(Math.random() * 4000) + 120,
    responseBytes: Math.floor(Math.random() * 12000) + 200,
  }
}

const TD_MONO: CSSProperties = {
  fontFamily: "'IBM Plex Mono', monospace",
  fontSize: 11.5,
  whiteSpace: 'nowrap',
}

export function RequestLogs() {
  const { deployments } = useStore()
  const toast = useToast()
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()

  const deploymentIds = useMemo(
    () => deployments.map((d) => d.id),
    [deployments],
  )

  const [logs, setLogs] = useState<RequestLog[]>(() => [...requestLogs])
  const [live, setLive] = useState(true)
  const [selectedId, setSelectedId] = useState<string | null>(null)

  // filters
  const [routeQuery, setRouteQuery] = useState('')
  const [deploymentFilter, setDeploymentFilter] = useState<string>(
    () => searchParams.get('deployment') ?? ALL,
  )
  const [methodFilter, setMethodFilter] = useState<string>(ALL)
  const [timeRange, setTimeRange] = useState<string>(TIME_RANGES[0])
  const [statusActive, setStatusActive] = useState<Set<StatusClass>>(
    () => new Set(STATUS_CLASSES),
  )

  // Keep a stable ref to deployment ids for the interval closure.
  const depIdsRef = useRef(deploymentIds)
  depIdsRef.current = deploymentIds

  const paused = !live || selectedId !== null

  // Live tail — prepend synthetic logs while running.
  useEffect(() => {
    if (paused) return
    const t = window.setInterval(() => {
      setLogs((prev) => {
        const next = [genLog(depIdsRef.current), ...prev]
        return next.length > MAX_ROWS ? next.slice(0, MAX_ROWS) : next
      })
    }, 1500)
    return () => window.clearInterval(t)
  }, [paused])

  const toggleStatusClass = (cls: StatusClass) => {
    setStatusActive((prev) => {
      const next = new Set(prev)
      if (next.has(cls)) next.delete(cls)
      else next.add(cls)
      return next
    })
  }

  const filtered = useMemo(() => {
    const q = routeQuery.trim().toLowerCase()
    return logs.filter((l) => {
      if (q && !l.route.toLowerCase().includes(q)) return false
      if (deploymentFilter !== ALL && l.deploymentId !== deploymentFilter) return false
      if (methodFilter !== ALL && l.method !== methodFilter) return false
      if (!statusActive.has(statusClassOf(l.status))) return false
      return true
    })
  }, [logs, routeQuery, deploymentFilter, methodFilter, statusActive])

  const selected = useMemo(
    () => (selectedId ? logs.find((l) => l.id === selectedId) ?? null : null),
    [logs, selectedId],
  )

  const itemLabel = (id: string | null) => (id == null || id === ALL ? 'All' : id)

  const liveControl = (
    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
      <StatusDot tone={paused ? 'gray' : 'green'} pulse={!paused} />
      <span style={{ fontSize: 13, color: 'var(--cds-text-secondary)' }}>Live tail</span>
      <Toggle
        id="live-tail"
        size="sm"
        toggled={live}
        onToggle={setLive}
        labelA=""
        labelB=""
        hideLabel
        labelText=""
      />
      <Mono size={11} color="var(--cds-text-helper)">
        streaming · 41 req/min
      </Mono>
    </div>
  )

  return (
    <div className="sc-page">
      <PageHeader
        title="Request Logs"
        subtitle="raw request log · retention 30 days · read-only"
        right={liveControl}
      />

      {/* Toolbar */}
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: 12,
          flexWrap: 'wrap',
          marginBottom: 16,
        }}
      >
        <div style={{ flex: '1 1 280px', minWidth: 220, maxWidth: 380 }}>
          <Search
            id="log-route-search"
            size="sm"
            labelText="Search route"
            placeholder="Search route, e.g. /v1/telemetry…"
            value={routeQuery}
            onChange={(e) => setRouteQuery(e.target.value)}
            onClear={() => setRouteQuery('')}
          />
        </div>
        <Dropdown
          id="log-deployment-filter"
          size="sm"
          type="inline"
          titleText="Deployment"
          label="Deployment"
          items={[ALL, ...deploymentIds]}
          selectedItem={deploymentFilter}
          itemToString={itemLabel}
          onChange={({ selectedItem }) => setDeploymentFilter(selectedItem ?? ALL)}
        />
        <Dropdown
          id="log-method-filter"
          size="sm"
          type="inline"
          titleText="Method"
          label="Method"
          items={[ALL, ...METHODS]}
          selectedItem={methodFilter}
          itemToString={itemLabel}
          onChange={({ selectedItem }) => setMethodFilter(selectedItem ?? ALL)}
        />
        <Dropdown
          id="log-time-range"
          size="sm"
          type="inline"
          titleText="Range"
          label="Range"
          items={TIME_RANGES}
          selectedItem={timeRange}
          itemToString={(x: string | null) => x ?? TIME_RANGES[0]}
          renderSelectedItem={(x: string) => <span className="sc-mono">{x}</span>}
          onChange={({ selectedItem }) => setTimeRange(selectedItem ?? TIME_RANGES[0])}
        />
        <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
          {STATUS_CLASSES.map((cls) => {
            const on = statusActive.has(cls)
            return (
              <button
                key={cls}
                type="button"
                className="sc-mono"
                aria-pressed={on}
                onClick={() => toggleStatusClass(cls)}
                style={{
                  cursor: 'pointer',
                  userSelect: 'none',
                  height: 22,
                  padding: '0 10px',
                  borderRadius: 24,
                  fontSize: 11,
                  border: '1px solid var(--cds-border-subtle)',
                  background: on ? 'var(--cds-layer-accent)' : 'transparent',
                  color: on ? 'var(--cds-text-primary)' : 'var(--cds-text-placeholder)',
                }}
              >
                {cls}
              </button>
            )
          })}
        </div>
      </div>

      {/* Body — table + detail panel */}
      <div style={{ display: 'flex', gap: 16, alignItems: 'flex-start' }}>
        <div style={{ flex: 1, minWidth: 0, overflowX: 'auto' }}>
          <TableContainer style={{ border: '1px solid var(--cds-border-subtle)' }}>
            <Table size="xs" useZebraStyles={false}>
              <TableHead>
                <TableRow>
                  <TableHeader style={TD_MONO}>time</TableHeader>
                  <TableHeader style={TD_MONO}>deployment</TableHeader>
                  <TableHeader style={TD_MONO}>method</TableHeader>
                  <TableHeader style={TD_MONO}>route</TableHeader>
                  <TableHeader style={TD_MONO}>status</TableHeader>
                  <TableHeader style={{ ...TD_MONO, textAlign: 'right' }}>ms</TableHeader>
                  {/* user/bytes hide when the detail panel is open (shown there). */}
                  {!selected && <TableHeader style={TD_MONO}>user</TableHeader>}
                  {!selected && (
                    <TableHeader style={{ ...TD_MONO, textAlign: 'right' }}>
                      bytes
                    </TableHeader>
                  )}
                </TableRow>
              </TableHead>
              <TableBody>
                {filtered.map((l) => {
                  const isSel = l.id === selectedId
                  const msColor =
                    l.status >= 500 || l.durationMs > 1000
                      ? 'var(--sc-support-error)'
                      : 'var(--cds-text-primary)'
                  return (
                    <TableRow
                      key={l.id}
                      onClick={() => setSelectedId(l.id)}
                      style={{
                        cursor: 'pointer',
                        height: 34,
                        background: isSel ? 'var(--cds-layer-selected)' : undefined,
                        boxShadow: isSel
                          ? 'inset 3px 0 0 var(--cds-link-primary)'
                          : undefined,
                      }}
                    >
                      <TableCell style={{ ...TD_MONO, color: 'var(--cds-text-helper)' }}>
                        {clockMs(l.occurredAt)}
                      </TableCell>
                      <TableCell style={TD_MONO}>{l.deploymentId}</TableCell>
                      <TableCell style={TD_MONO}>
                        <MethodTag method={l.method} />
                      </TableCell>
                      <TableCell
                        style={{
                          ...TD_MONO,
                          maxWidth: 260,
                          overflow: 'hidden',
                          textOverflow: 'ellipsis',
                        }}
                        title={l.route}
                      >
                        {l.route}
                      </TableCell>
                      <TableCell style={TD_MONO}>
                        <StatusCode status={l.status} />
                      </TableCell>
                      <TableCell style={{ ...TD_MONO, textAlign: 'right', color: msColor }}>
                        {l.durationMs}
                      </TableCell>
                      {!selected && (
                        <TableCell style={TD_MONO}>{l.userId ?? '—'}</TableCell>
                      )}
                      {!selected && (
                        <TableCell style={{ ...TD_MONO, textAlign: 'right' }}>
                          {fmtLogBytes(l.responseBytes)}
                        </TableCell>
                      )}
                    </TableRow>
                  )
                })}
              </TableBody>
            </Table>
          </TableContainer>
        </div>

        {selected && (
          <aside className="sc-detail-panel">
            <div
              style={{
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                gap: 8,
                padding: '12px 14px',
                borderBottom: '1px solid var(--cds-border-subtle)',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                <span style={{ fontSize: 14, fontWeight: 600 }}>Request detail</span>
                <StatusCode status={selected.status} />
              </div>
              <IconButton
                label="Close"
                kind="ghost"
                size="sm"
                onClick={() => setSelectedId(null)}
              >
                <Close />
              </IconButton>
            </div>

            <div style={{ padding: '4px 14px 14px' }}>
              <KV label="occurred_at">
                <Mono size={12}>{absoluteUTCSeconds(selected.occurredAt)}</Mono>
              </KV>
              <KV label="deployment">
                <Mono size={12}>{selected.deploymentId}</Mono>
              </KV>
              <KV label="method · route">
                <Mono size={12} title={selected.route}>
                  {selected.method} {selected.route}
                </Mono>
              </KV>
              <KV label="duration_ms">
                <Mono size={12}>{fmtInt(selected.durationMs)}</Mono>
              </KV>
              <KV label="user_id">
                <Mono size={12}>{selected.userId ?? '—'}</Mono>
              </KV>
              <KV label="token_id">
                <Mono size={12}>{selected.tokenId}</Mono>
              </KV>
              <KV label="ip">
                <Mono size={12}>{selected.ip}</Mono>
              </KV>
              <KV label="request_bytes">
                <Mono size={12}>{fmtInt(selected.requestBytes)}</Mono>
              </KV>
              <KV label="response_bytes">
                <Mono size={12}>{fmtInt(selected.responseBytes)}</Mono>
              </KV>

              <div
                style={{
                  fontSize: 12,
                  color: 'var(--cds-text-helper)',
                  marginTop: 14,
                  marginBottom: 6,
                }}
              >
                response body
              </div>
              <pre
                className="sc-mono"
                style={{
                  margin: 0,
                  background: 'var(--cds-background)',
                  border: '1px solid var(--cds-border-subtle)',
                  padding: 12,
                  fontSize: 11.5,
                  lineHeight: 1.5,
                  whiteSpace: 'pre-wrap',
                  wordBreak: 'break-word',
                  color: 'var(--cds-text-primary)',
                  overflowX: 'auto',
                }}
              >
                {selected.responseBody ?? '—'}
              </pre>

              {selected.context && (
                <div
                  style={{
                    marginTop: 14,
                    padding: 12,
                    border: '1px solid var(--cds-border-subtle)',
                    background: 'var(--cds-layer-accent)',
                    fontSize: 12,
                    color: 'var(--cds-text-secondary)',
                  }}
                >
                  <div>{selected.context.text}</div>
                  {selected.context.to && (
                    <button
                      type="button"
                      onClick={() => {
                        if (selected.context?.to) {
                          navigate(selected.context.to)
                          toast.success('Opening quota')
                        }
                      }}
                      style={{
                        marginTop: 8,
                        background: 'none',
                        border: 'none',
                        padding: 0,
                        cursor: 'pointer',
                        color: 'var(--cds-link-primary)',
                        font: 'inherit',
                      }}
                    >
                      View quota →
                    </button>
                  )}
                </div>
              )}
            </div>
          </aside>
        )}
      </div>
    </div>
  )
}
