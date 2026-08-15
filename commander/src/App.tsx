import { BrowserRouter, Navigate, Route, Routes, useLocation } from 'react-router-dom';
import type { ReactNode } from 'react';
import { AppProvider, useApp } from '@app/provider';
import { Shell } from '@app/Shell';

import { LoginPage } from '@features/auth/pages/LoginPage';
import { DashboardPage } from '@features/dashboard/pages/DashboardPage';

import { AccountsListPage } from '@features/accounts/pages/AccountsListPage';
import { AccountDetailPage } from '@features/accounts/pages/AccountDetailPage';
import { AccountFormPage } from '@features/accounts/pages/AccountFormPage';

import { ClientsListPage } from '@features/clients/pages/ClientsListPage';
import { ClientDetailPage } from '@features/clients/pages/ClientDetailPage';
import { ClientFormPage } from '@features/clients/pages/ClientFormPage';

import { ApplicationsListPage } from '@features/applications/pages/ApplicationsListPage';
import { ApplicationDetailPage } from '@features/applications/pages/ApplicationDetailPage';

import { PlansListPage } from '@features/plans/pages/PlansListPage';
import { PlanFormPage } from '@features/plans/pages/PlanFormPage';

import { SubscriptionsListPage } from '@features/subscriptions/pages/SubscriptionsListPage';
import { SubscriptionDetailPage } from '@features/subscriptions/pages/SubscriptionDetailPage';
import { SubscriptionFormPage } from '@features/subscriptions/pages/SubscriptionFormPage';

import { InvoicesListPage } from '@features/invoices/pages/InvoicesListPage';
import { InvoiceDetailPage } from '@features/invoices/pages/InvoiceDetailPage';
import { InvoiceFormPage } from '@features/invoices/pages/InvoiceFormPage';

import { InstancesListPage } from '@features/instances/pages/InstancesListPage';
import { InstanceDetailPage } from '@features/instances/pages/InstanceDetailPage';
import { ProvisioningPage } from '@features/provisioning/pages/ProvisioningPage';

import { UsagePage } from '@features/usage/pages/UsagePage';
import { QuotasListPage } from '@features/quotas/pages/QuotasListPage';
import { QuotaDetailPage } from '@features/quotas/pages/QuotaDetailPage';
import { LogsListPage } from '@features/logs/pages/LogsListPage';
import { ActivityListPage } from '@features/activity/pages/ActivityListPage';
import { ActivityDetailPage } from '@features/activity/pages/ActivityDetailPage';

import { OperatorsListPage } from '@features/operators/pages/OperatorsListPage';
import { OperatorFormPage } from '@features/operators/pages/OperatorFormPage';
import { SettingsPage } from '@features/settings/pages/SettingsPage';

/** Sin token no hay control plane; la ruta de destino se conserva para volver tras entrar. */
function RequireSession({ children }: { children: ReactNode }) {
  const { token } = useApp();
  const location = useLocation();
  if (!token) {
    return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />;
  }
  return <>{children}</>;
}

export function App() {
  return (
    <AppProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route
            element={
              <RequireSession>
                <Shell />
              </RequireSession>
            }
          >
            <Route index element={<DashboardPage />} />

            <Route path="accounts" element={<AccountsListPage />} />
            <Route path="accounts/new" element={<AccountFormPage />} />
            <Route path="accounts/:id/edit" element={<AccountFormPage />} />
            <Route path="accounts/:id" element={<AccountDetailPage />} />
            <Route path="accounts/:id/:tab" element={<AccountDetailPage />} />

            <Route path="clients" element={<ClientsListPage />} />
            <Route path="clients/new" element={<ClientFormPage />} />
            <Route path="clients/:id/edit" element={<ClientFormPage />} />
            <Route path="clients/:id" element={<ClientDetailPage />} />
            <Route path="clients/:id/:tab" element={<ClientDetailPage />} />

            <Route path="applications" element={<ApplicationsListPage />} />
            <Route path="applications/:id" element={<ApplicationDetailPage />} />
            <Route path="applications/:id/:tab" element={<ApplicationDetailPage />} />

            <Route path="plans" element={<PlansListPage />} />
            <Route path="plans/new" element={<PlanFormPage />} />
            <Route path="plans/:id/edit" element={<PlanFormPage />} />

            <Route path="subscriptions" element={<SubscriptionsListPage />} />
            <Route path="subscriptions/new" element={<SubscriptionFormPage />} />
            <Route path="subscriptions/:id" element={<SubscriptionDetailPage />} />
            <Route path="subscriptions/:id/:tab" element={<SubscriptionDetailPage />} />

            <Route path="invoices" element={<InvoicesListPage />} />
            <Route path="invoices/new" element={<InvoiceFormPage />} />
            <Route path="invoices/:id" element={<InvoiceDetailPage />} />

            <Route path="provisioning/new" element={<ProvisioningPage />} />

            <Route path="instances" element={<InstancesListPage />} />
            <Route path="instances/:id" element={<InstanceDetailPage />} />
            <Route path="instances/:id/:tab" element={<InstanceDetailPage />} />

            <Route path="usage" element={<UsagePage />} />
            <Route path="quotas" element={<QuotasListPage />} />
            <Route path="quotas/:id" element={<QuotaDetailPage />} />
            <Route path="logs" element={<LogsListPage />} />
            <Route path="activity" element={<ActivityListPage />} />
            <Route path="activity/:id" element={<ActivityDetailPage />} />

            <Route path="operators" element={<OperatorsListPage />} />
            <Route path="operators/new" element={<OperatorFormPage />} />
            <Route path="operators/:id/edit" element={<OperatorFormPage />} />
            <Route path="settings" element={<SettingsPage />} />

            <Route path="*" element={<Navigate to="/" replace />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </AppProvider>
  );
}
