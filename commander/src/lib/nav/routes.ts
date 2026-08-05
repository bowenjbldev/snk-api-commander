/* eslint-disable svelte/no-navigation-without-resolve --
 * Este es el ÚNICO fichero del proyecto que navega y el único que construye una ruta. La regla
 * pide `resolve()` para apps servidas bajo un prefijo (`base`); Commander se sirve en la raíz de
 * su propio subdominio, así que `base` es siempre vacío. Centralizarlo aquí es lo que evita el
 * `href` escrito a mano en veinte sitios, que es el problema real que la regla persigue.
 */
import { goto } from '$app/navigation';

export enum Route {
	Login = '/login',
	UiKit = '/uikit',
	Overview = '/overview',
	Fleet = '/fleet',
	Clients = '/clients',
	Invoices = '/invoices',
	Usage = '/usage',
	Logs = '/logs',
	Settings = '/settings',
	Packages = '/packages'
}

/** Las siete vistas del detalle de una instancia. Son rutas anidadas, no estado local. */
export enum PackageTab {
	Identity = 'identity',
	Plans = 'plans',
	Meters = 'meters',
	Usage = 'usage',
	Logs = 'logs',
	Code = 'code',
	Settings = 'settings'
}

/**
 * Una instancia se direcciona SIEMPRE por su identificador numérico, nunca por su nombre: el
 * mismo nombre de producto se repite entre clientes.
 */
export function packageRoute(id: number, tab: PackageTab = PackageTab.Identity): string {
	return `${Route.Packages}/${id}/${tab}`;
}

/** Un cliente sí se direcciona por slug: es único y además es su subdominio real. */
export function clientRoute(slug: string): string {
	return `${Route.Clients}/${encodeURIComponent(slug)}`;
}

/** Navegar. Nadie más llama a `goto`, para que no haya dos formas de moverse por la consola. */
export function navigate(to: string): void {
	void goto(to);
}
