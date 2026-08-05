<script lang="ts">
	import { ControlSize } from './kinds';

	interface Props {
		/** Opcional: el componente arranca vacío si nadie lo ata con `bind:value`. */
		value?: string;
		placeholder?: string;
		type?: 'text' | 'email' | 'password' | 'search';
		/** Lo que el operador escribe contra un dato del sistema —slug, id, ruta— va en mono. */
		mono?: boolean;
		size?: ControlSize;
		readonly?: boolean;
		disabled?: boolean;
		invalid?: boolean;
		id?: string;
		width?: string;
	}

	let {
		value = $bindable(''),
		placeholder,
		type = 'text',
		mono = false,
		size = ControlSize.Compact,
		readonly = false,
		disabled = false,
		invalid = false,
		id,
		width
	}: Props = $props();
</script>

<input
	{id}
	{type}
	{placeholder}
	{readonly}
	{disabled}
	bind:value
	class:mono
	data-size={size}
	aria-invalid={invalid || undefined}
	style:width
/>

<style>
	input[data-size='regular'] {
		height: var(--h-row);
	}
	input[data-size='compact'] {
		height: var(--h-ctl);
	}
	input[data-size='dense'] {
		height: var(--h-ctl-dense);
	}

	input {
		padding: 0 8px;
		border: 1px solid var(--bd);
		border-radius: var(--r);
		background: var(--bg-card);
		color: var(--tx);
		font-size: var(--fs-body);
		transition: border-color var(--t-fast) var(--ease);
	}

	/* El marcador de posición dice qué se espera, así que tiene que leerse: --tx4 es el gris de una
	   línea inerte, no el de un texto. */
	input::placeholder {
		color: var(--tx3);
	}

	input[aria-invalid] {
		border-color: var(--er);
	}

	/* De solo lectura no es lo mismo que deshabilitado: el dato SÍ importa —se copia, se compara—,
	   solo que lo escribe el sistema. Por eso baja de fondo, no de opacidad. */
	input[readonly] {
		background: var(--bg-fill2);
		color: var(--tx2);
	}

	input:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}
</style>
