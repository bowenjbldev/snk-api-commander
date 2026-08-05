<script lang="ts">
	import { OnExceed } from '$lib/model/enums';
	import { WARN_THRESHOLD, type Meter } from './meter';

	interface Props {
		meter: Meter;
		/** Sin la cifra al lado cuando la columna ya la lleva aparte. */
		showLabel?: boolean;
	}

	const { meter, showLabel = true }: Props = $props();
</script>

<div class="meter" title={meter.policyNote}>
	<div class="rail" data-tone={meter.tone} class:unlimited={meter.unlimited}>
		{#if !meter.unlimited}
			<div class="fill" style:width="{meter.fill * 100}%"></div>
			<!-- La marca del umbral, siempre visible: es lo que convierte una barra en un contador. -->
			<div class="threshold" style:left="{WARN_THRESHOLD * 100}%"></div>
			{#if meter.overflow > 0}
				<!-- El exceso sale del carril: pasarse no se lee igual que llegar. Con tope, para que
					 un 900 % no empuje la cifra fuera de la celda. -->
				<div class="over" style:width="{Math.min(10, 2 + meter.overflow * 8)}px"></div>
			{/if}
		{/if}
		{#if meter.onExceed === OnExceed.Suspend}
			<!-- Un contador con `suspend` no significa lo mismo que uno con `none`: la política va
				 dentro del propio instrumento. -->
			<div class="trip" title="al exceder corta el suministro"></div>
		{/if}
	</div>
	{#if showLabel}
		<span class="mono label" data-tone={meter.tone}>{meter.label}</span>
	{/if}
</div>

<style>
	.meter {
		display: flex;
		align-items: center;
		gap: 8px;
		min-width: 0;
		flex: 1;
	}

	.rail {
		position: relative;
		flex: 1;
		height: 6px;
		border-radius: var(--r-tag);
		background: var(--bg-fill2);
	}

	.rail.unlimited {
		opacity: 0.5;
	}

	.fill {
		height: 6px;
		border-radius: var(--r-tag) 0 0 var(--r-tag);
		/* Estar por debajo del umbral no es una señal: relleno neutro. */
		background: var(--tx4);
	}

	.rail[data-tone='warn'] .fill {
		background: var(--wn);
	}

	.rail[data-tone='error'] .fill {
		background: var(--er);
	}

	.threshold {
		position: absolute;
		top: 0;
		width: 1px;
		height: 6px;
		background: var(--tx4);
	}

	.over {
		position: absolute;
		top: -1px;
		left: 100%;
		height: 8px;
		border-radius: 0 var(--r-tag) var(--r-tag) 0;
		background: var(--er);
	}

	/* El carril no puede desbordar su celda: la cifra tiene que seguir siendo legible. */
	.meter {
		overflow: hidden;
	}

	.trip {
		position: absolute;
		top: -2px;
		right: -1px;
		width: 2px;
		height: 10px;
		border-radius: var(--r-line);
		background: var(--er);
	}

	.label {
		flex: 0 0 auto;
		min-width: 38px;
		text-align: right;
		font-size: var(--fs-small);
		color: var(--tx2);
	}

	.label[data-tone='warn'] {
		color: var(--wn);
	}

	.label[data-tone='error'] {
		color: var(--er);
	}
</style>
