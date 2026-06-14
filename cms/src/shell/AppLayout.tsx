import { Outlet } from 'react-router-dom'
import { AppHeader } from './AppHeader'
import { AppSideNav } from './AppSideNav'

export function AppLayout() {
  return (
    <>
      <AppHeader />
      <AppSideNav />
      <main
        style={{
          marginLeft: 256,
          marginTop: 48,
          minHeight: 'calc(100vh - 48px)',
          background: 'var(--cds-background)',
        }}
      >
        <Outlet />
      </main>
    </>
  )
}
