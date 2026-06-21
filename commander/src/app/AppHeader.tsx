import { Header, HeaderGlobalBar, HeaderGlobalAction, HeaderMenuButton, Theme } from '@carbon/react'
import { Search, Light, Asleep, Logout } from '@carbon/icons-react'
import { useAppTheme } from '../providers/ThemeProvider'
import { useAuth } from '../store/auth'

interface AppHeaderProps {
  onOpenSearch?: () => void
  showMenu?: boolean
  menuActive?: boolean
  onMenuToggle?: () => void
}

// Top bar. Always g100 (dark) in BOTH themes — IBM Cloud pattern — via a nested
// <Theme theme="g100"> zone.
export function AppHeader({ onOpenSearch, showMenu, menuActive, onMenuToggle }: AppHeaderProps) {
  const { theme, toggle } = useAppTheme()
  const user = useAuth((s) => s.user)
  const logout = useAuth((s) => s.logout)
  const initial = (user?.name ?? 'U').trim().charAt(0).toUpperCase() || 'U'

  return (
    <Theme theme="g100">
      <Header aria-label="SnakeCloud Commander" className="scns-header">
        {showMenu && (
          <HeaderMenuButton
            aria-label={menuActive ? 'Close navigation' : 'Open navigation'}
            isActive={menuActive}
            isCollapsible
            onClick={onMenuToggle}
          />
        )}
        {/* Wordmark */}
        <a
          href="/"
          onClick={(e) => e.preventDefault()}
          style={{
            display: 'flex',
            alignItems: 'baseline',
            gap: 6,
            padding: '0 24px 0 16px',
            fontSize: 14,
            textDecoration: 'none',
            color: '#f4f4f4',
            whiteSpace: 'nowrap',
          }}
        >
          <span style={{ fontWeight: 600 }}>SnakeCloud</span>
          <span style={{ fontWeight: 400, color: '#c6c6c6' }}>Commander</span>
        </a>

        <span style={{ width: 1, height: 24, background: '#393939' }} />

        {/* Global search — opens the ⌘K command palette */}
        <button
          type="button"
          onClick={onOpenSearch}
          aria-label="Search packages, clients, invoices"
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            background: '#262626',
            height: 32,
            width: 420,
            marginLeft: 24,
            padding: '0 8px 0 12px',
            border: 'none',
            cursor: 'pointer',
            color: 'inherit',
          }}
        >
          <Search size={16} style={{ color: '#8d8d8d', flex: 'none' }} />
          <span style={{ fontSize: 13, color: '#6f6f6f', flex: 1, textAlign: 'left' }}>
            Search packages, clients, invoices…
          </span>
          <span
            className="scns-mono"
            style={{
              fontSize: 10,
              color: '#8d8d8d',
              border: '1px solid #525252',
              padding: '2px 5px',
            }}
          >
            ⌘K
          </span>
        </button>

        <HeaderGlobalBar>
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 16,
              paddingRight: 8,
              paddingLeft: 16,
            }}
          >
            <span
              className="scns-mono"
              style={{
                fontSize: 10,
                letterSpacing: '1.2px',
                color: '#f4f4f4',
                border: '1px solid #6f6f6f',
                padding: '3px 8px',
              }}
            >
              PROD
            </span>
            <span
              style={{ display: 'flex', alignItems: 'center', gap: 7, fontSize: 12, color: '#c6c6c6' }}
            >
              <span
                style={{ width: 7, height: 7, borderRadius: '50%', background: '#42be65' }}
                aria-hidden="true"
              />
              Connected
            </span>
          </div>
          <HeaderGlobalAction
            aria-label={theme === 'g100' ? 'Switch to light theme' : 'Switch to dark theme'}
            onClick={toggle}
            tooltipAlignment="end"
          >
            {theme === 'g100' ? <Light size={18} /> : <Asleep size={18} />}
          </HeaderGlobalAction>
          <HeaderGlobalAction aria-label="Sign out" onClick={() => void logout()} tooltipAlignment="end">
            <Logout size={18} />
          </HeaderGlobalAction>
          <span style={{ width: 1, height: 24, background: '#393939', alignSelf: 'center' }} />
          <div
            title={user?.name ?? undefined}
            aria-label={user?.name ? `Signed in as ${user.name}` : undefined}
            style={{
              width: 28,
              height: 28,
              flex: 'none',
              borderRadius: '50%',
              background: '#393939',
              border: '1px solid #6f6f6f',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              fontSize: 12,
              fontWeight: 600,
              color: '#f4f4f4',
              margin: '0 16px',
              alignSelf: 'center',
            }}
          >
            {initial}
          </div>
        </HeaderGlobalBar>
      </Header>
    </Theme>
  )
}
