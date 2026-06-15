import { SideNav, SideNavItems, SideNavLink } from '@carbon/react'
import { Link, useLocation } from 'react-router'
import { NAV_ITEMS, APP_VERSION } from './navConfig'

interface Props {
  isWide: boolean
  expanded: boolean
  onNavigate: () => void
  onClose: () => void
}

export function AppSideNav({ isWide, expanded, onNavigate, onClose }: Props) {
  const { pathname } = useLocation()

  return (
    <SideNav
      isFixedNav={isWide}
      expanded={expanded}
      isPersistent={isWide}
      isChildOfHeader={false}
      aria-label="Primary navigation"
      className="scns-sidenav"
      onOverlayClick={onClose}
      onSideNavBlur={isWide ? undefined : onClose}
      style={{ display: 'flex', flexDirection: 'column' }}
    >
      <SideNavItems>
        {NAV_ITEMS.map((item) => {
          const isActive = pathname === item.path || pathname.startsWith(item.path + '/')
          return (
            <SideNavLink
              key={item.path}
              as={Link}
              to={item.path}
              renderIcon={item.icon}
              isActive={isActive}
              aria-current={isActive ? 'page' : undefined}
              onClick={onNavigate}
            >
              {item.label}
            </SideNavLink>
          )
        })}
      </SideNavItems>
      <div style={{ flex: 1 }} />
      <div
        className="scns-mono"
        style={{
          borderTop: '1px solid var(--cds-border-subtle)',
          padding: '12px 16px',
          fontSize: 11,
          color: 'var(--cds-text-helper)',
        }}
      >
        {APP_VERSION}
      </div>
    </SideNav>
  )
}
