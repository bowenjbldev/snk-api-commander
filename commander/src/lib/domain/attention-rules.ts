import { cd, eur, rel } from '$lib/format';
import { BillingStatus, PackageStatus, Situation, SupplyShape, Tone } from '$lib/model/enums';
import { displayName, situationOf, toSupplyInput } from '$lib/model/situation';
import type { CommanderInvoice, CommanderPackage } from '$lib/model/types';
import type { AttentionItem } from './attention';
import { WARN_THRESHOLD, worstMeter } from './meter';
import { supply } from './supply';

/**
 * Las reglas, una por familia. Cada una devuelve cero o un elemento, así que añadir una situación
 * nueva es añadir una función aquí y una línea en el orquestador — no tocar un `if` de sesenta
 * líneas.
 */
export function packageItems(pkg: CommanderPackage, now: number): AttentionItem[] {
	const context = `${displayName(pkg)} · ${pkg.client?.company ?? pkg.client?.name ?? '—'}`;
	const situation = situationOf(pkg, now);
	return [
		...supplyItem(pkg, now, context),
		...stalledItem(pkg, situation, now, context),
		...quotaItem(pkg, situation, context)
	];
}

function supplyItem(pkg: CommanderPackage, now: number, context: string): AttentionItem[] {
	const state = supply(toSupplyInput(pkg, now), now);

	if (state.shape === SupplyShape.Expired) {
		return [
			{
				id: `expired:${pkg.id}`,
				tone: Tone.Error,
				title: 'Preaviso caducado',
				body: `${context} — ya no está servido`,
				action: 'Decidir',
				packageId: pkg.id
			}
		];
	}

	if (state.shape === SupplyShape.Shrink && pkg.grace_until) {
		return [
			{
				id: `grace:${pkg.id}`,
				tone: Tone.Warn,
				title: `Preaviso corriendo · ${cd(new Date(pkg.grace_until).getTime(), now)}`,
				body: `${context} — ${pkg.reason ?? 'sin motivo registrado'}`,
				action: 'Extender preaviso',
				packageId: pkg.id
			}
		];
	}

	return [];
}

/** No hay estado `failed` que inventar: la única señal honesta es el reloj. */
function stalledItem(
	pkg: CommanderPackage,
	situation: Situation,
	now: number,
	context: string
): AttentionItem[] {
	if (situation !== Situation.Stalled) return [];
	return [
		{
			id: `stalled:${pkg.id}`,
			tone: Tone.Error,
			title: `Alta sin completar hace ${rel(now - new Date(pkg.created_at).getTime())}`,
			body: `${context} — revisa el trabajador de la cola`,
			action: 'Ver instancia',
			packageId: pkg.id
		}
	];
}

/**
 * Solo de las instancias activas: un corte manual, o por impago, no se cura bajando una cuota, así
 * que en las demás el contador ya no es lo que decide.
 */
function quotaItem(pkg: CommanderPackage, situation: Situation, context: string): AttentionItem[] {
	if (situation !== Situation.Live || pkg.status !== PackageStatus.Active) return [];
	const worst = worstMeter(pkg.quotas ?? []);
	if (worst === null || worst.ratio === null || worst.ratio <= WARN_THRESHOLD) return [];
	return [
		{
			id: `quota:${pkg.id}:${worst.key}`,
			tone: worst.tone,
			title: `Contador al ${worst.label} · ${worst.key}`,
			body: `${context} — política ${worst.onExceed}`,
			action: 'Contadores',
			packageId: pkg.id
		}
	];
}

/** Cobro y suministro se leen juntos, pero son campos distintos: esto mira solo el cobro. */
export function overdueItems(invoices: readonly CommanderInvoice[], now: number): AttentionItem[] {
	return invoices
		.filter((invoice) => invoice.status === BillingStatus.Overdue)
		.map((invoice) => {
			const late = invoice.due_at
				? Math.round((now - new Date(invoice.due_at).getTime()) / 86_400_000)
				: 0;
			return {
				id: `invoice:${invoice.id}`,
				tone: Tone.Error,
				title: `Factura vencida ${invoice.number}`,
				body: `${eur(Number(invoice.amount))} · ${late} días de retraso`,
				action: 'Cobro',
				invoiceId: invoice.id
			};
		});
}
