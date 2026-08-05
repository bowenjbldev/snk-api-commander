import { browser } from '$app/environment';

let current = $state(Date.now());
let timer: ReturnType<typeof setInterval> | null = null;
let subscribers = 0;

/**
 * Un solo reloj para toda la consola.
 *
 * Las cuentas atrás actualizan a 1 Hz desde aquí, no cada una por su cuenta: así las tablas no se
 * repintan cada segundo por treinta temporizadores distintos. Lo único vivo de esta consola es el
 * paso del tiempo — los datos llevan minutos de retraso y decir «ahora mismo» sería mentir.
 */
export const clock = {
	get now(): number {
		return current;
	},

	/** Arranca el reloj mientras alguien lo mire. Devuelve la baja. */
	subscribe(): () => void {
		if (!browser) return () => {};
		subscribers++;
		timer ??= setInterval(() => {
			current = Date.now();
		}, 1000);

		return () => {
			subscribers--;
			if (subscribers === 0 && timer !== null) {
				clearInterval(timer);
				timer = null;
			}
		};
	}
};
