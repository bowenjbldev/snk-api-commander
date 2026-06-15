import { useState } from 'react'
import { IconButton } from '@carbon/react'
import { Copy, View, ViewOff, Checkmark } from '@carbon/icons-react'

interface Props {
  /** The real value that gets copied. */
  value: string
  /** What to display when not revealed (for secrets). Defaults to `value`. */
  masked?: string
  secret?: boolean
  size?: number
}

// Inline mono code chip with a copy button; secrets add an eye reveal toggle and
// copy the real value even while masked.
export function CopyableCode({ value, masked, secret, size = 12 }: Props) {
  const [revealed, setRevealed] = useState(false)
  const [copied, setCopied] = useState(false)
  const shown = secret && !revealed ? (masked ?? value) : value

  const copy = () => {
    navigator.clipboard?.writeText(value).catch(() => {})
    setCopied(true)
    setTimeout(() => setCopied(false), 1200)
  }

  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 2, maxWidth: '100%' }}>
      <code
        className="scns-mono"
        style={{
          fontSize: size,
          background: 'var(--cds-layer-accent)',
          padding: '2px 8px',
          color: 'var(--cds-text-primary)',
          overflow: 'hidden',
          textOverflow: 'ellipsis',
          whiteSpace: 'nowrap',
        }}
      >
        {shown}
      </code>
      {secret && (
        <IconButton
          kind="ghost"
          size="sm"
          label={revealed ? 'Hide' : 'Reveal'}
          onClick={() => setRevealed((r) => !r)}
        >
          {revealed ? <ViewOff /> : <View />}
        </IconButton>
      )}
      <IconButton kind="ghost" size="sm" label={copied ? 'Copied' : 'Copy'} onClick={copy}>
        {copied ? <Checkmark /> : <Copy />}
      </IconButton>
    </span>
  )
}
