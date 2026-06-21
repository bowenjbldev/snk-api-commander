import {
  Dashboard,
  User,
  Box,
  Receipt,
  ChartBar,
  Catalog,
  Settings,
  type CarbonIconType,
} from '@carbon/icons-react'

export interface NavItem {
  label: string
  path: string
  icon: CarbonIconType
}

// The seven operator sections, in design order. Packages es el hub central (el package provisionado 1:1 + Catalog + Quotas + Subscriptions).
export const NAV_ITEMS: NavItem[] = [
  { label: 'Dashboard', path: '/dashboard', icon: Dashboard },
  { label: 'Clients', path: '/clients', icon: User },
  { label: 'Packages', path: '/packages', icon: Box },
  { label: 'Invoices', path: '/invoices', icon: Receipt },
  { label: 'Usage', path: '/usage', icon: ChartBar },
  { label: 'Request Logs', path: '/request-logs', icon: Catalog },
  { label: 'Settings', path: '/settings', icon: Settings },
]

export const APP_VERSION = 'SnakeCloud Commander v1.14.2'
