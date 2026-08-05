import { assertNever } from '$lib/model/assert-never';
import { PackageStatus, Situation, SupplyShape, Tone } from '$lib/model/enums';
import type { SupplyInput } from '$lib/model/situation';

export interface Supply {
	shape: SupplyShape;
	tone: Tone;
	/** Nuestra palabra para el estado, no el valor del sistema. */
	label: string;
	/** Cuánto queda del segmento, de 1 a 0. Solo significa algo con `SupplyShape.Shrink`. */
	ratio: number;
}

/** Si el preaviso no dice cuánto duraba, se asume la duración por defecto de la plataforma. */
const DEFAULT_GRACE_MS = 72 * 60 * 60 * 1000;

/**
 * El estado del suministro de una instancia, listo para dibujar.
 *
 * Devuelve **forma y tono**, no colores: el color lo pone el CSS del componente. Así la lógica se
 * prueba sin DOM y ningún hex se escapa a un fichero de TypeScript.
 *
 * Aquí vive **el cuarto estado, el que no está en los datos**: cuando vence la fecha límite del
 * preaviso, el backend trata la instancia como cortada aunque la columna siga diciendo `grace`.
 * Ningún proceso reescribe ese campo, así que lo deduce el reloj.
 */
export function supply(input: SupplyInput, now: number): Supply {
	switch (input.situation) {
		case Situation.Catalog:
			return { shape: SupplyShape.None, tone: Tone.Neutral, label: 'Solo catálogo', ratio: 0 };
		case Situation.Gone:
			return { shape: SupplyShape.None, tone: Tone.Neutral, label: 'Dada de baja', ratio: 0 };
		case Situation.Undeployed:
			return { shape: SupplyShape.None, tone: Tone.Neutral, label: 'Sin desplegar', ratio: 0 };
		case Situation.Unregistered:
			return { shape: SupplyShape.None, tone: Tone.Neutral, label: 'Sin dar de alta', ratio: 0 };
		case Situation.Pending:
			return { shape: SupplyShape.Dotted, tone: Tone.Info, label: 'Provisionando', ratio: 0 };
		case Situation.Stalled:
			return { shape: SupplyShape.Dotted, tone: Tone.Error, label: 'Alta atascada', ratio: 0 };
		case Situation.Live:
			return live(input, now);
		default:
			return assertNever(input.situation, 'situación');
	}
}

function live(input: SupplyInput, now: number): Supply {
	switch (input.status) {
		case PackageStatus.Suspended:
			return { shape: SupplyShape.Hollow, tone: Tone.Error, label: 'Cortada', ratio: 0 };
		case PackageStatus.Grace: {
			const until = input.graceUntil;
			// Sin fecha límite no hay preaviso que representar: se lee como conectada, que es lo
			// que el cliente percibe.
			if (until === null) {
				return { shape: SupplyShape.Solid, tone: Tone.Ok, label: 'Conectada', ratio: 1 };
			}
			const left = until - now;
			if (left <= 0) {
				return {
					shape: SupplyShape.Expired,
					tone: Tone.Error,
					label: 'Preaviso caducado',
					ratio: 0
				};
			}
			const total = input.graceStartedAt === null ? DEFAULT_GRACE_MS : until - input.graceStartedAt;
			const ratio = Math.max(0.02, Math.min(1, left / (total || DEFAULT_GRACE_MS)));
			return { shape: SupplyShape.Shrink, tone: Tone.Warn, label: 'En preaviso', ratio };
		}
		case PackageStatus.Active:
			return { shape: SupplyShape.Solid, tone: Tone.Ok, label: 'Conectada', ratio: 1 };
		default:
			return assertNever(input.status, 'estado de suministro');
	}
}

/** Orden por urgencia: lo que exige decisión primero, lo sano al final. */
const URGENCY: Record<SupplyShape, number> = {
	[SupplyShape.Expired]: 0,
	[SupplyShape.Hollow]: 1,
	[SupplyShape.Shrink]: 2,
	[SupplyShape.Dotted]: 3,
	[SupplyShape.Solid]: 4,
	[SupplyShape.None]: 5
};

/**
 * Ordena por urgencia y, dentro del preaviso, por lo que queda: el que corta antes va primero.
 * Un producto declarado hace cuarenta y cinco días sube por encima de veinte instancias sanas,
 * porque es trabajo pendiente y ellas no.
 */
export function byUrgency(a: Supply, b: Supply): number {
	return URGENCY[a.shape] - URGENCY[b.shape] || a.ratio - b.ratio;
}
