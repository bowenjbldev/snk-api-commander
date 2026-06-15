import type { ReactNode } from 'react'

// Carbon ships no warm-amber tag; this matches the design tokens + pill shape.
export function AmberTag({ children, small }: { children: ReactNode; small?: boolean }) {
  return <span className={`scns-tag scns-tag--amber${small ? ' scns-tag--sm' : ''}`}>{children}</span>
}
