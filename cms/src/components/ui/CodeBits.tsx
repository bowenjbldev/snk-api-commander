import { useState } from 'react'
import { CodeSnippet, IconButton } from '@carbon/react'
import { Copy, View, ViewOff } from '@carbon/icons-react'

/** Inline/single-line value with Carbon's built-in copy affordance. */
export function CopyableCode({
  value,
  type = 'single',
  onCopy,
}: {
  value: string
  type?: 'inline' | 'single'
  onCopy?: () => void
}) {
  return (
    <CodeSnippet
      type={type}
      feedback="Copied"
      onClick={() => {
        navigator.clipboard?.writeText(value).catch(() => {})
        onCopy?.()
      }}
      className="sc-mono"
    >
      {value}
    </CodeSnippet>
  )
}

/**
 * Masked secret with an eye toggle to reveal and a copy button.
 * `last4` is the only real fragment; the reveal shows a representative value.
 */
export function MaskedSecret({
  prefix = 'sk_ctl_',
  last4,
  revealed: revealedValue,
  onCopy,
}: {
  prefix?: string
  last4: string
  /** Full value shown when revealed; defaults to a representative string. */
  revealed?: string
  onCopy?: () => void
}) {
  const [shown, setShown] = useState(false)
  const masked = `${prefix}${'•'.repeat(20)}${last4}`
  const full = revealedValue ?? `${prefix}live_4Kd9Xa2pLm7Qz0Rt8Vc${last4}`
  return (
    <span
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 6,
        background: 'var(--cds-field)',
        border: '1px solid var(--cds-border-subtle)',
        padding: '4px 8px',
      }}
    >
      <span className="sc-mono" style={{ fontSize: 12.5 }}>
        {shown ? full : masked}
      </span>
      <IconButton
        label={shown ? 'Hide secret' : 'Reveal secret'}
        kind="ghost"
        size="sm"
        onClick={() => setShown((s) => !s)}
      >
        {shown ? <ViewOff /> : <View />}
      </IconButton>
      <IconButton
        label="Copy secret"
        kind="ghost"
        size="sm"
        onClick={() => {
          navigator.clipboard?.writeText(full).catch(() => {})
          onCopy?.()
        }}
      >
        <Copy />
      </IconButton>
    </span>
  )
}
