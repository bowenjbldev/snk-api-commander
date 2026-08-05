<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Domain,
		name: 'SupplyLine',
		notes:
			'Lo que este sistema no trae. Codifica el suministro por forma antes que por color: se lee en escala de grises y con visión de color reducida.'
	};
</script>

<script lang="ts">
	import { PackageStatus, Situation } from '$lib/model/enums';
	import type { SupplyInput } from '$lib/model/situation';
	import Tag from '$lib/ui/Tag.svelte';
	import { supply } from './supply';
	import SupplyLine from './SupplyLine.svelte';

	const NOW = 1_700_000_000_000;
	const HOUR = 3_600_000;

	const base: SupplyInput = {
		situation: Situation.Live,
		status: PackageStatus.Active,
		graceUntil: null,
		graceStartedAt: null
	};

	const CASES: { input: SupplyInput; note: string }[] = [
		{ input: base, note: 'sirve con normalidad' },
		{
			input: {
				...base,
				status: PackageStatus.Grace,
				graceUntil: NOW + 36 * HOUR,
				graceStartedAt: NOW - 12 * HOUR
			},
			note: 'el segmento mengua hasta el corte'
		},
		{
			input: {
				...base,
				status: PackageStatus.Grace,
				graceUntil: NOW + 4 * HOUR,
				graceStartedAt: NOW - 44 * HOUR
			},
			note: 'queda poco: se ve sin leer la cifra'
		},
		{
			input: {
				...base,
				status: PackageStatus.Grace,
				graceUntil: NOW - HOUR,
				graceStartedAt: NOW - 49 * HOUR
			},
			note: 'llegó a cero: ya no está servido'
		},
		{ input: { ...base, status: PackageStatus.Suspended }, note: 'corte' },
		{ input: { ...base, situation: Situation.Pending }, note: 'alta en curso' },
		{
			input: { ...base, situation: Situation.Stalled },
			note: 'alta atascada: solo el reloj lo dice'
		},
		{ input: { ...base, situation: Situation.Catalog }, note: 'no hay suministro que representar' }
	];
</script>

<div class="rows">
	{#each CASES as item, i (i)}
		{@const state = supply(item.input, NOW)}
		<div class="row">
			<SupplyLine supply={state} />
			<Tag tone={state.tone}>{state.label}</Tag>
			<span class="note">{item.note}</span>
			<span class="mono shape">{state.shape}</span>
		</div>
	{/each}
</div>

<style>
	.rows {
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	.row {
		display: flex;
		align-items: center;
		gap: 10px;
		height: var(--h-row);
	}

	.note {
		flex: 1;
		font-size: var(--fs-body);
		color: var(--tx2);
	}

	.shape {
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
