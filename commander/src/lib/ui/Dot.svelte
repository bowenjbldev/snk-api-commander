<script lang="ts">
	import { Tone } from '$lib/model/enums';

	interface Props {
		tone?: Tone;
		/** Hueco: lo hizo el sistema solo. Lleno: lo decidió una persona. */
		hollow?: boolean;
		title?: string;
	}

	const { tone = Tone.Neutral, hollow = false, title }: Props = $props();
</script>

<span class="dot" data-tone={tone} class:hollow {title}></span>

<style>
	/* El anillo va por dentro para que lleno y hueco ocupen exactamente lo mismo y la columna de
	   la bitácora no baile un píxel entre una línea y la siguiente. */
	.dot {
		display: block;
		width: 7px;
		height: 7px;
		border-radius: 50%;
		background: var(--tx3);
		box-shadow: inset 0 0 0 1.4px var(--tx3);
	}

	.dot[data-tone='ok'] {
		background: var(--ok);
		box-shadow: inset 0 0 0 1.4px var(--ok);
	}
	.dot[data-tone='warn'] {
		background: var(--wn);
		box-shadow: inset 0 0 0 1.4px var(--wn);
	}
	.dot[data-tone='error'] {
		background: var(--er);
		box-shadow: inset 0 0 0 1.4px var(--er);
	}
	.dot[data-tone='info'] {
		background: var(--in);
		box-shadow: inset 0 0 0 1.4px var(--in);
	}

	/* Después de los tonos: vaciar gana sobre teñir, y el anillo conserva el tono. */
	.dot.hollow {
		background: var(--bg-card);
	}
</style>
