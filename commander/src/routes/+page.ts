import { redirect } from '@sveltejs/kit';
import { Route } from '$lib/nav/routes';

/** La consola abre en Panorama: responde «¿tengo que actuar?» en tres segundos. */
export function load(): never {
	redirect(307, Route.Overview);
}
