import { createContext, useCallback, useContext, useMemo, useRef, useState, type ReactNode } from 'react'
import { ToastNotification } from '@carbon/react'

type ToastKind = 'success' | 'error' | 'warning' | 'info'

interface ToastItem {
  id: number
  kind: ToastKind
  title: string
  subtitle?: string
  caption: string
  actionLabel?: string
  onAction?: () => void
}

interface ToastApi {
  push: (kind: ToastKind, title: string, subtitle?: string, opts?: ToastExtra) => void
  success: (title: string, subtitle?: string, opts?: ToastExtra) => void
  error: (title: string, subtitle?: string, opts?: ToastExtra) => void
  warning: (title: string, subtitle?: string, opts?: ToastExtra) => void
  info: (title: string, subtitle?: string, opts?: ToastExtra) => void
}

interface ToastExtra {
  actionLabel?: string
  onAction?: () => void
}

const ToastContext = createContext<ToastApi | null>(null)

function stamp(): string {
  return `${new Date().toISOString().slice(11, 19)} UTC`
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<ToastItem[]>([])
  const seq = useRef(0)

  const remove = useCallback((id: number) => {
    setToasts((t) => t.filter((x) => x.id !== id))
  }, [])

  const push = useCallback<ToastApi['push']>((kind, title, subtitle, opts) => {
    seq.current += 1
    const id = seq.current
    setToasts((t) => [
      { id, kind, title, subtitle, caption: stamp(), actionLabel: opts?.actionLabel, onAction: opts?.onAction },
      ...t,
    ])
  }, [])

  const api = useMemo<ToastApi>(
    () => ({
      push,
      success: (title, subtitle, opts) => push('success', title, subtitle, opts),
      error: (title, subtitle, opts) => push('error', title, subtitle, opts),
      warning: (title, subtitle, opts) => push('warning', title, subtitle, opts),
      info: (title, subtitle, opts) => push('info', title, subtitle, opts),
    }),
    [push],
  )

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div
        aria-live="polite"
        role="region"
        aria-label="Notifications"
        style={{
          position: 'fixed',
          right: 16,
          bottom: 16,
          zIndex: 9000,
          display: 'flex',
          flexDirection: 'column',
          gap: 8,
        }}
      >
        {toasts.map((t) => (
          <ToastNotification
            key={t.id}
            kind={t.kind}
            title={t.title}
            subtitle={t.subtitle}
            caption={t.caption}
            timeout={t.actionLabel ? 0 : 6000}
            onClose={() => {
              remove(t.id)
              return true
            }}
            style={{ minWidth: 320 }}
          >
            {t.actionLabel && t.onAction && (
              <button
                type="button"
                className="scns-link"
                style={{ background: 'none', border: 'none', padding: '8px 0 0', cursor: 'pointer' }}
                onClick={() => {
                  t.onAction?.()
                  remove(t.id)
                }}
              >
                {t.actionLabel}
              </button>
            )}
          </ToastNotification>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast(): ToastApi {
  const ctx = useContext(ToastContext)
  if (!ctx) throw new Error('useToast must be used within ToastProvider')
  return ctx
}
