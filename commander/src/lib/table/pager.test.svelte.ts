import { describe, expect, it } from 'vitest';
import { createPager, PageSize } from './pager.svelte';

const rows = (count: number): number[] => Array.from({ length: count }, (_, i) => i + 1);

describe('createPager', () => {
	it('recorta la página pedida y cuenta el rango', () => {
		const pager = createPager(() => rows(31));
		expect(pager.slice).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
		expect(pager.range).toBe('1–10 de 31');
		expect(pager.pages).toBe(4);
	});

	it('avanza y retrocede sin salirse por los extremos', () => {
		const pager = createPager(() => rows(31));
		pager.prev();
		expect(pager.page).toBe(1);

		pager.next();
		expect(pager.slice[0]).toBe(11);
		expect(pager.range).toBe('11–20 de 31');

		pager.goto(99);
		expect(pager.page).toBe(4);
		expect(pager.slice).toEqual([31]);
		pager.next();
		expect(pager.page).toBe(4);
	});

	it('dice que no hay filas en vez de contar cero', () => {
		const pager = createPager(() => rows(0));
		expect(pager.range).toBe('sin filas');
		expect(pager.pages).toBe(1);
		expect(pager.slice).toEqual([]);
	});

	// El caso que rompe un paginador ingenuo: estar en la página 4 y que un filtro deje 3 filas.
	// `$state` porque así llega en la pantalla real: el origen lee de los filtros, que son runas.
	it('se reajusta solo cuando la lista encoge bajo los pies', () => {
		let size = $state(31);
		const pager = createPager(() => rows(size));
		pager.goto(4);
		expect(pager.page).toBe(4);

		size = 3;
		expect(pager.page).toBe(1);
		expect(pager.slice).toEqual([1, 2, 3]);
		expect(pager.range).toBe('1–3 de 3');
	});

	it('cambiar el tamaño devuelve a la primera página', () => {
		const pager = createPager(() => rows(200));
		pager.goto(5);
		pager.setSize(PageSize.Fifty);
		expect(pager.page).toBe(1);
		expect(pager.size).toBe(50);
		expect(pager.pages).toBe(4);
	});

	it('la ventana de números tiene cinco y encierra a la página actual', () => {
		const pager = createPager(() => rows(200));
		expect(pager.numbers).toEqual([1, 2, 3, 4, 5]);

		pager.goto(10);
		expect(pager.numbers).toEqual([8, 9, 10, 11, 12]);

		pager.goto(20);
		expect(pager.numbers).toEqual([16, 17, 18, 19, 20]);
	});
});
