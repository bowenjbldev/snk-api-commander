import { useEffect, useState, type DependencyList } from 'react'

export function useLoad<T>(load: () => Promise<T>, deps: DependencyList) {
  const [state, setState] = useState<{ loading: boolean; data: T | null; error: string | null }>({
    loading: true, data: null, error: null,
  })
  useEffect(() => {
    let active = true
    queueMicrotask(() => active && setState({ loading: true, data: null, error: null }))
    load().then(
      (data) => active && setState({ loading: false, data, error: null }),
      (error: unknown) => active && setState({ loading: false, data: null, error: error instanceof Error ? error.message : 'Request failed' }),
    )
    return () => { active = false }
    // The caller names the values that define the request, like useEffect itself.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps)
  return state
}
