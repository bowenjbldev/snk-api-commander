import { lazy } from 'react'
import { createBrowserRouter, Navigate } from 'react-router'
import { AppShell } from './app/AppShell'
import { Dashboard } from './pages/Dashboard'

// Landing (Dashboard) is eager; every other route is code-split and loaded on demand
// (Suspense fallback lives in AppShell). Named exports → default for React.lazy.
const Clients = lazy(() => import('./pages/Clients').then((m) => ({ default: m.Clients })))
const ClientDetail = lazy(() => import('./pages/ClientDetail').then((m) => ({ default: m.ClientDetail })))
const Packages = lazy(() => import('./pages/Packages').then((m) => ({ default: m.Packages })))
const Invoices = lazy(() => import('./pages/Invoices').then((m) => ({ default: m.Invoices })))
const Usage = lazy(() => import('./pages/Usage').then((m) => ({ default: m.Usage })))
const RequestLogs = lazy(() => import('./pages/RequestLogs').then((m) => ({ default: m.RequestLogs })))
const Settings = lazy(() => import('./pages/Settings').then((m) => ({ default: m.Settings })))
const NotFound = lazy(() => import('./pages/NotFound').then((m) => ({ default: m.NotFound })))

// Package-centric hub: /packages/:id with tabs.
const PackageDetail = lazy(() =>
  import('./pages/PackageDetail/PackageDetail').then((m) => ({ default: m.PackageDetail })),
)
const OverviewTab = lazy(() =>
  import('./pages/PackageDetail/OverviewTab').then((m) => ({ default: m.OverviewTab })),
)
const PlansTab = lazy(() => import('./pages/PackageDetail/PlansTab').then((m) => ({ default: m.PlansTab })))
const UsageTab = lazy(() => import('./pages/PackageDetail/UsageTab').then((m) => ({ default: m.UsageTab })))
const QuotasTab = lazy(() => import('./pages/PackageDetail/QuotasTab').then((m) => ({ default: m.QuotasTab })))
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
      { path: 'clients/:id', element: <ClientDetail /> },
      { path: 'packages', element: <Packages /> },
      {
        path: 'packages/:id',
        element: <PackageDetail />,
        children: [
          { index: true, element: <Navigate to="overview" replace /> },
          { path: 'overview', element: <OverviewTab /> },
          { path: 'plans', element: <PlansTab /> },
          { path: 'quotas', element: <QuotasTab /> },
          { path: 'usage', element: <UsageTab /> },
          { path: 'logs', element: <LogsTab /> },
          { path: 'settings', element: <SettingsTab /> },
        ],
      },
      { path: 'invoices', element: <Invoices /> },
      { path: 'usage', element: <Usage /> },
      { path: 'request-logs', element: <RequestLogs /> },
      { path: 'settings', element: <Settings /> },
      { path: '*', element: <NotFound /> },
    ],
  },
])
