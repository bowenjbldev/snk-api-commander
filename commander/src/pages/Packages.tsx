import { useMemo } from 'react'
import { Link, useNavigate } from 'react-router'
import { Tag } from '@carbon/react'
import { useStore } from '../store/store'
import { PageHeader } from '../components/PageHeader'
import { DataTableShell, type ShellColumn } from '../components/DataTableShell'
import { useFirstLoad } from '../hooks/useFirstLoad'
import type { RegisteredPackage } from '../store/types'

// Hub package-céntrico: lista los packages de negocio registrados (sync desde disco). Cada fila
// entra a /packages/{name} con sus tabs (Overview, Plans, Deployments, Usage, Logs, Settings).
export function Packages() {
  const packages = useStore((s) => s.packages)
  const navigate = useNavigate()

  const liveCount = useMemo(() => packages.filter((p) => p.has_deployment).length, [packages])

  const columns: ShellColumn<RegisteredPackage>[] = [
    {
      key: 'name',
      header: 'Package',
      sortable: true,
      sortValue: (p) => p.label ?? p.name,
      width: 280,
      render: (p) => (
        <div style={{ lineHeight: 1.3 }}>
          <Link to={`/packages/${p.name}`} className="scns-link" style={{ fontWeight: 600 }}>
            {p.label ?? p.name}
          </Link>
          <div className="scns-mono scns-helper" style={{ fontSize: 11 }}>
            {p.name}
            {p.on_disk === false ? ' · missing on disk' : ''}
          </div>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      sortable: true,
      sortValue: (p) => p.status,
      width: 120,
      render: (p) => (
        <Tag type={p.status === 'active' ? 'green' : 'gray'} size="sm">
          {p.status}
        </Tag>
      ),
    },
    {
      key: 'deployment',
      header: 'Deployment',
      sortable: true,
      sortValue: (p) => (p.has_deployment ? 1 : 0),
      width: 160,
      render: (p) =>
        p.has_deployment ? (
          <Tag type="blue" size="sm">
            live
          </Tag>
        ) : (
          <span className="scns-helper">not deployed</span>
        ),
    },
    {
      key: 'plans',
      header: 'Plans',
      sortable: true,
      sortValue: (p) => p.plan_count ?? 0,
      align: 'right',
      width: 100,
      render: (p) => <span className="scns-num">{p.plan_count ?? 0}</span>,
    },
  ]

  return (
    <div className="scns-page">
      <PageHeader
        title="Packages"
        subtitle={
          <>
            {packages.length} packages · {liveCount} deployed
          </>
        }
      />
      <DataTableShell
        rows={packages}
        columns={columns}
        getId={(p) => p.name}
        searchText={(p) => `${p.name} ${p.label ?? ''}`}
        searchPlaceholder="Search packages"
        initialSort={{ key: 'name', dir: 'ASC' }}
        itemLabel="packages"
        loading={useFirstLoad()}
        onRowClick={(p) => navigate(`/packages/${p.name}`)}
      />
    </div>
  )
}
