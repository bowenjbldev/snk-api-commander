<script lang="ts">
	import { ControlSize } from './kinds';

	export interface SelectOption {
		value: string;
		label: string;
	}

	interface Props {
		/** Opcional: el componente arranca vacío si nadie lo ata con `bind:value`. */
		value?: string;
		options: SelectOption[];
		size?: ControlSize;
		/** Cuando las opciones SON el dato —`requests_day`, `suspend`, `25`— y no palabra nuestra. */
		mono?: boolean;
		disabled?: boolean;
		id?: string;
		title?: string;
		width?: string;
	}

	let {
		value = $bindable(''),
		options,
		size = ControlSize.Compact,
		mono = false,
		disabled = false,
		id,
		title,
		width
	}: Props = $props();
</script>

<select {id} {disabled} {title} bind:value class:mono data-size={size} style:width>
	{#each options as option (option.value)}
		<option value={option.value}>{option.label}</option>
	{/each}
</select>

<style>
	select[data-size='regular'] {
		height: var(--h-row);
	}
	select[data-size='compact'] {
		height: var(--h-ctl);
	}
	/* A 24 el aire lateral se recorta: es el selector del paginador, pegado a los números. */
	select[data-size='dense'] {
		height: var(--h-ctl-dense);
		padding: 0 4px;
	}

	select {
		padding: 0 6px;
		border: 1px solid var(--bd);
		border-radius: var(--r);
		background: var(--bg-card);
		color: var(--tx);
		font-size: var(--fs-body);
		transition: border-color var(--t-fast) var(--ease);
	}

	/* El mono baja un punto: una opción que es un identificador ocupa más y no compite con el
	   rótulo de al lado. */
	select.mono {
		font-size: var(--fs-small);
	}

	select:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}
</style>
