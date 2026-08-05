import { k, rel } from '$lib/format';
import { meterFromQuota, worstMeter, type Meter } from '$lib/domain/meter';
import { byUrgency, supply, type Supply } from '$lib/domain/supply';
import { Situation } from '$lib/model/enums';
import { displayName, situationOf, toSupplyInput } from '$lib/model/situation';
import type { CommanderPackage } from '$lib/model/types';

/* eslint-disable svelte/prefer-svelte-reactivity --
 * `new Date(...).getTime()` se usa para leer un instante y descartarlo: no hay fecha que mutar
 * ni que observar. `SvelteDate` es para una fecha que vive en el estado. */

export interface FleetRow {
	id: number;
	name: string;
	slug: string;
	client: string;
	plan: string;
	situation: Situation;
	supply: Supply;
	meter: Meter | null;
	requests: string;
	seen: string;
	/** Instante del corte previsto, para la cuenta atrás de la fila. Nulo si no hay preaviso. */
	graceUntil: number | null;
	/** Texto para el buscador: producto, cliente e identificador a la vez. */
	haystack: string;
}

export function toRow(pkg: CommanderPackage, now: number): FleetRow {
	const name = displayName(pkg);
	const client = pkg.client?.company ?? pkg.client?.name ?? '—';
	const quotas = pkg.quotas ?? [];

	return {
		id: pkg.id,
		name,
		slug: pkg.slug ?? pkg.name,
		client,
		plan: pkg.subscription?.plan ?? '—',
		situation: situationOf(pkg, now),
		supply: supply(toSupplyInput(pkg, now), now),
		meter: worstMeter(quotas) ?? (quotas[0] ? meterFromQuota(quotas[0]) : null),
		requests: k(pkg.requests_24h),
		graceUntil: pkg.grace_until ? new Date(pkg.grace_until).getTime() : null,
		seen: pkg.last_seen ? rel(now - new Date(pkg.last_seen).getTime()) : '—',
		haystack: `${name} ${pkg.slug ?? ''} ${client} ${pkg.id}`.toLowerCase()
	};
}

/** Los chips de situación de la Flota, con su recuento. */
export interface SituationFilter {
	id: string;
	label: string;
	match: (row: FleetRow) => boolean;
}

export const FILTERS: SituationFilter[] = [
	{ id: 'all', label: 'Todas', match: () => true },
	{ id: 'live', label: 'Conectadas', match: (r) => r.situation === Situation.Live },
	{
		id: 'pending',
		label: 'Alta pendiente',
		match: (r) => r.situation === Situation.Pending || r.situation === Situation.Stalled
	},
	{ id: 'catalog', label: 'Solo catálogo', match: (r) => r.situation === Situation.Catalog }
];

/**
 * Orden por urgencia: preaviso caducado → cortada → preaviso corriendo → alta atascada → alta en
 * curso → conectada → solo catálogo. Lo que exige decisión sube por encima de lo que está sano,
 * porque es trabajo pendiente y lo sano no.
 */
export function sortRows(rows: FleetRow[]): FleetRow[] {
	return rows.toSorted(
		(a, b) => byUrgency(a.supply, b.supply) || a.name.localeCompare(b.name, 'es')
	);
}
