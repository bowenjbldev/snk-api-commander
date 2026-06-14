// Simulated demo clock.
//
// The design is a snapshot at a fixed instant. To make countdowns and relative
// timestamps both MATCH the mock ("13h 42m", "3h ago") AND tick live, we anchor
// a virtual "now" to that instant and advance it by real elapsed wall-time.
import {
  createContext,
  createElement,
  useContext,
  useEffect,
  useState,
  type ReactNode,
} from 'react'

export const DEMO_NOW_ISO = '2026-06-11T14:02:31Z'
export const DEMO_NOW_MS = Date.parse(DEMO_NOW_ISO)

export const SEC = 1000
export const MIN = 60 * SEC
export const HOUR = 60 * MIN
export const DAY = 24 * HOUR

/** ISO timestamp `seconds` before the demo instant (deterministic, no Date.now). */
export function agoIso(seconds: number): string {
  return new Date(DEMO_NOW_MS - seconds * 1000).toISOString()
}
/** ISO timestamp `seconds` after the demo instant. */
export function aheadIso(seconds: number): string {
  return new Date(DEMO_NOW_MS + seconds * 1000).toISOString()
}

// Real wall-clock anchor, captured once at module load.
const REAL_START_MS = Date.now()

/** Current simulated time in ms (demo instant + real elapsed). */
export function simNowMs(): number {
  return DEMO_NOW_MS + (Date.now() - REAL_START_MS)
}

// ---------------------------------------------------------------------------
// React clock context — re-renders subscribers every second.
// ---------------------------------------------------------------------------
const NowContext = createContext<number>(DEMO_NOW_MS)

export function NowProvider({ children }: { children: ReactNode }) {
  const [nowMs, setNowMs] = useState<number>(() => simNowMs())
  useEffect(() => {
    const t = window.setInterval(() => setNowMs(simNowMs()), 1000)
    return () => window.clearInterval(t)
  }, [])
  return createElement(NowContext.Provider, { value: nowMs }, children)
}

/** Ticking simulated now (ms). Re-renders the caller every second. */
export function useNowMs(): number {
  return useContext(NowContext)
}
