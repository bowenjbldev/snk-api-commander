import { Breadcrumb, BreadcrumbItem } from '@carbon/react'
import { Link, useLocation } from 'react-router'
import { useStore } from '../store/store'

// Breadcrumbs appear under the header on detail pages only.
export function Breadcrumbs() {
  const { pathname } = useLocation()
  const packages = useStore((s) => s.packages)
  const clients = useStore((s) => s.clients)

  const pkgMatch = pathname.match(/^\/packages\/([^/]+)/)
  if (pkgMatch) {
    const id = Number(decodeURIComponent(pkgMatch[1]))
    const pkg = packages.find((p) => p.id === id)
    const label = pkg?.label ?? pkg?.name ?? `Package ${pkgMatch[1]}`
    return (
      <Breadcrumb noTrailingSlash style={{ marginBottom: 16 }}>
        <BreadcrumbItem>
          <Link to="/packages">Packages</Link>
        </BreadcrumbItem>
        <BreadcrumbItem isCurrentPage>{label}</BreadcrumbItem>
      </Breadcrumb>
    )
  }

  const clientMatch = pathname.match(/^\/clients\/([^/]+)/)
  if (clientMatch) {
    const id = Number(decodeURIComponent(clientMatch[1]))
    const label = clients.find((c) => c.id === id)?.name ?? `Client ${clientMatch[1]}`
    return (
      <Breadcrumb noTrailingSlash style={{ marginBottom: 16 }}>
        <BreadcrumbItem>
          <Link to="/clients">Clients</Link>
        </BreadcrumbItem>
        <BreadcrumbItem isCurrentPage>{label}</BreadcrumbItem>
      </Breadcrumb>
    )
  }

  return null
}
