import { Tone } from '$lib/model/enums';

export interface Toast {
	id: number;
	text: string;
	tone: Tone;
}

const LIFETIME = 4000;

let items = $state<Toast[]>([]);
let sequence = 0;

/**
 * Avisos efímeros.
 *
 * Se reservan para **confirmar lo que el operador acaba de hacer**. Un corte automático o un alta
 * fallida NO son un aviso efímero: van a la bandeja de atención, que persiste y exige decisión.
 */
export const toasts = {
	get items(): Toast[] {
		return items;
	},

	push(text: string, tone: Tone = Tone.Neutral): void {
		const id = ++sequence;
		items = [...items, { id, text, tone }];
		setTimeout(() => {
			toasts.dismiss(id);
		}, LIFETIME);
	},

	dismiss(id: number): void {
		items = items.filter((toast) => toast.id !== id);
	}
};
