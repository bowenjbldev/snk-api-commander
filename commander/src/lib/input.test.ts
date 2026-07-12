import { describe, expect, it } from 'vitest'
import { optionalLimit, slugify, validEmail } from './input'

describe('form input helpers', () => {
  it('normalizes user input', () => {
    expect(slugify(' Shop Pro ')).toBe('shop-pro')
    expect(optionalLimit('12.6')).toBe(13)
    expect(optionalLimit('')).toBeNull()
    expect(validEmail('ops@example.com')).toBe(true)
  })
})
