import type { Deployment } from '../store/types'

// El dominio base del despliegue. En prod el SPA corre en commander.<BASE_DOMAIN>, así que se deriva
// del host quitando el prefijo `commander.`; override opcional vía VITE_BASE_DOMAIN. Fallback snake.test.
export function baseDomain(): string {
  const env = (import.meta.env.VITE_BASE_DOMAIN as string | undefined) ?? ''
  if (env) return env

  const host = typeof window !== 'undefined' ? window.location.hostname : ''
  if (host.startsWith('commander.')) return host.slice('commander.'.length)

  return host || 'snake.test'
}

// FQDN del deployment, derivado del subdominio bare de la relación `package` (la identidad ya no se
// almacena en el deployment: se lee de package.subdomain + el dominio base).
export function deploymentFqdn(d: Deployment): string {
  return `${d.package.subdomain}.${baseDomain()}`
}

// Base URL de la API del deployment (https://<fqdn>/api/v1).
export function deploymentBaseUrl(d: Deployment): string {
  return `https://${deploymentFqdn(d)}/api/v1`
}
