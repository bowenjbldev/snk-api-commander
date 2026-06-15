import { Breadcrumb, BreadcrumbItem } from '@carbon/react'
import { Link, useLocation } from 'react-router'
import { useStore } from '../store/store'

// Breadcrumbs appear under the header on detail pages only.
export function Breadcrumbs() {
  const { pathname } = useLocation()
  const deployments = useStore((s) => s.deployments)
  const match = pathname.match(/^\/deployments\/([^/]+)/)
  if (!match) return null

  const id = decodeURIComponent(match[1])
  const name = deployments.find((d) => d.id === id)?.name ?? id

  return (
    <Breadcrumb noTrailingSlash style={{ marginBottom: 16 }}>
      <BreadcrumbItem>
        <Link to="/deployments">Deployments</Link>
      </BreadcrumbItem>
      <BreadcrumbItem isCurrentPage className="scns-mono">
        {name}
      </BreadcrumbItem>
    </Breadcrumb>
  )
}
