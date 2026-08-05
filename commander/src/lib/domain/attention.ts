import { Tone } from '$lib/model/enums';
import type { CommanderInvoice, CommanderPackage } from '$lib/model/types';
import { overdueItems, packageItems } from './attention-rules';

export interface AttentionItem {
	id: string;
	tone: Tone;
	title: string;
	/** Contexto en la lengua del sistema: nombre, cliente, motivo. */
	body: string;
	/** El mando que resuelve, nombrado por lo que hace. */
	action: string;
	packageId?: number;
	invoiceId?: number;
}

export interface AttentionSource {
	packages: readonly CommanderPackage[];
	invoices: readonly CommanderInvoice[];
	now: number;
	/** Lo que el operador ya marcó como visto. */
	seen?: ReadonlySet<string>;
}

const TONE_ORDER = [Tone.Error, Tone.Warn, Tone.Info, Tone.Ok, Tone.Neutral];

/**
 * La cola de trabajo del operador.
 *
 * No es un centro de notificaciones: es la lista de lo que **exige una decisión**, cada línea con
 * su acción directa. Se deriva de los datos en cada lectura, así que sobrevive a la recarga sin
 * guardar nada — lo único que se persiste es qué se descartó.
 *
 * Las reglas viven en `attention-rules.ts`; aquí solo se juntan y se ordenan por urgencia.
 */
export function attentionItems(source: AttentionSource): AttentionItem[] {
	const items = [
		...source.packages.flatMap((pkg) => packageItems(pkg, source.now)),
		...overdueItems(source.invoices, source.now)
	];

	return items
		.filter((item) => !source.seen?.has(item.id))
		.sort((a, b) => TONE_ORDER.indexOf(a.tone) - TONE_ORDER.indexOf(b.tone));
}
