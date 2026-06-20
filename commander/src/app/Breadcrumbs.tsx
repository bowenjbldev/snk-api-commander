import { Breadcrumb, BreadcrumbItem } from '@carbon/react'
import { Link, useLocation } from 'react-router'
import { useStore } from '../store/store'

// Breadcrumbs appear under the header on detail pages only.
export function Breadcrumbs() {
  const { pathname } = useLocation()
  const packages = useStore((s) => s.packages)
  const match = pathname.match(/^\/packages\/([^/]+)/)
  if (!match) return null

  const name = decodeURIComponent(match[1])
  const label = packages.find((p) => p.name === name)?.label ?? name

  return (
    <Breadcrumb noTrailingSlash style={{ marginBottom: 16 }}>
      <BreadcrumbItem>
        <Link to="/packages">Packages</Link>
      </BreadcrumbItem>
      <BreadcrumbItem isCurrentPage className="scns-mono">
        {label}
      </BreadcrumbItem>
    </Breadcrumb>
  )
}
