import type { PackageStatus } from './enums';
import { Situation } from './enums';
import type { CommanderPackage } from './types';

/** Un pendiente de dos minutos es normal; uno de dos días es una avería. */
export const STALLED_AFTER_MS = 6 * 60 * 60 * 1000;

/** Lo mínimo que la línea de suministro necesita saber. Sin esto, `supply` tocaría toda la fila. */
export interface SupplyInput {
	situation: Situation;
	status: PackageStatus;
	/** Instante del corte previsto, en ms. */
	graceUntil: number | null;
	/** Cuándo se abrió el preaviso, para saber cuánto ha menguado ya. */
	graceStartedAt: number | null;
}

function ms(iso: string | null): number | null {
	if (!iso) return null;
	const value = new Date(iso).getTime();
	return Number.isNaN(value) ? null : value;
}

/**
 * Qué ES una fila de `commander_packages`, mirando cliente, alta y reloj a la vez.
 *
 * Las dos situaciones del contrato de catálogo —«sin desplegar» y «sin dar de alta»— no se pueden
 * deducir todavía: el backend no tiene `source` ni `deployed`, y `GET packages` crea la fila al
 * sincronizar desde disco. Cuando esos campos existan, se deducen aquí y solo aquí.
 */
export function situationOf(pkg: CommanderPackage, now: number): Situation {
	if (pkg.provisioned_at) return Situation.Live;
	if (pkg.client_id !== null) {
		const created = ms(pkg.created_at);
		const stalled = created !== null && now - created > STALLED_AFTER_MS;
		return stalled ? Situation.Stalled : Situation.Pending;
	}
	return Situation.Catalog;
}

export function toSupplyInput(pkg: CommanderPackage, now: number): SupplyInput {
	return {
		situation: situationOf(pkg, now),
		status: pkg.status,
		graceUntil: ms(pkg.grace_until),
		// `changed_at` es cuando se abrió el preaviso: el largo total del segmento sale de ahí.
		graceStartedAt: ms(pkg.changed_at)
	};
}

/** El nombre que se enseña. Un producto sin rótulo comercial cae a su nombre técnico. */
export function displayName(pkg: CommanderPackage): string {
	return pkg.label ?? pkg.slug ?? pkg.name;
}
