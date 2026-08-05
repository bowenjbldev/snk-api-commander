<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Data,
		name: 'Chip',
		notes:
			'Filtro de situación. La marca de 3 px es la misma que la línea de suministro de la fila.'
	};
</script>

<script lang="ts">
	import { Tone } from '$lib/model/enums';
	import Chip from './Chip.svelte';

	const FILTERS = [
		{ id: 'all', label: 'Todas', count: 31, tone: Tone.Neutral, muted: false },
		{ id: 'live', label: 'Conectadas', count: 24, tone: Tone.Ok, muted: false },
		{ id: 'notice', label: 'En preaviso', count: 2, tone: Tone.Warn, muted: false },
		{ id: 'cut', label: 'Cortadas', count: 1, tone: Tone.Error, muted: false },
		{ id: 'pending', label: 'Alta pendiente', count: 1, tone: Tone.Info, muted: false },
		{ id: 'catalog', label: 'Solo catálogo', count: 3, tone: Tone.Neutral, muted: true },
		{ id: 'undeployed', label: 'Sin desplegar', count: 1, tone: Tone.Neutral, muted: true },
		{ id: 'unregistered', label: 'Sin dar de alta', count: 0, tone: Tone.Neutral, muted: true },
		{ id: 'gone', label: 'De baja', count: 2, tone: Tone.Neutral, muted: true }
	];

	let selected = $state('live');
</script>

<div class="row">
	{#each FILTERS as filter (filter.id)}
		<Chip
			label={filter.label}
			count={filter.count}
			tone={filter.tone}
			muted={filter.muted}
			selected={selected === filter.id}
			onclick={() => (selected = filter.id)}
		/>
	{/each}
</div>

<div class="row">
	<Chip label="Sin cifra" />
	<Chip label="Sin cifra y encendido" selected />
	<Chip label="Apagado" muted count="—" />
</div>

<p class="note">
	Los cuatro últimos del primer grupo llevan la marca apagada al borde: no hay suministro que
	representar, así que la marca no puede prometer ninguno.
</p>

<style>
	.row {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	.row + .row {
		margin-top: 10px;
	}

	.note {
		margin: 10px 0 0;
		font-size: var(--fs-small);
		color: var(--tx2);
	}
</style>
