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
	/**
	 * Cuando una demo enseña un compuesto entero —una tabla con sus nueve piezas—, los ficheros
	 * hermanos que documenta. Sin esto se asume que `Button.demo.svelte` documenta `Button.svelte`.
	 */
	components?: string[];
}

/** Qué es cada sección, para quien llega sin contexto. */
export const GROUP_NOTES: Record<DemoGroup, string> = {
	[DemoGroup.Foundations]: 'Color, tipografía y medidas. Todo lo demás sale de aquí.',
	[DemoGroup.Actions]: 'Lo que se pulsa. Una sola acción primaria por pantalla.',
	[DemoGroup.Fields]: 'Lo que se rellena. El rótulo va dentro de la opción siempre que quepa.',
	[DemoGroup.Data]: 'Lo que se lee sin tocarlo: estados, cifras, muestras y leyendas.',
	[DemoGroup.Surfaces]: 'Los planos donde se apoya el contenido.',
	[DemoGroup.Layers]: 'Lo que flota por encima y hay que cerrar para seguir.',
	[DemoGroup.Charts]: 'ECharts para las de tarjeta; SVG propio para lo que va por fila.',
	[DemoGroup.Domain]: 'Lo que ningún sistema trae hecho y hubo que construir para esta consola.'
};

export interface DemoEntry extends DemoMeta {
	component: Component;
	/** Ruta del fichero, para poder ir a leerlo. */
	source: string;
}
