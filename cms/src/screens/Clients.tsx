import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Button, Dropdown } from '@carbon/react'
import { Add } from '@carbon/icons-react'
import {
  PageHeader,
  KV,
  Mono,
  Num,
  ClientStatusTag,
  DeploymentStatusTag,
  DataGrid,
  Drawer,
} from '../components/ui'
import type { GridColumn } from '../components/ui'
import { useStore } from '../store/DataStore'
import { useToast } from '../notify/ToastProvider'
import { useNowMs } from '../lib/time'
import { clients, deploymentsByClient, getClient } from '../data/dataset'
import { monthYear, fmtUSD, daysOverdue } from '../lib/format'
import type { Client } from '../types'

type StatusFilter = 'all' | 'active' | 'inactive'

interface FilterOption {
  id: StatusFilter
  label: string
}

const STATUS_OPTIONS: FilterOption[] = [
  { id: 'all', label: 'All statuses' },
  { id: 'active', label: 'Active' },
  { id: 'inactive', label: 'Inactive' },
]

const SECTION_LABEL_STYLE = {
  textTransform: 'uppercase' as const,
  fontSize: 12,
  letterSpacing: 0.5,
}

export function Clients() {
  const { deployments, invoices } = useStore()
  const navigate = useNavigate()
  const toast = useToast()
  const nowMs = useNowMs()
  const [searchParams] = useSearchParams()

  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all')
  const [selectedClient, setSelectedClient] = useState<Client | null>(null)

  // Focus a client from a deep link (?focus=<clientId>).
  useEffect(() => {
    const focus = searchParams.get('focus')
    if (!focus) return
    const c = getClient(focus)
    if (c) setSelectedClient(c)
  }, [searchParams])

  const filteredRows = useMemo(
    () =>
      statusFilter === 'all'
        ? clients
        : clients.filter((c) => c.status === statusFilter),
    [statusFilter],
  )

  // Derive overdue invoice from the live store for the open client.
  const overdueInvoice = selectedClient
    ? invoices.find(
        (i) => i.clientId === selectedClient.id && i.status === 'overdue',
      )
    : undefined

  const columns: GridColumn<Client>[] = [
    {
      key: 'name',
      header: 'Name',
      sortValue: (c) => c.name,
      render: (c) => (
        <span
          style={{ color: 'var(--cds-link-primary)', cursor: 'pointer' }}
        >
          {c.name}
        </span>
      ),
    },
    {
      key: 'company',
      header: 'Company',
      sortValue: (c) => c.company,
      render: (c) => c.company,
    },
    {
      key: 'email',
      header: 'Email',
      render: (c) => (
        <Mono color="var(--cds-link-primary)" size={12}>
          {c.email}
        </Mono>
      ),
    },
    {
      key: 'deployments',
      header: 'Deployments',
      align: 'right',
      sortValue: (c) =>
        deployments.filter((d) => d.clientId === c.id).length,
      render: (c) => (
        <Num>{deployments.filter((d) => d.clientId === c.id).length}</Num>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      sortValue: (c) => c.status,
      render: (c) => <ClientStatusTag status={c.status} size="sm" />,
    },
    {
      key: 'created',
      header: 'Created',
      sortValue: (c) => c.since,
      render: (c) => <Mono>{monthYear(c.since)}</Mono>,
    },
  ]

  const drawerDeployments = selectedClient
    ? deploymentsByClient(selectedClient.id)
    : []

  return (
    <div className="sc-page">
      <PageHeader
        title="Clients"
        subtitle={`${clients.length} clients`}
        right={
          <Button
            kind="primary"
            renderIcon={Add}
            onClick={() =>
              toast.success(
                'Create client',
                'Opens the create form (not yet built).',
              )
            }
          >
            Create client
          </Button>
        }
      />

      <DataGrid
        rows={filteredRows}
        getRowId={(c) => c.id}
        columns={columns}
        size="lg"
        initialSort={{ key: 'name', dir: 'ASC' }}
        searchText={(c) => `${c.name} ${c.company} ${c.email}`}
        searchPlaceholder="Search by name, company or email…"
        onRowClick={(c) => setSelectedClient(c)}
        selectedRowId={selectedClient?.id ?? null}
        toolbarContent={
          <Dropdown<FilterOption>
            id="clients-status-filter"
            size="lg"
            type="inline"
            label="All statuses"
            titleText="Status"
            hideLabel
            items={STATUS_OPTIONS}
            itemToString={(item) => item?.label ?? ''}
            selectedItem={
              STATUS_OPTIONS.find((o) => o.id === statusFilter) ??
              STATUS_OPTIONS[0]
            }
            onChange={({ selectedItem }) =>
              setStatusFilter(selectedItem?.id ?? 'all')
            }
          />
        }
      />

      <Drawer
        open={!!selectedClient}
        onClose={() => setSelectedClient(null)}
        title={
          selectedClient && (
            <div style={{ minWidth: 0 }}>
              <div style={{ fontSize: 18, fontWeight: 600 }}>
                {selectedClient.name}
              </div>
              <div
                style={{
                  marginTop: 4,
                  fontSize: 12,
                  color: 'var(--cds-text-helper)',
                }}
              >
                client since {monthYear(selectedClient.since)} ·{' '}
                {selectedClient.company}
              </div>
            </div>
          )
        }
        headerExtra={
          selectedClient && (
            <ClientStatusTag status={selectedClient.status} size="sm" />
          )
        }
        footer={
          selectedClient && (
            <>
              <Button
                kind="secondary"
                style={{ flex: 1, maxWidth: 'none' }}
                onClick={() => toast.success('Edit client')}
              >
                Edit client
              </Button>
              <Button
                kind="primary"
                style={{ flex: 1, maxWidth: 'none' }}
                onClick={() => toast.success('Open full record')}
              >
                Open full record
              </Button>
            </>
          )
        }
      >
        {selectedClient && (
          <div
            style={{
              padding: 16,
              display: 'flex',
              flexDirection: 'column',
              gap: 24,
            }}
          >
            <div>
              <div className="sc-section-label" style={SECTION_LABEL_STYLE}>
                Contact
              </div>
              <div style={{ marginTop: 4 }}>
                <KV label="Email">
                  <Mono color="var(--cds-link-primary)">
                    {selectedClient.email}
                  </Mono>
                </KV>
                <KV label="Phone">
                  <Mono>{selectedClient.phone}</Mono>
                </KV>
                <KV label="Notes" align="left">
                  {selectedClient.notes}
                </KV>
              </div>
            </div>

            {overdueInvoice && (
              <div
                style={{
                  borderLeft: '3px solid var(--sc-support-error)',
                  background: 'var(--cds-layer-accent)',
                  padding: '10px 12px',
                  fontSize: 13,
                  lineHeight: 1.5,
                }}
              >
                <Mono color="var(--cds-link-primary)">
                  {overdueInvoice.number}
                </Mono>{' '}
                · <Mono>{fmtUSD(overdueInvoice.amount, { cents: true })}</Mono>{' '}
                is {daysOverdue(overdueInvoice.dueAt, nowMs)} days overdue —{' '}
                <Mono color="var(--cds-link-primary)">
                  {overdueInvoice.deploymentId}
                </Mono>{' '}
                auto-suspended.
              </div>
            )}

            <div>
              <div className="sc-section-label" style={SECTION_LABEL_STYLE}>
                Deployments · {drawerDeployments.length}
              </div>
              <div style={{ marginTop: 8 }}>
                {drawerDeployments.length === 0 ? (
                  <div
                    style={{
                      fontSize: 13,
                      color: 'var(--cds-text-helper)',
                      padding: '10px 0',
                    }}
                  >
                    No deployments for this client.
                  </div>
                ) : (
                  drawerDeployments.map((d, i) => (
                    <div
                      key={d.id}
                      style={{
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'space-between',
                        gap: 12,
                        padding: '10px 0',
                        borderTop:
                          i === 0
                            ? undefined
                            : '1px solid var(--cds-border-subtle)',
                      }}
                    >
                      <div style={{ minWidth: 0 }}>
                        <Mono
                          color="var(--cds-link-primary)"
                          title={d.name}
                        >
                          <span
                            style={{ cursor: 'pointer' }}
                            onClick={() => navigate(`/deployments/${d.id}`)}
                          >
                            {d.name}
                          </span>
                        </Mono>
                        <div
                          style={{
                            fontSize: 12,
                            color: 'var(--cds-text-helper)',
                            marginTop: 2,
                          }}
                        >
                          {d.packageName}
                        </div>
                      </div>
                      <DeploymentStatusTag state={d.state} size="sm" />
                    </div>
                  ))
                )}
              </div>
            </div>

            <div>
              <Button
                kind="tertiary"
                renderIcon={Add}
                size="md"
                onClick={() =>
                  navigate(`/deployments?createFor=${selectedClient.id}`)
                }
              >
                New deployment for this client
              </Button>
            </div>
          </div>
        )}
      </Drawer>
    </div>
  )
}
