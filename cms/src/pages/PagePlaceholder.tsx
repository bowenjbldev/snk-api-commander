import type { ReactNode } from 'react'

interface PagePlaceholderProps {
  title: string
  subtitle?: string
  children?: ReactNode
}

// Temporary scaffold for routes not yet built out. Replaced per-phase.
export function PagePlaceholder({ title, subtitle, children }: PagePlaceholderProps) {
  return (
    <div className="scns-page">
      <h1 style={{ fontSize: 24, fontWeight: 400, margin: 0 }}>{title}</h1>
      {subtitle && (
        <p className="scns-helper" style={{ marginTop: 4 }}>
          {subtitle}
        </p>
      )}
      {children ?? (
        <p className="scns-helper" style={{ marginTop: 32 }}>
          Coming soon.
        </p>
      )}
    </div>
  )
}
