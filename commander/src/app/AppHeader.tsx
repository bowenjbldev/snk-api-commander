import { Header, HeaderName, HeaderGlobalBar, HeaderGlobalAction, HeaderMenuButton, Theme } from '@carbon/react'
import { Search, Light, Asleep, Logout } from '@carbon/icons-react'
import { Link, useNavigate } from 'react-router'
import { useAppTheme } from '../providers/ThemeProvider'
import { useAuth } from '../store/auth'
import { NotificationsBell } from './NotificationsBell'
import { ConnectionStatus } from './ConnectionStatus'

interface AppHeaderProps {
  onOpenSearch?: () => void
  showMenu?: boolean
  menuActive?: boolean
  onMenuToggle?: () => void
}

// Deployment environment badge. Derived from Vite's build mode so a dev server
// shows DEVELOPMENT and a production build shows PRODUCTION; override with VITE_APP_ENV.
const ENV_RAW =
  (import.meta.env.VITE_APP_ENV as string | undefined)?.toUpperCase() ??
  (import.meta.env.PROD ? 'PROD' : 'DEV')
// PROD stays neutral; anything else (DEV/STAGING) gets an amber warning tint.
const ENV_IS_PROD = ENV_RAW === 'PROD' || ENV_RAW === 'PRODUCTION'
// Spell the environment out in full; unknown values (e.g. STAGING) pass through.
const ENV_LABEL = ENV_RAW === 'PROD' ? 'PRODUCTION' : ENV_RAW === 'DEV' ? 'DEVELOPMENT' : ENV_RAW

// Top bar. Pure Carbon UI Shell: HeaderName + HeaderGlobalBar of HeaderGlobalActions
// (search, notifications, theme, sign out, account) — every icon carries its own embedded
// tooltip. Always g100 (dark) in BOTH themes (IBM Cloud pattern) via a nested <Theme>, so every
// colour reads from Carbon tokens (no hard-coded hex).
export function AppHeader({ onOpenSearch, showMenu, menuActive, onMenuToggle }: AppHeaderProps) {
  const { theme, toggle } = useAppTheme()
  const navigate = useNavigate()
  const user = useAuth((s) => s.user)
  const logout = useAuth((s) => s.logout)
  const initial = (user?.name ?? 'U').trim().charAt(0).toUpperCase() || 'U'
  // The header is a fixed g100 zone, so its tooltips default to high-contrast (light surface) in
  // both app themes — which reads pale when they drop into a light content plane. Track the APP
  // theme via HeaderGlobalAction's `tooltipHighContrast`: dark app → light tooltip, light app →
  // dark tooltip ($layer of the g100 zone). Matches the content tooltips elsewhere in the app.
  const tooltipHighContrast = theme === 'g100'

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
        {/* Wordmark — canonical Carbon HeaderName routed through react-router (navigates
            home, no dead link). Brand treatment (bold "SnakeCloud" + lighter "Commander")
            via the children spans; the prefix slot is left empty. When the menu button is
            hidden, the name is pinned to the SideNav width (16rem) so it lines up with the nav. */}
        <HeaderName
          as={Link}
          to="/"
          prefix=""
          className={`scns-header__name${showMenu ? '' : ' scns-header__name--pinned'}`}
        >
          <span className="scns-header__brand">SnakeCloud</span>
          <span className="scns-header__suffix">Commander</span>
        </HeaderName>

        <HeaderGlobalBar>
          {/* Status info reads as plain visible text — no tooltips on already-visible content
              (Carbon usage guidance). */}
          <div className="scns-header__cluster">
            <span
              className={`scns-mono scns-header__env scns-header__env--${ENV_IS_PROD ? 'prod' : 'nonprod'}`}
            >
              {ENV_LABEL}
            </span>
            <ConnectionStatus />
          </div>
          <span className="scns-header__sep" />
          {/* Search is a canonical icon action that opens the ⌘K command palette. */}
          <HeaderGlobalAction
            aria-label="Search (⌘K)"
            onClick={onOpenSearch}
            tooltipAlignment="end"
            tooltipHighContrast={tooltipHighContrast}
          >
            <Search size={18} />
          </HeaderGlobalAction>
          <NotificationsBell />
          <HeaderGlobalAction
            aria-label={theme === 'g100' ? 'Switch to light theme' : 'Switch to dark theme'}
            onClick={toggle}
            tooltipAlignment="end"
            tooltipHighContrast={tooltipHighContrast}
          >
            {theme === 'g100' ? <Light size={18} /> : <Asleep size={18} />}
          </HeaderGlobalAction>
          <HeaderGlobalAction
            aria-label="Sign out"
            onClick={() => void logout()}
            tooltipAlignment="end"
            tooltipHighContrast={tooltipHighContrast}
          >
            <Logout size={18} />
          </HeaderGlobalAction>
          {/* Account: the avatar is a button that links to operator Settings. */}
          <HeaderGlobalAction
            aria-label="Account settings"
            onClick={() => navigate('/settings')}
            tooltipAlignment="end"
            tooltipHighContrast={tooltipHighContrast}
          >
            <span className="scns-header__avatar" aria-hidden="true">
              {initial}
            </span>
          </HeaderGlobalAction>
        </HeaderGlobalBar>
      </Header>
    </Theme>
  )
}
