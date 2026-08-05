<script lang="ts">
	interface Props {
		checked?: boolean;
		/** Alguna sí y alguna no: la cabecera de una tabla con selección parcial. */
		mixed?: boolean;
		disabled?: boolean;
		title?: string;
		label: string;
		/** Para selecciones que viven en un conjunto, donde `bind:checked` no encaja. */
		onchange?: (checked: boolean) => void;
	}

	let {
		checked = $bindable(false),
		mixed = false,
		disabled = false,
		title,
		label,
		onchange
	}: Props = $props();
</script>

<button
	type="button"
	role="checkbox"
	aria-label={label}
	aria-checked={mixed ? 'mixed' : checked}
	{disabled}
	{title}
	onclick={() => {
		checked = !checked;
		onchange?.(checked);
	}}
>
	<span class="box">{mixed ? '–' : checked ? '✓' : ''}</span>
</button>

<style>
	button {
		height: var(--h-row);
		padding: 0;
		border: 0;
		background: transparent;
		display: grid;
		place-items: center;
	}

	button:disabled {
		cursor: not-allowed;
	}

	/* La casilla vive dentro de una fila que ya tiene fondo propio —seleccionada, al apuntar—, así
	   que apagada es transparente: pintarla de blanco la recortaría del resto de la fila. */
	.box {
		display: grid;
		place-items: center;
		width: 12px;
		height: 12px;
		border: 1px solid var(--bd);
		border-radius: var(--r-tag);
		background: transparent;
		color: var(--on-pri);
		font-size: 9px;
		line-height: 1;
	}

	/* Una fila que no admite selección no se tacha ni se pinta: la casilla se apaga hasta el borde
	   de fondo y baja de opacidad, y el ojo la salta al recorrer la columna. */
	button:disabled .box {
		border-color: var(--bd2);
		opacity: 0.3;
	}

	button[aria-checked='true'] .box,
	button[aria-checked='mixed'] .box {
		background: var(--pri);
		border-color: var(--pri);
	}
</style>
