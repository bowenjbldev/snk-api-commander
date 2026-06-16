import { lazy } from 'react'
import { createBrowserRouter, Navigate } from 'react-router'
import { AppShell } from './app/AppShell'
import { Dashboard } from './pages/Dashboard'

// Landing (Dashboard) is eager; every other route is code-split and loaded on demand
// (Suspense fallback lives in AppShell). Named exports → default for React.lazy.
const Clients = lazy(() => import('./pages/Clients').then((m) => ({ default: m.Clients })))
const Deployments = lazy(() => import('./pages/Deployments').then((m) => ({ default: m.Deployments })))
const Subscriptions = lazy(() => import('./pages/Subscriptions').then((m) => ({ default: m.Subscriptions })))
const Invoices = lazy(() => import('./pages/Invoices').then((m) => ({ default: m.Invoices })))
const Usage = lazy(() => import('./pages/Usage').then((m) => ({ default: m.Usage })))
const Quotas = lazy(() => import('./pages/Quotas').then((m) => ({ default: m.Quotas })))
const Settings = lazy(() => import('./pages/Settings').then((m) => ({ default: m.Settings })))
const NotFound = lazy(() => import('./pages/NotFound').then((m) => ({ default: m.NotFound })))
const DeploymentDetail = lazy(() =>
  import('./pages/DeploymentDetail/DeploymentDetail').then((m) => ({ default: m.DeploymentDetail })),
)
const OverviewTab = lazy(() =>
  import('./pages/DeploymentDetail/OverviewTab').then((m) => ({ default: m.OverviewTab })),
)
const UsageTab = lazy(() => import('./pages/DeploymentDetail/UsageTab').then((m) => ({ default: m.UsageTab })))
const SettingsTab = lazy(() =>
  import('./pages/DeploymentDetail/SettingsTab').then((m) => ({ default: m.SettingsTab })),
)

export const router = createBrowserRouter([
  {
    element: <AppShell />,
    children: [
      { index: true, element: <Navigate to="/dashboard" replace /> },
      { path: 'dashboard', element: <Dashboard /> },
      { path: 'clients', element: <Clients /> },
      { path: 'deployments', element: <Deployments /> },
      {
        path: 'deployments/:id',
        element: <DeploymentDetail />,
        children: [
          { index: true, element: <Navigate to="overview" replace /> },
          { path: 'overview', element: <OverviewTab /> },
          { path: 'usage', element: <UsageTab /> },
          { path: 'settings', element: <SettingsTab /> },
        ],
      },
      { path: 'subscriptions', element: <Subscriptions /> },
      { path: 'invoices', element: <Invoices /> },
      { path: 'usage', element: <Usage /> },
      { path: 'quotas', element: <Quotas /> },
      { path: 'settings', element: <Settings /> },
      { path: '*', element: <NotFound /> },
    ],
  },
])
