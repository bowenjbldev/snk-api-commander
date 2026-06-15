import { describe, it, expect, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { InlineEdit } from './InlineEdit'

describe('InlineEdit', () => {
  it('commits a new value on Enter', async () => {
    const onCommit = vi.fn()
    render(<InlineEdit display="500,000" rawValue="500000" ariaLabel="limit" onCommit={onCommit} />)
    await userEvent.click(screen.getByRole('button', { name: /edit limit/i }))
    const input = screen.getByLabelText('limit')
    await userEvent.clear(input)
    await userEvent.type(input, '1000{Enter}')
    expect(onCommit).toHaveBeenCalledWith('1000')
  })

  it('cancels on Escape', async () => {
    const onCommit = vi.fn()
    render(<InlineEdit display="500,000" rawValue="500000" ariaLabel="limit" onCommit={onCommit} />)
    await userEvent.click(screen.getByRole('button', { name: /edit limit/i }))
    const input = screen.getByLabelText('limit')
    await userEvent.type(input, '9{Escape}')
    expect(onCommit).not.toHaveBeenCalled()
  })
})
