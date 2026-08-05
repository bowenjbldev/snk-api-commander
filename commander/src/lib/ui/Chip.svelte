<script lang="ts">
	import { Tone } from '$lib/model/enums';

	interface Props {
		label: string;
		/** La cifra va en mono; el rótulo no. Son dos cosas distintas dentro del mismo control. */
		count?: number | string;
		tone?: Tone;
		/** Sin suministro que representar: la marca se apaga hasta el borde. */
		muted?: boolean;
		selected?: boolean;
		onclick?: () => void;
	}

	const {
		label,
		count,
		tone = Tone.Neutral,
		muted = false,
		selected = false,
		onclick
	}: Props = $props();
</script>

<button type="button" {onclick} data-tone={tone} class:muted class:selected aria-pressed={selected}>
	<span class="mark"></span>
	<span>{label}</span>
	{#if count !== undefined}<span class="mono count">{count}</span>{/if}
</button>

<style>
	button {
		display: inline-flex;
		align-items: center;
		gap: 7px;
		height: var(--h-ctl);
		padding: 0 10px;
		border: 1px solid var(--bd);
		border-radius: var(--r);
		background: var(--bg-card);
		color: var(--tx);
		font-size: var(--fs-body);
	}

	/* El plano de selección, no el primario: un filtro encendido no es una acción. */
	button.selected {
		border-color: var(--pri-bd);
		background: var(--bg-sel);
	}

	/* Marca de 3 px: el mismo grosor que la línea de suministro de la tabla, para que el filtro y
	   lo que filtra se lean como la misma cosa. */
	.mark {
		width: 3px;
		height: 12px;
		border-radius: var(--r-line);
		background: var(--tx4);
	}

	button[data-tone='ok'] .mark {
		background: var(--ok);
	}
	button[data-tone='warn'] .mark {
		background: var(--wn);
	}
	button[data-tone='error'] .mark {
		background: var(--er);
	}
	button[data-tone='info'] .mark {
		background: var(--in);
	}

	/* Después de los tonos a propósito: apagar gana sobre teñir. */
	button.muted .mark {
		background: var(--bd);
	}

	.count {
		color: var(--tx2);
	}
</style>
