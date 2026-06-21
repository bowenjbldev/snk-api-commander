import type { Package } from '../store/types'

// El dominio base del package provisionado. En prod el SPA corre en commander.<BASE_DOMAIN>, así
// que se deriva del host quitando el prefijo `commander.`; override opcional vía VITE_BASE_DOMAIN.
export function baseDomain(): string {
  const env = (import.meta.env.VITE_BASE_DOMAIN as string | undefined) ?? ''
  if (env) return env

  const host = typeof window !== 'undefined' ? window.location.hostname : ''
  if (host.startsWith('commander.')) return host.slice('commander.'.length)

  return host || 'snake.test'
}

export function packageFqdn(pkg: Package): string {
  return `${pkg.subdomain}.${baseDomain()}`
}

export function packageBaseUrl(pkg: Package): string {
  return `https://${packageFqdn(pkg)}/api/v1`
}
