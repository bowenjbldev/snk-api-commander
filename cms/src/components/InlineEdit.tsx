import { useEffect, useRef, useState } from 'react'
import { Checkmark, Close } from '@carbon/icons-react'

interface Props {
  /** Formatted display value when not editing (e.g. "500,000"). */
  display: string
  /** Raw editable value (e.g. "500000"); empty string shows the placeholder. */
  rawValue: string
  placeholder?: string
  ariaLabel: string
  align?: 'left' | 'right'
  onCommit: (raw: string) => void
}

// Dashed-underline value that swaps to an input with ✓/✕ on click. Enter/✓ commit,
// Esc/✕ cancel. Used for inline-editable quota limits.
export function InlineEdit({ display, rawValue, placeholder, ariaLabel, align = 'left', onCommit }: Props) {
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState(rawValue)
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (editing) {
      inputRef.current?.focus()
      inputRef.current?.select()
    }
  }, [editing])

  const start = () => {
    setDraft(rawValue)
    setEditing(true)
  }
  const commit = () => {
    setEditing(false)
    if (draft !== rawValue) onCommit(draft.trim())
  }
  const cancel = () => setEditing(false)

  if (!editing) {
    const empty = rawValue === ''
    return (
      <button
        type="button"
        className={`scns-inline-value scns-mono${empty ? ' scns-inline-value--placeholder' : ''}`}
        style={{ textAlign: align, width: '100%', display: 'inline-block' }}
        onClick={start}
        aria-label={`Edit ${ariaLabel}`}
      >
        {empty ? (placeholder ?? '—') : display}
      </button>
    )
  }

  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}>
      <input
        ref={inputRef}
        className="scns-inline-input scns-mono"
        value={draft}
        placeholder={placeholder}
        inputMode="numeric"
        onChange={(e) => setDraft(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter') commit()
          else if (e.key === 'Escape') cancel()
        }}
        aria-label={ariaLabel}
      />
      <button type="button" className="scns-inline-btn scns-inline-btn--ok" onClick={commit} aria-label="Save">
        <Checkmark size={16} />
      </button>
      <button type="button" className="scns-inline-btn scns-inline-btn--cancel" onClick={cancel} aria-label="Cancel">
        <Close size={16} />
      </button>
    </span>
  )
}
