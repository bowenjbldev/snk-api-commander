import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { ThemeProvider } from './theme/ThemeContext'
import { NowProvider } from './lib/time'
import { ToastProvider } from './notify/ToastProvider'
import { DataStoreProvider } from './store/DataStore'
import { ModalHost } from './components/modals/ModalHost'
import { AppLayout } from './shell/AppLayout'
import { Dashboard } from './screens/Dashboard'
import { DeploymentsList } from './screens/deployments/DeploymentsList'
import { DeploymentDetail } from './screens/deployments/DeploymentDetail'
import { Clients } from './screens/Clients'
import { Invoices } from './screens/Invoices'
import { Quotas } from './screens/Quotas'
import { RequestLogs } from './screens/RequestLogs'
import { Subscriptions } from './screens/Subscriptions'
import { Usage } from './screens/Usage'
import { Settings } from './screens/Settings'
import { NotFound } from './screens/NotFound'

export function App() {
  return (
    <ThemeProvider>
      <NowProvider>
        <ToastProvider>
          <DataStoreProvider>
            <BrowserRouter>
              <ModalHost>
                <Routes>
                  <Route element={<AppLayout />}>
                    <Route path="/" element={<Dashboard />} />
                    <Route path="/clients" element={<Clients />} />
                    <Route path="/deployments" element={<DeploymentsList />} />
                    <Route path="/deployments/:id" element={<DeploymentDetail />} />
                    <Route path="/subscriptions" element={<Subscriptions />} />
                    <Route path="/invoices" element={<Invoices />} />
                    <Route path="/usage" element={<Usage />} />
                    <Route path="/quotas" element={<Quotas />} />
                    <Route path="/logs" element={<RequestLogs />} />
                    <Route path="/settings" element={<Settings />} />
                    <Route path="*" element={<NotFound />} />
                  </Route>
                </Routes>
              </ModalHost>
            </BrowserRouter>
          </DataStoreProvider>
        </ToastProvider>
      </NowProvider>
    </ThemeProvider>
  )
}
