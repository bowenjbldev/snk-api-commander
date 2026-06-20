import { lazy } from 'react'
import { createBrowserRouter, Navigate } from 'react-router'
import { AppShell } from './app/AppShell'
import { Dashboard } from './pages/Dashboard'

// Landing (Dashboard) is eager; every other route is code-split and loaded on demand
// (Suspense fallback lives in AppShell). Named exports → default for React.lazy.
const Clients = lazy(() => import('./pages/Clients').then((m) => ({ default: m.Clients })))
const Packages = lazy(() => import('./pages/Packages').then((m) => ({ default: m.Packages })))
const Subscriptions = lazy(() => import('./pages/Subscriptions').then((m) => ({ default: m.Subscriptions })))
const Invoices = lazy(() => import('./pages/Invoices').then((m) => ({ default: m.Invoices })))
const Usage = lazy(() => import('./pages/Usage').then((m) => ({ default: m.Usage })))
const Quotas = lazy(() => import('./pages/Quotas').then((m) => ({ default: m.Quotas })))
const RequestLogs = lazy(() => import('./pages/RequestLogs').then((m) => ({ default: m.RequestLogs })))
const Settings = lazy(() => import('./pages/Settings').then((m) => ({ default: m.Settings })))
const NotFound = lazy(() => import('./pages/NotFound').then((m) => ({ default: m.NotFound })))

// Package-centric hub: /packages/:name with tabs.
const PackageDetail = lazy(() =>
  import('./pages/PackageDetail/PackageDetail').then((m) => ({ default: m.PackageDetail })),
)
const OverviewTab = lazy(() =>
  import('./pages/PackageDetail/OverviewTab').then((m) => ({ default: m.OverviewTab })),
)
const PlansTab = lazy(() => import('./pages/PackageDetail/PlansTab').then((m) => ({ default: m.PlansTab })))
const DeploymentsTab = lazy(() =>
  import('./pages/PackageDetail/DeploymentsTab').then((m) => ({ default: m.DeploymentsTab })),
)
const UsageTab = lazy(() => import('./pages/PackageDetail/UsageTab').then((m) => ({ default: m.UsageTab })))
const LogsTab = lazy(() => import('./pages/PackageDetail/LogsTab').then((m) => ({ default: m.LogsTab })))
const SettingsTab = lazy(() =>
  import('./pages/PackageDetail/SettingsTab').then((m) => ({ default: m.SettingsTab })),
)

export const router = createBrowserRouter([
  {
    element: <AppShell />,
    children: [
      { index: true, element: <Navigate to="/dashboard" replace /> },
      { path: 'dashboard', element: <Dashboard /> },
      { path: 'clients', element: <Clients /> },
      { path: 'packages', element: <Packages /> },
      {
        path: 'packages/:name',
        element: <PackageDetail />,
        children: [
          { index: true, element: <Navigate to="overview" replace /> },
          { path: 'overview', element: <OverviewTab /> },
          { path: 'plans', element: <PlansTab /> },
          { path: 'deployments', element: <DeploymentsTab /> },
          { path: 'usage', element: <UsageTab /> },
          { path: 'logs', element: <LogsTab /> },
          { path: 'settings', element: <SettingsTab /> },
        ],
      },
      { path: 'subscriptions', element: <Subscriptions /> },
      { path: 'invoices', element: <Invoices /> },
      { path: 'usage', element: <Usage /> },
      { path: 'quotas', element: <Quotas /> },
      { path: 'request-logs', element: <RequestLogs /> },
      { path: 'settings', element: <Settings /> },
      // Back-compat: la IA antigua (Deployments + Catalog) ahora vive bajo Packages.
      { path: 'deployments', element: <Navigate to="/packages" replace /> },
      { path: 'deployments/:name', element: <Navigate to="/packages" replace /> },
      { path: 'catalog', element: <Navigate to="/packages" replace /> },
      { path: '*', element: <NotFound /> },
    ],
  },
])
