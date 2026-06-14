import {
  createContext,
  useCallback,
  useContext,
  useRef,
  useState,
  type ReactNode,
} from 'react'
import { ActionableNotification, ToastNotification } from '@carbon/react'
import { simNowMs } from '../lib/time'

type ToastKind = 'success' | 'error' | 'warning' | 'info'

export interface ToastInput {
  kind?: ToastKind
  title: string
  subtitle?: string
  /** Provide to render a retry/action affordance (used for failures). */
  actionLabel?: string
  onAction?: () => void
  /** ms before auto-dismiss; 0 disables (errors persist by default). */
  timeout?: number
}

interface ToastItem extends Required<Pick<ToastInput, 'title'>> {
  id: number
  kind: ToastKind
  subtitle?: string
  caption: string
  actionLabel?: string
  onAction?: () => void
  timeout: number
}

interface ToastCtx {
  notify: (t: ToastInput) => void
  success: (title: string, subtitle?: string) => void
  error: (title: string, subtitle?: string, onRetry?: () => void) => void
}

const Ctx = createContext<ToastCtx | null>(null)

function caption(): string {
  const d = new Date(simNowMs())
  const p = (n: number) => String(n).padStart(2, '0')
  return `${p(d.getUTCHours())}:${p(d.getUTCMinutes())}:${p(
    d.getUTCSeconds(),
  )} UTC`
}

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<ToastItem[]>([])
  const seq = useRef(0)

  const remove = useCallback((id: number) => {
    setToasts((cur) => cur.filter((t) => t.id !== id))
  }, [])

  const notify = useCallback((input: ToastInput) => {
    const kind = input.kind ?? 'success'
    const id = ++seq.current
    const defaultTimeout = kind === 'error' ? 0 : 5000
    setToasts((cur) => [
      ...cur,
      {
        id,
        kind,
        title: input.title,
        subtitle: input.subtitle,
        caption: caption(),
        actionLabel: input.actionLabel,
        onAction: input.onAction,
        timeout: input.timeout ?? defaultTimeout,
      },
    ])
  }, [])

  const success = useCallback(
    (title: string, subtitle?: string) => notify({ kind: 'success', title, subtitle }),
    [notify],
  )
  const error = useCallback(
    (title: string, subtitle?: string, onRetry?: () => void) =>
      notify({
        kind: 'error',
        title,
        subtitle,
        actionLabel: onRetry ? 'Retry' : undefined,
        onAction: onRetry,
      }),
    [notify],
  )

  return (
    <Ctx.Provider value={{ notify, success, error }}>
      {children}
      <div className="sc-toasts" aria-live="polite" aria-relevant="additions">
        {toasts.map((t) =>
          t.actionLabel ? (
            <ActionableNotification
              key={t.id}
              kind={t.kind}
              title={t.title}
              subtitle={t.subtitle}
              caption={t.caption}
              actionButtonLabel={t.actionLabel}
              onActionButtonClick={() => {
                t.onAction?.()
                remove(t.id)
              }}
              onClose={() => {
                remove(t.id)
                return true
              }}
              lowContrast={false}
              inline={false}
              role="status"
            />
          ) : (
            <ToastNotification
              key={t.id}
              kind={t.kind}
              title={t.title}
              subtitle={t.subtitle}
              caption={t.caption}
              timeout={t.timeout}
              onClose={() => {
                remove(t.id)
                return true
              }}
              lowContrast={false}
              role="status"
            />
          ),
        )}
      </div>
    </Ctx.Provider>
  )
}

export function useToast(): ToastCtx {
  const ctx = useContext(Ctx)
  if (!ctx) throw new Error('useToast must be used within ToastProvider')
  return ctx
}
