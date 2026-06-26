import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate } from 'react-router'
import { HeaderGlobalAction, Theme } from '@carbon/react'
import { Notification, Settings, ErrorFilled, WarningAltFilled } from '@carbon/icons-react'
import { useAttentionItems, type AttentionItem, type Tone } from '../lib/attention'
import { useAppTheme } from '../providers/ThemeProvider'

// Header notifications — the Carbon notification-pattern "notification panel": a dropdown anchored
// under the bell (NOT a full-height side panel), grouped by source, with stacked item cards (kicker
// + title + description + a status icon and a coloured left accent). The bell carries a live unread
// count badge; the footer links to notification preferences (Settings). The panel is a content-plane
// surface, so it follows the APP theme (light in g10, dark in g100) rather than the always-dark
// header chrome it hangs off. Data is the operator triage feed (quota overages, grace windows,
// overdue invoices) from useAttentionItems().

const STATUS_ICON: Record<Tone, typeof ErrorFilled | null> = {
  red: ErrorFilled,
  amber: WarningAltFilled,
  blue: null,
  green: null,
}

export function NotificationsBell() {
  const items = useAttentionItems()
  const navigate = useNavigate()
  const { theme } = useAppTheme()
  const [open, setOpen] = useState(false)
  // Anchor the dropdown to the bell: measure the button and pin the panel's top to its bottom edge
  // and its right to the bell's right edge (position: fixed, so it escapes the header's box).
  const [pos, setPos] = useState<{ top: number; right: number }>({ top: 48, right: 8 })
  const wrapRef = useRef<HTMLDivElement>(null)
  const bellRef = useRef<HTMLButtonElement>(null)
  const count = items.length

  useLayoutEffect(() => {
    if (!open) return
    const measure = () => {
      const r = bellRef.current?.getBoundingClientRect()
      if (r) setPos({ top: Math.round(r.bottom), right: Math.max(8, Math.round(window.innerWidth - r.right)) })
    }
    measure()
    window.addEventListener('resize', measure)
    return () => window.removeEventListener('resize', measure)
  }, [open])

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => {
      const t = e.target as HTMLElement
      // The dropdown is portaled to <body> (outside wrapRef), so also treat clicks inside it as "in".
      if (wrapRef.current?.contains(t) || t.closest?.('.scns-notif')) return
      setOpen(false)
    }
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false)
    }
    document.addEventListener('mousedown', onDown)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('mousedown', onDown)
      document.removeEventListener('keydown', onKey)
    }
  }, [open])

  const go = (to: string) => {
    setOpen(false)
    navigate(to)
  }

  // Group the (urgency-ordered) items by source, preserving first-seen group order.
  const groups: { label: string; items: AttentionItem[] }[] = []
  for (const it of items) {
    let g = groups.find((x) => x.label === it.group)
    if (!g) {
      g = { label: it.group, items: [] }
      groups.push(g)
    }
    g.items.push(it)
  }

  return (
    <div ref={wrapRef} style={{ display: 'contents' }}>
      <HeaderGlobalAction
        ref={bellRef}
        aria-label="Notifications"
        isActive={open}
        onClick={() => setOpen((o) => !o)}
        tooltipAlignment="end"
        tooltipHighContrast={theme === 'g100'}
      >
        <span style={{ position: 'relative', display: 'inline-flex' }}>
          <Notification size={18} />
          {count > 0 && (
            <span aria-hidden="true" className="scns-notif__badge">
              {count > 9 ? '9+' : count}
            </span>
          )}
        </span>
      </HeaderGlobalAction>

      {open && createPortal(
        <Theme
          theme={theme}
          className="scns-notif"
          role="dialog"
          aria-label="Notifications"
          style={{ top: pos.top, right: pos.right }}
        >
          <div className="scns-notif__head">
            <span className="scns-notif__head-title">Notifications</span>
            <span className="scns-notif__head-count">{count === 0 ? 'all clear' : `${count} item${count === 1 ? '' : 's'}`}</span>
          </div>

          <div className="scns-notif__body">
            {count === 0 ? (
              <div className="scns-notif__empty">No issues detected</div>
            ) : (
              groups.map((group) => (
                <section key={group.label}>
                  <div className="scns-notif__group">
                    {group.label} <span className="scns-notif__group-count">({group.items.length})</span>
                  </div>
                  {group.items.map((a) => {
                    const Icon = STATUS_ICON[a.tone]
                    return (
                      <button
                        key={a.id}
                        type="button"
                        onClick={() => go(a.to)}
                        className={`scns-notif__item scns-notif__item--${a.tone}`}
                      >
                        <div className="scns-notif__item-body">
                          <span className="scns-notif__kicker">{a.label}</span>
                          <span className="scns-mono scns-notif__title">{a.entity}</span>
                          <span className="scns-notif__desc">{a.desc}</span>
                        </div>
                        <div className="scns-notif__aside">
                          {Icon && <Icon size={16} className={`scns-notif__status scns-notif__status--${a.tone}`} />}
                          <span className="scns-mono scns-notif__metric">{a.metric}</span>
                        </div>
                      </button>
                    )
                  })}
                </section>
              ))
            )}
          </div>

          <button type="button" className="scns-notif__foot" onClick={() => go('/settings')}>
            <span>Notification settings</span>
            <Settings size={16} />
          </button>
        </Theme>,
        document.body,
      )}
    </div>
  )
}
