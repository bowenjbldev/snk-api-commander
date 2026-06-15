import { useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate } from 'react-router'
import { Search } from '@carbon/icons-react'
import { useStore } from '../store/store'
import { useFocusTrap } from '../hooks/useFocusTrap'

interface Cmd {
  id: string
  label: string
  sublabel: string
  kind: 'Deployment' | 'Client' | 'Invoice'
  to: string
}

export function CommandPalette({ open, onClose }: { open: boolean; onClose: () => void }) {
  const navigate = useNavigate()
  const deployments = useStore((s) => s.deployments)
  const clients = useStore((s) => s.clients)
  const invoices = useStore((s) => s.invoices)
  const [query, setQuery] = useState('')
  const [active, setActive] = useState(0)
  const inputRef = useRef<HTMLInputElement>(null)
  const boxRef = useRef<HTMLDivElement>(null)
  useFocusTrap(boxRef, open)

  const all = useMemo<Cmd[]>(
    () => [
      ...deployments.map((d) => ({ id: d.id, label: d.name, sublabel: d.subdomain, kind: 'Deployment' as const, to: `/deployments/${d.id}` })),
      ...clients.map((c) => ({ id: c.id, label: c.name, sublabel: c.company, kind: 'Client' as const, to: '/clients' })),
      ...invoices.map((i) => ({ id: i.id, label: i.id, sublabel: i.deployment_id, kind: 'Invoice' as const, to: '/invoices' })),
    ],
    [deployments, clients, invoices],
  )

  const results = useMemo(() => {
    const q = query.trim().toLowerCase()
    const list = q ? all.filter((c) => `${c.label} ${c.sublabel}`.toLowerCase().includes(q)) : all
    return list.slice(0, 8)
  }, [all, query])

  useEffect(() => {
    if (open) {
      setQuery('')
      setActive(0)
      setTimeout(() => inputRef.current?.focus(), 0)
    }
  }, [open])

  useEffect(() => {
    setActive(0)
  }, [query])

  if (!open) return null

  const go = (c: Cmd) => {
    navigate(c.to)
    onClose()
  }

  return createPortal(
    <div className="scns-cmdk" onClick={onClose}>
      <div ref={boxRef} className="scns-cmdk__box" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-label="Command palette">
        <div style={{ display: 'flex', alignItems: 'center' }}>
          <Search size={16} style={{ position: 'absolute', marginLeft: 16, color: 'var(--cds-text-helper)' }} />
          <input
            ref={inputRef}
            className="scns-cmdk__input"
            style={{ paddingLeft: 44 }}
            placeholder="Search deployments, clients, invoices…"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'ArrowDown') {
                e.preventDefault()
                setActive((a) => Math.min(a + 1, results.length - 1))
              } else if (e.key === 'ArrowUp') {
                e.preventDefault()
                setActive((a) => Math.max(a - 1, 0))
              } else if (e.key === 'Enter') {
                e.preventDefault()
                if (results[active]) go(results[active])
              } else if (e.key === 'Escape') {
                onClose()
              }
            }}
          />
        </div>
        <ul style={{ listStyle: 'none', margin: 0, padding: 0, maxHeight: 360, overflowY: 'auto' }}>
          {results.length === 0 && (
            <li className="scns-helper" style={{ padding: 16 }}>
              No matches.
            </li>
          )}
          {results.map((c, i) => (
            <li
              key={`${c.kind}-${c.id}`}
              className={`scns-cmdk__item${i === active ? ' scns-cmdk__item--active' : ''}`}
              onMouseEnter={() => setActive(i)}
              onClick={() => go(c)}
            >
              <span>
                <span className="scns-mono" style={{ color: 'var(--cds-link-primary)' }}>{c.label}</span>
                <span className="scns-helper" style={{ marginLeft: 8, fontSize: 12 }}>{c.sublabel}</span>
              </span>
              <span className="scns-mono scns-helper" style={{ fontSize: 10, textTransform: 'uppercase', letterSpacing: 0.4 }}>
                {c.kind}
              </span>
            </li>
          ))}
        </ul>
      </div>
    </div>,
    document.body,
  )
}
