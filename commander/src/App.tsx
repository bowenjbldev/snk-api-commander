import { useEffect } from 'react'
import { RouterProvider } from 'react-router'
import { ThemeProvider } from './providers/ThemeProvider'
import { ToastProvider } from './providers/ToastProvider'
import { TickProvider } from './providers/TickProvider'
import { ActionsProvider } from './providers/ActionsProvider'
import { FullScreenLoading } from './components/FullScreenLoading'
import { Login } from './pages/Login'
import { useAuth } from './store/auth'
import { useStore } from './store/store'
import { router } from './router'

// Authenticated shell: load the registry once, then mount the app (data-loading gate keeps
// the router from flashing fixture data before the real API response lands).
function AuthedApp() {
  const loaded = useStore((s) => s.loaded)
  const loadData = useStore((s) => s.loadData)

  useEffect(() => {
    void loadData()
  }, [loadData])

  return (
    <TickProvider>
      <ActionsProvider>
        {loaded ? <RouterProvider router={router} /> : <FullScreenLoading label="Loading workspace…" />}
      </ActionsProvider>
    </TickProvider>
  )
}

function AuthGate() {
  const status = useAuth((s) => s.status)
  const bootstrap = useAuth((s) => s.bootstrap)

  useEffect(() => {
    void bootstrap()
  }, [bootstrap])

  if (status === 'loading') return <FullScreenLoading label="Signing in…" />
  if (status === 'anon') return <Login />
  return <AuthedApp />
}

function App() {
  return (
    <ThemeProvider>
      <ToastProvider>
        <AuthGate />
      </ToastProvider>
    </ThemeProvider>
  )
}

export default App
