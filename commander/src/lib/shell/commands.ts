import { eur } from '$lib/format';
import { displayName } from '$lib/model/situation';
import type { CommanderClient, CommanderInvoice, CommanderPackage } from '$lib/model/types';
import { NAV } from '$lib/nav/nav';
import { clientRoute, navigate, packageRoute } from '$lib/nav/routes';
import { type Command, CommandKind } from './command';

/** Un mando que solo salta a un sitio. Aísla el `void` de `navigate` en un punto. */
function go(to: string): () => void {
	return () => {
		navigate(to);
	};
}

/**
 * La paleta es navegación de verdad, no un buscador decorativo: un operador diario rentabiliza el
 * teclado. Salta a cualquier instancia, cliente o factura sin pasar por la lista.
 */
export function buildCommands(
	packages: readonly CommanderPackage[],
	clients: readonly CommanderClient[],
	invoices: readonly CommanderInvoice[]
): Command[] {
	return [
		...NAV.map((entry) => ({
			id: `nav:${entry.route}`,
			kind: CommandKind.Navigate,
			label: entry.label,
			run: go(entry.route)
		})),

		...packages.map((pkg) => ({
			id: `pkg:${pkg.id}`,
			kind: CommandKind.Instance,
			label: displayName(pkg),
			hint: pkg.client?.slug ?? pkg.slug ?? undefined,
			run: go(packageRoute(pkg.id))
		})),

		...clients.map((client) => ({
			id: `client:${client.id}`,
			kind: CommandKind.Client,
			label: client.company ?? client.name,
			hint: client.slug,
			run: go(clientRoute(client.slug))
		})),

		...invoices.map((invoice) => ({
			id: `invoice:${invoice.id}`,
			kind: CommandKind.Invoice,
			label: invoice.number,
			hint: eur(Number(invoice.amount)),
			run: go(clientRoute(String(invoice.client_id)))
		}))
	];
}
