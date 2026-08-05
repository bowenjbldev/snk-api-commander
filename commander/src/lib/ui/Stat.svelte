<script lang="ts">
	import { Tone } from '$lib/model/enums';

	interface Props {
		label: string;
		/** La cifra. Siempre mono y tabular: es una lectura. */
		value: string;
		/** Cifra del apunte de abajo, en mono. */
		subValue?: string;
		/** Palabra del apunte, en la familia de interfaz. */
		subText?: string;
		tone?: Tone;
		/** Una lectura en cero no compite por la atención: la cifra se apaga aunque tenga tono. */
		dim?: boolean;
		/** Con `onclick` la lectura lleva a la pantalla que la explica. */
		onclick?: () => void;
		hint?: string;
	}

	const {
		label,
		value,
		subValue,
		subText,
		tone = Tone.Neutral,
		dim = false,
		onclick,
		hint
	}: Props = $props();
</script>

<svelte:element
	this={onclick ? 'button' : 'div'}
	class="stat"
	role={onclick ? 'button' : undefined}
	type={onclick ? 'button' : undefined}
	title={hint}
	{onclick}
>
	<div class="top">
		<span class="label">{label}</span>
		<div class="spacer"></div>
		{#if onclick}<span class="chevron">›</span>{/if}
	</div>
	<div class="mono value" class:dim data-tone={tone}>{value}</div>
	{#if subValue ?? subText}
		<div class="sub">
			{#if subValue}<span class="mono">{subValue}</span>{/if}
			{#if subText}<span>{subText}</span>{/if}
		</div>
	{/if}
</svelte:element>

<style>
	.stat {
		display: block;
		width: 100%;
		padding: 10px 12px;
		border: 1px solid var(--bd2);
		border-radius: var(--r);
		background: var(--bg-card);
		text-align: left;
	}

	/* La que se pulsa lleva apunte debajo y respira un punto más por abajo que por arriba. */
	button.stat {
		padding: 9px 12px 10px;
	}

	button.stat:hover {
		border-color: var(--bd);
		background: var(--bg-hover);
	}

	.top {
		display: flex;
		align-items: center;
		gap: 6px;
	}

	.label {
		font-size: var(--fs-micro);
		color: var(--tx2);
		text-transform: uppercase;
		letter-spacing: var(--ls-caps);
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.spacer {
		flex: 1;
	}

	.chevron {
		flex: 0 0 auto;
		font-size: var(--fs-body);
		line-height: 1;
		color: var(--tx3);
	}

	.value {
		margin-top: 2px;
		font-size: var(--fs-read);
		line-height: 1.25;
		color: var(--tx);
	}

	.value[data-tone='ok'] {
		color: var(--ok);
	}
	.value[data-tone='warn'] {
		color: var(--wn);
	}
	.value[data-tone='error'] {
		color: var(--er);
	}
	.value[data-tone='info'] {
		color: var(--in);
	}

	/* Después de los tonos: apagar gana sobre teñir, porque un cero no alarma de nada. */
	.value.dim {
		color: var(--tx3);
	}

	.sub {
		display: flex;
		align-items: baseline;
		gap: 4px;
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
