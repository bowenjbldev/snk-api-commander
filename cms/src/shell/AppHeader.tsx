import { useEffect, useState } from 'react'
import {
  Header,
  HeaderGlobalAction,
  HeaderGlobalBar,
  Theme,
} from '@carbon/react'
import { Asleep, Light, Search } from '@carbon/icons-react'
import { useTheme } from '../theme/ThemeContext'
import { CommandPalette } from './CommandPalette'

export function AppHeader() {
  const { theme, toggle } = useTheme()
  const [paletteOpen, setPaletteOpen] = useState(false)

  // ⌘K / Ctrl+K opens the command palette.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault()
        setPaletteOpen((o) => !o)
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  return (
    // Header is always dark (g100) in both themes, like IBM Cloud.
    <Theme theme="g100">
      <Header aria-label="SnakeCloud CMS">
        <a
          href="/"
          className="cds--header__name"
          style={{ fontSize: 14, textDecoration: 'none' }}
          onClick={(e) => e.preventDefault()}
        >
          <span style={{ fontWeight: 600 }}>SnakeCloud</span>
          <span style={{ fontWeight: 400, color: '#c6c6c6', marginLeft: 6 }}>
            CMS
          </span>
        </a>

        {/* Global search trigger (expands to the ⌘K command palette). */}
        <div style={{ flex: 1, display: 'flex', justifyContent: 'center', padding: '0 16px' }}>
          <button
            type="button"
            onClick={() => setPaletteOpen(true)}
            aria-label="Search"
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 8,
              width: 420,
              maxWidth: '100%',
              height: 32,
              padding: '0 10px',
              background: 'transparent',
              border: '1px solid #525252',
              color: '#8d8d8d',
              cursor: 'pointer',
              fontSize: 13,
            }}
          >
            <Search size={16} />
            <span style={{ flex: 1, textAlign: 'left' }}>
              Search deployments, clients, invoices…
            </span>
            <span
              className="sc-mono"
              style={{
                fontSize: 10,
                padding: '1px 5px',
                border: '1px solid #525252',
                color: '#c6c6c6',
              }}
            >
              ⌘K
            </span>
          </button>
        </div>

        <HeaderGlobalBar>
          <span
            className="sc-mono"
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              height: 20,
              padding: '0 8px',
              margin: '0 8px',
              fontSize: 10,
              letterSpacing: 1.2,
              border: '1px solid #6f6f6f',
              color: '#c6c6c6',
            }}
          >
            PROD
          </span>
          <span
            style={{
              display: 'inline-flex',
              alignItems: 'center',
              gap: 6,
              marginRight: 8,
              fontSize: 12,
              color: '#c6c6c6',
            }}
          >
            <span
              style={{
                width: 7,
                height: 7,
                borderRadius: '50%',
                background: '#42be65',
                display: 'inline-block',
              }}
            />
            Connected
          </span>
          <HeaderGlobalAction
            aria-label={theme === 'g100' ? 'Switch to light theme' : 'Switch to dark theme'}
            onClick={toggle}
            tooltipAlignment="end"
          >
            {theme === 'g100' ? <Light size={20} /> : <Asleep size={20} />}
          </HeaderGlobalAction>
          <span
            style={{
              width: 1,
              height: 24,
              background: '#393939',
              margin: '0 4px',
              alignSelf: 'center',
            }}
          />
          <span
            aria-label="Operator"
            title="Operator"
            style={{
              width: 28,
              height: 28,
              borderRadius: '50%',
              background: '#393939',
              border: '1px solid #6f6f6f',
              color: '#f4f4f4',
              display: 'inline-flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontSize: 12,
              margin: '0 12px 0 4px',
            }}
          >
            R
          </span>
        </HeaderGlobalBar>
      </Header>

      <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} />
    </Theme>
  )
}
