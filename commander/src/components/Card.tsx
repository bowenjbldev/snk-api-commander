import type { ReactNode } from 'react'

// Bordered card with a header band: the title shares the card's background but is divided from the
// body by a hairline (Carbon has no core Card-with-header, so this is our house treatment). Used by
// the Dashboard (charts + triage) and the client detail page so every titled card reads the same.
export function Card({
  title,
  subtitle,
  action,
  footer,
  bodyPadding = 16,
  bodyClassName,
  className,
  children,
}: {
  title: ReactNode
  subtitle?: ReactNode
  action?: ReactNode
  /** Pinned footer band (e.g. form save/discard actions), divided from the body by a hairline. */
  footer?: ReactNode
  /** Body inset; pass 0 for full-bleed lists whose rows carry their own padding. */
  bodyPadding?: number | string
  bodyClassName?: string
  className?: string
  children: ReactNode
}) {
  return (
    <div className={`scns-card scns-card--sectioned${className ? ` ${className}` : ''}`}>
      <div className="scns-card__header">
        <div className="scns-card__heading">
          <span className="scns-card__title">{title}</span>
          {subtitle != null && <span className="scns-card__sub">{subtitle}</span>}
        </div>
        {action}
      </div>
      <div className={`scns-card__body${bodyClassName ? ` ${bodyClassName}` : ''}`} style={{ padding: bodyPadding }}>
        {children}
      </div>
      {footer != null && <div className="scns-card__footer">{footer}</div>}
    </div>
  )
}
