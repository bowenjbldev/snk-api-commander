import { redirect } from '@sveltejs/kit';
import { PackageTab, packageRoute } from '$lib/nav/routes';

/** El detalle abre en Identidad: los metadatos de catálogo son lo primero que se mira. */
export function load({ params }: { params: { id: string } }): never {
	redirect(307, packageRoute(Number(params.id), PackageTab.Identity));
}
