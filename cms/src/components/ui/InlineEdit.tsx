import { useEffect, useRef, useState } from 'react'
import { IconButton, TextInput } from '@carbon/react'
import { Checkmark, Close } from '@carbon/icons-react'

/**
 * Inline-editable value. Renders a dashed-underline affordance; clicking swaps
 * to a TextInput with ✓/✕ confirm. Enter/✓ commits, Esc/✕ cancels.
 *
 * `value` is the display string; `onCommit` receives the raw input string
 * (empty string === cleared, which callers may treat as "unlimited").
 */
export function InlineEdit({
  value,
  placeholder,
  isPlaceholder,
  ariaLabel,
  onCommit,
  parse,
  align = 'left',
}: {
  value: string
  /** Shown (placeholder-colored) when there is no value, e.g. "Unlimited". */
  placeholder?: string
  isPlaceholder?: boolean
  ariaLabel: string
  onCommit: (raw: string) => void
  /** Optional validator; return false to reject the commit. */
  parse?: (raw: string) => boolean
  align?: 'left' | 'right'
}) {
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState('')
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (editing) inputRef.current?.focus()
  }, [editing])

  const start = () => {
    setDraft(isPlaceholder ? '' : value)
    setEditing(true)
  }
  const commit = () => {
    if (parse && !parse(draft)) return
    onCommit(draft.trim())
    setEditing(false)
  }
  const cancel = () => setEditing(false)

  if (editing) {
    return (
      <span
        style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}
      >
        <span style={{ width: 120 }} className="sc-inline-edit-field">
          <TextInput
            ref={inputRef}
            id={`inline-${ariaLabel}`}
            labelText=""
            hideLabel
            size="sm"
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') commit()
              if (e.key === 'Escape') cancel()
            }}
          />
        </span>
        <IconButton
          label="Save"
          size="sm"
          kind="primary"
          onClick={commit}
        >
          <Checkmark />
        </IconButton>
        <IconButton label="Cancel" size="sm" kind="tertiary" onClick={cancel}>
          <Close />
        </IconButton>
      </span>
    )
  }

  return (
    <button
      type="button"
      onClick={start}
      aria-label={`Edit ${ariaLabel}`}
      className="sc-mono"
      style={{
        background: 'none',
        border: 'none',
        padding: 0,
        cursor: 'pointer',
        font: 'inherit',
        textAlign: align,
        width: '100%',
        color: isPlaceholder
          ? 'var(--cds-text-placeholder)'
          : 'var(--cds-text-primary)',
        borderBottom: '1px dashed var(--cds-border-strong)',
      }}
    >
      {isPlaceholder ? placeholder : value}
    </button>
  )
}
