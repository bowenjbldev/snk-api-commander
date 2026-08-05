import type { Component } from 'svelte';
import { DemoGroup, type DemoEntry, type DemoMeta } from './demo-meta';

export { DemoGroup };
export type { DemoEntry, DemoMeta };

interface DemoModule {
	default: Component;
	meta?: DemoMeta;
}

/**
 * Los ejemplos viven junto a la primitiva, no aquí: crear un `Button.demo.svelte` lo hace
 * aparecer en la guía sin tocar ningún índice, y borrarlo lo quita. `ds-check` exige que toda
 * primitiva tenga el suyo, así que la guía no puede quedarse atrás.
 */
const modules = import.meta.glob<DemoModule>('/src/lib/**/*.demo.svelte', { eager: true });

const GROUP_ORDER = Object.values(DemoGroup);

export const demos: DemoEntry[] = Object.entries(modules)
	.flatMap(([source, module]) =>
		module.meta ? [{ ...module.meta, component: module.default, source }] : []
	)
	.sort(
		(a, b) =>
			GROUP_ORDER.indexOf(a.group) - GROUP_ORDER.indexOf(b.group) ||
			a.name.localeCompare(b.name, 'es')
	);

export interface DemoSection {
	group: DemoGroup;
	entries: DemoEntry[];
}

/** Solo los grupos que tienen algo dentro: una sección vacía es ruido, no información. */
export const sections: DemoSection[] = GROUP_ORDER.flatMap((group) => {
	const entries = demos.filter((d) => d.group === group);
	return entries.length ? [{ group, entries }] : [];
});

/** Ancla estable para el índice lateral: minúsculas y sin diacríticos. */
export function anchorFor(group: DemoGroup): string {
	return group
		.toLowerCase()
		.normalize('NFD')
		.replace(/[\u0300-\u036f]/g, '');
}
