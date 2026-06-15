import { Loading } from '@carbon/react'

/** Centered spinner for the auth check and the first registry load. */
export function FullScreenLoading({ label = 'Loading…' }: { label?: string }) {
  return (
    <div
      style={{
        position: 'fixed',
        inset: 0,
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        gap: 16,
      }}
    >
      <Loading withOverlay={false} description={label} />
      <span style={{ fontSize: 13, color: 'var(--cds-text-secondary)' }}>{label}</span>
    </div>
  )
}
