import { useMemo, useState } from 'react'
import {
  Button,
  Dropdown,
} from '@carbon/react'
import { Renew } from '@carbon/icons-react'
import { useProvisionedPackages } from '../store/store'
import { PageHeader } from '../components/PageHeader'
import { DataTableShell, type ShellColumn } from '../components/DataTableShell'
import { EmptyState } from '../components/EmptyState'
import { MethodTag } from '../components/tags/MethodTag'
import { useFirstLoad } from '../hooks/useFirstLoad'
import { useLoad } from '../hooks/useLoad'
import { platformLogs } from '../lib/api'
import { formatInt } from '../lib/format'
import { logTime } from '../lib/time'
import { statusColor } from '../lib/statusColor'
import type { LogEntry } from '../store/types'

// Sentinel for the "no package filter" Dropdown option (cross-fleet view).
const ALL_PACKAGES = 'All packages'

// Platform-wide request log: read commander_request_logs in-process (no /control, no proxy). By
// default we tail every package; the package Dropdown is an optional filter (by package name).
export function RequestLogs() {
  const packages = useProvisionedPackages()
  // null == "All packages" (cross-fleet). A non-null name filters to one package.
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [tick, setTick] = useState(0)
  const firstLoad = useFirstLoad()
  const state = useLoad(
    () => platformLogs({ limit: 100, ...(selectedId ? { package: selectedId } : {}) }).then((res) => res.data ?? []),
    [selectedId, tick],
  )

  // El backend filtra logs por package NAME (param `package`), no por subdominio ni id numerico.
  // El dropdown muestra el nombre del package y manda ese mismo nombre como valor.
  const items = useMemo(
    () => [
      { id: '', label: ALL_PACKAGES },
      ...packages.map((p) => ({ id: p.name, label: p.name })),
    ],
    [packages],
  )
  const logs = state.data ?? []

  const columns: ShellColumn<LogEntry>[] = [
    {
      key: 'time',
      header: 'Time',
      width: 140,
      render: (l) => <span className="scns-mono" style={{ color: 'var(--cds-text-helper)', fontSize: 12 }}>{logTime(l.created_at)}</span>,
    },
    // The origin column only earns its width in the cross-fleet view; hide it when filtered.
    ...(selectedId
      ? []
      : [
          {
            key: 'package',
            header: 'Package',
            width: 180,
            render: (l: LogEntry) => <span className="scns-mono" style={{ fontSize: 12 }}>{l.package}</span>,
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
          searchText={(l) => `${l.package} ${l.method} ${l.route} ${l.status} ${l.ip}`}
          searchPlaceholder="Search logs"
          itemLabel="logs"
          loading={state.loading || firstLoad}
          toolbarExtra={
            <>
              <Dropdown
                id="logs-deploy"
                titleText="Package"
                hideLabel
                label="Filter by package"
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
