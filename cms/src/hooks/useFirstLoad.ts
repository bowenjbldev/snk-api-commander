import { useEffect, useState } from 'react'

// Returns true for a brief moment after mount, then false — so list pages can show a
// skeleton on first paint (the demo store is synchronous). One-time and short, so it
// doesn't undo the dashboard render-perf work.
export function useFirstLoad(ms = 280): boolean {
  const [loading, setLoading] = useState(true)
  useEffect(() => {
    const id = setTimeout(() => setLoading(false), ms)
    return () => clearTimeout(id)
  }, [ms])
  return loading
}
