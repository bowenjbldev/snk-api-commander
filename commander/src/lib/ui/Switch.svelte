<script lang="ts">
	interface Props {
		/** Opcional: arranca apagado si nadie lo ata con `bind:checked`. */
		checked?: boolean;
		label: string;
		note?: string;
		disabled?: boolean;
	}

	let { checked = $bindable(false), label, note, disabled = false }: Props = $props();
</script>

<div class="row">
	<button
		type="button"
		role="switch"
		aria-label={label}
		aria-checked={checked}
		{disabled}
		onclick={() => (checked = !checked)}
	>
		<span class="knob"></span>
	</button>
	<div class="text">
		<div class="label">{label}</div>
		{#if note}<div class="note">{note}</div>{/if}
	</div>
</div>

<style>
	.row {
		display: flex;
		align-items: center;
		gap: 10px;
	}

	button {
		position: relative;
		flex: 0 0 32px;
		width: 32px;
		height: 18px;
		padding: 0;
		border: 1px solid var(--bd);
		border-radius: 9px;
		background: var(--bg-fill2);
		transition:
			background var(--t-fast) var(--ease),
			border-color var(--t-fast) var(--ease);
	}

	button[aria-checked='true'] {
		background: var(--pri);
		border-color: var(--pri);
	}

	button:disabled {
		opacity: 0.5;
		cursor: not-allowed;
	}

	/* La perilla se mueve por `left`, no por `transform`: apagada es un punto gris sobre el riel
	   —el mismo gris de una nota— y encendida se recorta en blanco sobre el primario. */
	.knob {
		position: absolute;
		top: 1px;
		left: 1px;
		width: 14px;
		height: 14px;
		border-radius: 50%;
		background: var(--tx3);
		transition: left var(--t-fast) var(--ease);
	}

	button[aria-checked='true'] .knob {
		left: 15px;
		background: var(--on-pri);
	}

	.label {
		font-size: var(--fs-body);
	}

	.note {
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
