import { n } from '$lib/format';

/** Los cuatro tamaños de página. No hay campo libre: es un selector. */
export enum PageSize {
	Ten = 10,
	TwentyFive = 25,
	Fifty = 50,
	Hundred = 100
}

export interface PagerView<T> {
	readonly slice: T[];
	readonly page: number;
	readonly pages: number;
	readonly size: number;
	readonly total: number;
	/** `1–10 de 31`, o `sin filas` cuando no hay nada que contar. */
	readonly range: string;
	/** La ventana de cinco números alrededor de la página actual. */
	readonly numbers: number[];
	goto(page: number): void;
	prev(): void;
	next(): void;
	setSize(size: number): void;
}

const WINDOW = 5;

/**
 * Paginación en el navegador, que es la única posible: ningún listado de la API acepta `page` ni
 * `per_page` — devuelven la tabla entera.
 *
 * `source` es una función y no un array para que el paginador siga vivo cuando cambian los
 * filtros: recalcula, y si la página se quedó fuera de rango se ajusta sola en vez de enseñar el
 * vacío.
 */
export function createPager<T>(source: () => readonly T[], initial: PageSize = PageSize.Ten) {
	let requested = $state(1);
	let size = $state<number>(initial);

	const total = $derived(source().length);
	const pages = $derived(Math.max(1, Math.ceil(total / size)));
	const page = $derived(Math.min(Math.max(1, requested), pages));
	const from = $derived((page - 1) * size);

	const numbers = $derived.by(() => {
		const start = Math.max(1, Math.min(page - 2, pages - WINDOW + 1));
		const end = Math.min(pages, start + WINDOW - 1);
		return Array.from({ length: end - start + 1 }, (_, i) => start + i);
	});

	const range = $derived(
		total ? `${n(from + 1)}–${n(Math.min(total, from + size))} de ${n(total)}` : 'sin filas'
	);

	const view: PagerView<T> = {
		get slice() {
			return source().slice(from, from + size);
		},
		get page() {
			return page;
		},
		get pages() {
			return pages;
		},
		get size() {
			return size;
		},
		get total() {
			return total;
		},
		get range() {
			return range;
		},
		get numbers() {
			return numbers;
		},
		goto: (next) => {
			requested = next;
		},
		prev: () => {
			requested = page - 1;
		},
		next: () => {
			requested = page + 1;
		},
		setSize: (next) => {
			size = next;
			requested = 1;
		}
	};

	return view;
}
