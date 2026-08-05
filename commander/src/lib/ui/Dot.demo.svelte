<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Data,
		name: 'Dot',
		notes:
			'El punto de la bitácora. Hueco es del sistema, lleno es de una persona: quién lo hizo se ve antes de leer.'
	};
</script>

<script lang="ts">
	import { Tone } from '$lib/model/enums';
	import Dot from './Dot.svelte';

	const TONES = [Tone.Ok, Tone.Warn, Tone.Error, Tone.Info, Tone.Neutral] as const;

	const LOG = [
		{ tone: Tone.Error, hollow: false, verb: 'Cortó el suministro', who: 'jorge · impago' },
		{ tone: Tone.Warn, hollow: true, verb: 'Abrió preaviso', who: 'sistema · contador al 100 %' },
		{ tone: Tone.Ok, hollow: false, verb: 'Restableció', who: 'jorge · pago recibido' },
		{ tone: Tone.Info, hollow: true, verb: 'Provisionó', who: 'sistema · alta aceptada' }
	];
</script>

<div class="row">
	{#each TONES as tone (tone)}
		<Dot {tone} title={tone} />
	{/each}
</div>
<div class="row">
	{#each TONES as tone (tone)}
		<Dot {tone} hollow title={`${tone}, automático`} />
	{/each}
</div>

<div class="log">
	{#each LOG as item, i (i)}
		<div class="line">
			<div class="rail">
				<span class="thread"></span><Dot tone={item.tone} hollow={item.hollow} />
			</div>
			<div>
				<div class="verb">{item.verb}</div>
				<div class="mono who">{item.who}</div>
			</div>
		</div>
	{/each}
</div>

<style>
	.row {
		display: flex;
		align-items: center;
		gap: 10px;
	}

	.row + .row {
		margin-top: 10px;
	}

	.log {
		margin-top: 14px;
	}

	.line {
		display: grid;
		grid-template-columns: 12px minmax(0, 1fr);
		gap: 10px;
		padding: 8px 0;
	}

	/* El hilo cruza de línea a línea por detrás del punto: la bitácora es un continuo. */
	.rail {
		position: relative;
		display: flex;
		justify-content: center;
	}

	.thread {
		position: absolute;
		top: -9px;
		bottom: -9px;
		width: 1px;
		background: var(--bd2);
	}

	.rail :global(.dot) {
		position: relative;
		margin-top: 3px;
	}

	.verb {
		font-size: var(--fs-body);
		font-weight: var(--fw-bold);
	}

	.who {
		font-size: var(--fs-small);
		color: var(--tx2);
	}
</style>
