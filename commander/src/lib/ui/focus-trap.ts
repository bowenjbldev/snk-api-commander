const FOCUSABLE =
	'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

/**
 * Encierra el foco dentro de una capa y lo devuelve al salir.
 *
 * Una capa que deja el foco detrás es una capa que el teclado no puede usar y que el lector de
 * pantalla sigue leyendo por debajo del velo.
 */
export function trapFocus(node: HTMLElement) {
	const previous = document.activeElement as HTMLElement | null;

	const focusable = (): HTMLElement[] => [...node.querySelectorAll<HTMLElement>(FOCUSABLE)];

	// El primer control, o la propia capa si no hay ninguno.
	(focusable()[0] ?? node).focus();

	function onKeydown(event: KeyboardEvent): void {
		if (event.key !== 'Tab') return;
		const items = focusable();
		if (items.length === 0) return;
		const first = items[0];
		const last = items[items.length - 1];
		if (!first || !last) return;

		if (event.shiftKey && document.activeElement === first) {
			event.preventDefault();
			last.focus();
		} else if (!event.shiftKey && document.activeElement === last) {
			event.preventDefault();
			first.focus();
		}
	}

	node.addEventListener('keydown', onKeydown);

	return {
		destroy(): void {
			node.removeEventListener('keydown', onKeydown);
			previous?.focus();
		}
	};
}
