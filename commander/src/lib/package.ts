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

// El host del package provisionado deriva del CLIENTE (client.slug es el subdominio), no del package.
// Requiere que la relación `client` esté cargada (la API la incluye siempre en las respuestas).
export function packageFqdn(pkg: Package): string {
  const sub = pkg.client?.slug ?? 'unknown'
  return `${sub}.${baseDomain()}`
}

// URL base de la instancia: host (client.slug) + segmento de path (package.slug).
export function packageBaseUrl(pkg: Package): string {
  const path = pkg.slug ?? ''
  return `https://${packageFqdn(pkg)}/api/v1${path ? `/${path}` : ''}`
}
