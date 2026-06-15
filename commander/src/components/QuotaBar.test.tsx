import { describe, it, expect } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QuotaBar } from './QuotaBar'

describe('QuotaBar', () => {
  it('shows an OVER badge past 100%', () => {
    render(<QuotaBar current={1240332} limit={1000000} showLabel />)
    expect(screen.getByText('OVER 124%')).toBeInTheDocument()
  })
  it('shows "no limit" when unlimited', () => {
    render(<QuotaBar current={322} limit={null} showLabel />)
    expect(screen.getByText('no limit')).toBeInTheDocument()
  })
  it('shows the percentage under the limit', () => {
    render(<QuotaBar current={44} limit={100} showLabel />)
    expect(screen.getByText('44%')).toBeInTheDocument()
  })
})
