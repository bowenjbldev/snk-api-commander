<script lang="ts">
	import { SupplyShape } from '$lib/model/enums';
	import type { Supply } from './supply';

	interface Props {
		supply: Supply;
		/** 22 en una fila de tabla, 14 en una leyenda, estirado en una alerta. */
		height?: number | 'stretch';
	}

	const { supply, height = 22 }: Props = $props();
</script>

<!-- La capa que se lee primero. La etiqueta de texto sigue existiendo en la cabecera de detalle,
	 en la bandeja y para el lector de pantalla; esto no es la única señal. -->
<span
	class="line"
	data-shape={supply.shape}
	data-tone={supply.tone}
	title={supply.label}
	aria-hidden="true"
	style:height={height === 'stretch' ? undefined : `${height}px`}
	class:stretch={height === 'stretch'}
>
	{#if supply.shape === SupplyShape.Shrink}
		<span class="fill" style:height="{(supply.ratio * 100).toFixed(1)}%"></span>
	{/if}
</span>

<style>
	/* Codifica el suministro por COLOR y por FORMA: funciona con visión de color reducida y en
	   escala de grises. Y no consume ancho en una tabla que ya va justa. */
	.line {
		display: block;
		flex: 0 0 3px;
		width: 3px;
		border-radius: var(--r-line);
		overflow: hidden;
		transition: background var(--t-supply) var(--ease);
	}

	.line.stretch {
		align-self: stretch;
	}

	/* Llena: conectada. */
	.line[data-shape='solid'] {
		background: var(--ok);
	}

	/* Segmento que MENGUA hasta el corte: el preaviso deja de ser un número y se ve encogerse. */
	.line[data-shape='shrink'] {
		background: var(--wn-bg);
	}

	.fill {
		display: block;
		width: 3px;
		background: var(--wn);
		transition: height 1s linear;
	}

	/* Llegó a cero: ese cliente ya no está servido aunque la columna siga diciendo `grace`. */
	.line[data-shape='expired'] {
		background: var(--er-bg);
		box-shadow: inset 0 0 0 1px var(--er);
	}

	/* Hueca: cortada. */
	.line[data-shape='hollow'] {
		background: transparent;
		box-shadow: inset 0 0 0 1px var(--er);
	}

	/* Punteada: alta en curso, o atascada si lleva demasiado. */
	.line[data-shape='dotted'] {
		background: repeating-linear-gradient(to bottom, var(--in) 0 3px, transparent 3px 6px);
	}

	.line[data-shape='dotted'][data-tone='error'] {
		background: repeating-linear-gradient(to bottom, var(--er) 0 3px, transparent 3px 6px);
	}

	/* Sin línea: no hay suministro que representar. Ocupa su hueco para que la rejilla cuadre. */
	.line[data-shape='none'] {
		background: transparent;
	}
</style>
