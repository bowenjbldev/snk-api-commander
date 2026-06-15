import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'

// A single 1s clock shared app-wide. Only leaf components that must update live
// (GraceCountdown, RelativeTime) consume it — so a tick re-renders those leaves,
// never whole tables.
const TickContext = createContext<number>(Date.now())

export function TickProvider({ children }: { children: ReactNode }) {
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => {
    const id = setInterval(() => setNow(Date.now()), 1000)
    return () => clearInterval(id)
  }, [])
  return <TickContext.Provider value={now}>{children}</TickContext.Provider>
}

/** Current time in ms, updated once per second. */
export function useTick(): number {
  return useContext(TickContext)
}
