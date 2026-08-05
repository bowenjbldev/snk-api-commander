<script lang="ts">
	import { eur, k, pct } from '$lib/format';
	import { Tone } from '$lib/model/enums';
	import { navigate, Route } from '$lib/nav/routes';
	import Stat from '$lib/ui/Stat.svelte';

	interface Props {
		live: number;
		notice: number;
		cut: number;
		requests: number;
		errors: number;
		mrr: number;
	}

	const { live, notice, cut, requests, errors, mrr }: Props = $props();

	const toFleet = (): void => {
		navigate(Route.Fleet);
	};
</script>

<!-- Responde «¿tengo que actuar?» en tres segundos. Las tres primeras llevan a la Flota ya
	 filtrada: la lectura y la pantalla que la explica son la misma cosa. -->
<div class="stats">
	<Stat label="Instancias vivas" value={String(live)} tone={Tone.Ok} onclick={toFleet} />
	<Stat
		label="En preaviso"
		value={String(notice)}
		tone={notice ? Tone.Warn : Tone.Neutral}
		onclick={toFleet}
	/>
	<Stat
		label="Cortadas"
		value={String(cut)}
		tone={cut ? Tone.Error : Tone.Neutral}
		onclick={toFleet}
	/>
	<Stat label="Peticiones" value={k(requests)} subText="en la ventana" />
	<Stat
		label="Proporción de error"
		value={requests ? pct(errors / requests) : '—'}
		subValue="4xx+5xx"
	/>
	<Stat label="Ingreso recurrente" value={eur(mrr)} subText="términos vigentes" />
</div>

<style>
	.stats {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(172px, 1fr));
		gap: 10px;
	}
</style>
