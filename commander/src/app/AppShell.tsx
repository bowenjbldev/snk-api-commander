import { Suspense, useEffect, useState } from 'react'
import { Outlet } from 'react-router'
import { SkeletonText } from '@carbon/react'
import { AppHeader } from './AppHeader'
import { AppSideNav } from './AppSideNav'
import { CommandPalette } from '../components/CommandPalette'

const WIDE = '(min-width: 1056px)'

// UI shell: fixed dark header (48px) + side nav (256px, collapsible below 1056px) +
// offset content. ⌘K opens the command palette.
export function AppShell() {
  const [paletteOpen, setPaletteOpen] = useState(false)
  const [navExpanded, setNavExpanded] = useState(false)
  const [isWide, setIsWide] = useState(() => window.matchMedia(WIDE).matches)

  useEffect(() => {
    const mq = window.matchMedia(WIDE)
    const onChange = () => setIsWide(mq.matches)
    mq.addEventListener('change', onChange)
    return () => mq.removeEventListener('change', onChange)
  }, [])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        setPaletteOpen((o) => !o)
      }
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [])

  return (
    <>
      <a href="#main-content" className="scns-skip">
        Skip to content
      </a>
      <AppHeader
        onOpenSearch={() => setPaletteOpen(true)}
        showMenu={!isWide}
        menuActive={navExpanded}
        onMenuToggle={() => setNavExpanded((v) => !v)}
      />
      <AppSideNav
        isWide={isWide}
        expanded={isWide || navExpanded}
        onNavigate={() => setNavExpanded(false)}
        onClose={() => setNavExpanded(false)}
      />
      <main
        id="main-content"
        tabIndex={-1}
        style={{
          marginLeft: isWide ? 256 : 0,
          marginTop: 48,
          minHeight: 'calc(100vh - 48px)',
          background: 'var(--cds-background)',
          outline: 'none',
        }}
      >
        <Suspense
          fallback={
            <div className="scns-page" aria-busy="true">
              <SkeletonText heading width="220px" />
              <SkeletonText paragraph lineCount={4} width="70%" />
            </div>
          }
        >
          <Outlet />
        </Suspense>
      </main>
      <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
    </>
  )
}
