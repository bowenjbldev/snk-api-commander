import { useEffect } from 'react'
import { useStore } from '../store/store'
import { randomLogEntry } from '../store/liveTail'

// Prepends a synthetic log every ~1.4s (~43 req/min) while enabled and not paused.
// Pauses on toggle-off or when a row is selected (per the spec).
export function useLiveTail(enabled: boolean, paused: boolean) {
  useEffect(() => {
    if (!enabled || paused) return
    const id = setInterval(() => {
      const { deployments, prependLog } = useStore.getState()
      prependLog(randomLogEntry(deployments))
    }, 1400)
    return () => clearInterval(id)
  }, [enabled, paused])
}
