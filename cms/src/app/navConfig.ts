import {
  Dashboard,
  User,
  Deploy,
  Renew,
  Receipt,
  ChartBar,
  Meter,
  Catalog,
  Settings,
  type CarbonIconType,
} from '@carbon/icons-react'

export interface NavItem {
  label: string
  path: string
  icon: CarbonIconType
}

// The nine operator sections, in design order.
export const NAV_ITEMS: NavItem[] = [
  { label: 'Dashboard', path: '/dashboard', icon: Dashboard },
  { label: 'Clients', path: '/clients', icon: User },
  { label: 'Deployments', path: '/deployments', icon: Deploy },
  { label: 'Subscriptions', path: '/subscriptions', icon: Renew },
  { label: 'Invoices', path: '/invoices', icon: Receipt },
  { label: 'Usage', path: '/usage', icon: ChartBar },
  { label: 'Quotas', path: '/quotas', icon: Meter },
  { label: 'Request Logs', path: '/request-logs', icon: Catalog },
  { label: 'Settings', path: '/settings', icon: Settings },
]

export const APP_VERSION = 'snakecloud-cms v1.14.2'
