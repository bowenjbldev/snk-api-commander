import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Search } from '@carbon/icons-react'
import { useStore } from '../store/DataStore'
import { clients } from '../data/dataset'
import { Mono } from '../components/ui/Bits'

interface Result {
  label: string
  hint: string
  to: string
  mono?: boolean
}

export function CommandPalette({
  open,
  onClose,
}: {
  open: boolean
  onClose: () => void
}) {
  const navigate = useNavigate()
  const { deployments, invoices } = useStore()
  const [q, setQ] = useState('')
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (open) {
      setQ('')
      setTimeout(() => inputRef.current?.focus(), 0)
    }
  }, [open])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  const results = useMemo<Result[]>(() => {
    const query = q.trim().toLowerCase()
    const all: Result[] = [
      ...deployments.map((d) => ({
        label: d.name,
        hint: `Deployment · ${d.clientName}`,
        to: `/deployments/${d.id}`,
        mono: true,
      })),
      ...clients.map((c) => ({
        label: c.name,
        hint: `Client · ${c.company}`,
        to: `/clients?focus=${c.id}`,
      })),
      ...invoices.map((i) => ({
        label: i.number,
        hint: `Invoice · ${i.clientName}`,
        to: `/invoices?focus=${i.id}`,
        mono: true,
      })),
    ]
    if (!query) return all.slice(0, 8)
    return all
      .filter(
        (r) =>
          r.label.toLowerCase().includes(query) ||
          r.hint.toLowerCase().includes(query),
      )
      .slice(0, 10)
  }, [q, deployments, invoices])

  if (!open) return null
  const go = (to: string) => {
    navigate(to)
    onClose()
  }

  return (
    <div
      className="sc-drawer-overlay"
      style={{ display: 'flex', justifyContent: 'center', alignItems: 'flex-start' }}
      onClick={onClose}
    >
      <div
        role="dialog"
        aria-label="Command palette"
        onClick={(e) => e.stopPropagation()}
        style={{
          marginTop: 96,
          width: 560,
          maxWidth: '92vw',
          background: 'var(--cds-layer)',
          border: '1px solid var(--cds-border-subtle)',
          boxShadow: '0 8px 24px rgba(0,0,0,0.4)',
        }}
      >
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            padding: '0 14px',
            height: 48,
            borderBottom: '1px solid var(--cds-border-subtle)',
          }}
        >
          <Search size={16} />
          <input
            ref={inputRef}
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Search deployments, clients, invoices…"
            style={{
              flex: 1,
              background: 'transparent',
              border: 'none',
              outline: 'none',
              color: 'var(--cds-text-primary)',
              fontSize: 14,
            }}
          />
        </div>
        <ul style={{ listStyle: 'none', margin: 0, padding: 0, maxHeight: 360, overflowY: 'auto' }}>
          {results.map((r, i) => (
            <li key={i}>
              <button
                type="button"
                onClick={() => go(r.to)}
                style={{
                  width: '100%',
                  textAlign: 'left',
                  display: 'flex',
                  justifyContent: 'space-between',
                  gap: 12,
                  alignItems: 'center',
                  background: 'none',
                  border: 'none',
                  borderTop: '1px solid var(--cds-border-subtle)',
                  padding: '10px 14px',
                  cursor: 'pointer',
                  color: 'var(--cds-text-primary)',
                }}
              >
                {r.mono ? <Mono color="var(--cds-link-primary)">{r.label}</Mono> : <span>{r.label}</span>}
                <span style={{ fontSize: 12, color: 'var(--cds-text-helper)' }}>{r.hint}</span>
              </button>
            </li>
          ))}
          {results.length === 0 && (
            <li style={{ padding: 16, fontSize: 13, color: 'var(--cds-text-helper)' }}>
              No matches.
            </li>
          )}
        </ul>
      </div>
    </div>
  )
}
