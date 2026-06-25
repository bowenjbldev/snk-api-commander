import { useSyncExternalStore } from 'react'
import { useStore } from '../store/store'

// Browser connectivity, read via useSyncExternalStore so it stays in sync with the
// online/offline events without a manual effect + stale closure.
function subscribeOnline(cb: () => void) {
  window.addEventListener('online', cb)
  window.addEventListener('offline', cb)
  return () => {
    window.removeEventListener('online', cb)
    window.removeEventListener('offline', cb)
  }
}
const getOnline = () => navigator.onLine

// Right-cluster connection indicator (design §7: dot + label). Plain visible text, no tooltip —
// Carbon advises against tooltips on already-visible content. Honest signal: connectivity from
// navigator.onLine and "reached the API at least once" from the store's `loaded` flag — NOT from
// `actionError`, which also fires on benign 4xx (e.g. a 422 validation error) and would falsely
// read as a dropped connection.
export function ConnectionStatus() {
  const online = useSyncExternalStore(subscribeOnline, getOnline, () => true)
  const loaded = useStore((s) => s.loaded)

  const state = !online ? 'offline' : loaded ? 'connected' : 'connecting'
  const label = state === 'offline' ? 'Offline' : state === 'connecting' ? 'Connecting…' : 'Connected'

  return (
    <span className={`scns-conn scns-conn--${state}`}>
      <span className="scns-conn__dot" aria-hidden="true" />
      <span className="scns-conn__label">{label}</span>
    </span>
  )
}
