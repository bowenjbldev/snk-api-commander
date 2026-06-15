import '@testing-library/jest-dom/vitest'
import { vi } from 'vitest'

// jsdom doesn't implement these; Carbon components touch them.
if (!window.matchMedia) {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn(),
  }))
}

class ResizeObserverStub {
  observe() {}
  unobserve() {}
  disconnect() {}
}
// @ts-expect-error assign stub
window.ResizeObserver = window.ResizeObserver ?? ResizeObserverStub
