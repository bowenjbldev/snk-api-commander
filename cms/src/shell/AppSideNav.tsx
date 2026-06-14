import { NavLink, useLocation } from 'react-router-dom'
import { SideNav, SideNavItems, SideNavLink } from '@carbon/react'
import {
  Application,
  Catalog,
  ChartBar,
  Dashboard,
  Meter,
  Receipt,
  Renew,
  Settings,
  User,
} from '@carbon/icons-react'

interface NavItem {
  label: string
  to: string
  icon: React.ComponentType
}

const ITEMS: NavItem[] = [
  { label: 'Dashboard', to: '/', icon: Dashboard },
  { label: 'Clients', to: '/clients', icon: User },
  { label: 'Deployments', to: '/deployments', icon: Application },
  { label: 'Subscriptions', to: '/subscriptions', icon: Renew },
  { label: 'Invoices', to: '/invoices', icon: Receipt },
  { label: 'Usage', to: '/usage', icon: ChartBar },
  { label: 'Quotas', to: '/quotas', icon: Meter },
  { label: 'Request Logs', to: '/logs', icon: Catalog },
  { label: 'Settings', to: '/settings', icon: Settings },
]

export function AppSideNav() {
  const { pathname } = useLocation()
  const isActive = (to: string) =>
    to === '/' ? pathname === '/' : pathname.startsWith(to)

  return (
    <SideNav
      aria-label="Side navigation"
      isFixedNav
      expanded
      isChildOfHeader
    >
      <SideNavItems>
        {ITEMS.map((item) => (
          <SideNavLink
            key={item.to}
            as={NavLink}
            to={item.to}
            renderIcon={item.icon}
            isActive={isActive(item.to)}
            aria-current={isActive(item.to) ? 'page' : undefined}
          >
            {item.label}
          </SideNavLink>
        ))}
      </SideNavItems>
      <div
        style={{
          marginTop: 'auto',
          borderTop: '1px solid var(--cds-border-subtle)',
          padding: '12px 16px',
        }}
        className="sc-mono"
      >
        <span style={{ fontSize: 11, color: 'var(--cds-text-helper)' }}>
          snakecloud-cms v1.14.2
        </span>
      </div>
    </SideNav>
  )
}
