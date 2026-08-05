import type { Component } from 'svelte';

/**
 * El vocabulario de la guía, aparte del registro a propósito.
 *
 * `registry.ts` importa todas las demos con un glob eager, y cada demo declara su grupo. Si el
 * enum viviera allí habría un ciclo —registro → demo → registro— y el cuerpo de la demo correría
 * antes de que el enum existiera. Aquí no importa nada, así que no puede haber ciclo.
 */

/**
 * Las secciones. El orden de este enum es el orden en que se muestran: de los cimientos hacia
 * arriba, terminando en lo que este sistema no trae hecho y hay que construir.
 */
export enum DemoGroup {
	Foundations = 'Cimientos',
	Actions = 'Acciones',
	Fields = 'Campos',
	Data = 'Datos',
	Surfaces = 'Superficies',
	Layers = 'Capas',
	Charts = 'Gráficas',
	Domain = 'Dominio'
}

export interface DemoMeta {
	group: DemoGroup;
	name: string;
	/** Una línea sobre qué decide este componente. No es documentación de API. */
	notes?: string;
}

export interface DemoEntry extends DemoMeta {
	component: Component;
	/** Ruta del fichero, para poder ir a leerlo. */
	source: string;
}
