/* eslint-disable svelte/prefer-svelte-reactivity --
 * `new Date(...).getTime()` lee un instante y lo descarta: no hay fecha que mutar ni observar. */
import { eur, k, rel } from '$lib/format';
import { meterFromQuota, worstMeter, type Meter } from '$lib/domain/meter';
import { BillingStatus, ClientStatus, PackageStatus } from '$lib/model/enums';
import type {
	CommanderClient,
	CommanderInvoice,
	CommanderPackage,
	CommanderQuota,
	CommanderSubscription
} from '$lib/model/types';

export interface ClientRow {
	id: number;
	slug: string;
	company: string;
	inactive: boolean;
	/** Tiene alguna factura vencida: cobro y suministro se leen juntos. */
	overdue: boolean;
	mrr: string;
	requests: string;
	meter: Meter | null;
	seen: string;
	meta: string;
	haystack: string;
}

export interface ClientSource {
	clients: readonly CommanderClient[];
	packages: readonly CommanderPackage[];
	subscriptions: readonly CommanderSubscription[];
	invoices: readonly CommanderInvoice[];
	now: number;
}

export function toClientRows(source: ClientSource): ClientRow[] {
	const { clients, packages, subscriptions, invoices, now } = source;

	return clients.map((client) => {
		const own = packages.filter((pkg) => pkg.client_id === client.id);
		const quotas: CommanderQuota[] = own.flatMap((pkg) => pkg.quotas ?? []);

		// Solo cuentan los términos vigentes de instancias que siguen sirviendo: una cortada no
		// factura por el hecho de existir.
		const mrr = subscriptions
			.filter(
				(sub) =>
					sub.client_id === client.id &&
					sub.ended_at === null &&
					own.some((pkg) => pkg.id === sub.package_id && pkg.status !== PackageStatus.Suspended)
			)
			.reduce((total, sub) => total + Number(sub.price_monthly), 0);

		const lastSeen = own
			.map((pkg) => (pkg.last_seen ? new Date(pkg.last_seen).getTime() : 0))
			.reduce((newest, at) => Math.max(newest, at), 0);

		const company = client.company ?? client.name;

		return {
			id: client.id,
			slug: client.slug,
			company,
			inactive: client.status === ClientStatus.Inactive,
			overdue: invoices.some(
				(invoice) => invoice.client_id === client.id && invoice.status === BillingStatus.Overdue
			),
			mrr: eur(mrr),
			requests: k(own.reduce((total, pkg) => total + pkg.requests_24h, 0)),
			meter: worstMeter(quotas) ?? (quotas[0] ? meterFromQuota(quotas[0]) : null),
			seen: lastSeen ? rel(now - lastSeen) : '—',
			meta: [client.country, client.payment_terms].filter(Boolean).join(' · ') || '—',
			haystack: `${company} ${client.slug} ${client.email ?? ''}`.toLowerCase()
		};
	});
}
