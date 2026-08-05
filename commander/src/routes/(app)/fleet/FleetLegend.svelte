<script lang="ts">
	import SupplyLine from '$lib/domain/SupplyLine.svelte';
	import { supply } from '$lib/domain/supply';
	import { PackageStatus, Situation } from '$lib/model/enums';
	import type { SupplyInput } from '$lib/model/situation';

	const NOW = 0;
	const base: SupplyInput = {
		situation: Situation.Live,
		status: PackageStatus.Active,
		graceUntil: null,
		graceStartedAt: null
	};

	const CASES: SupplyInput[] = [
		base,
		{ ...base, status: PackageStatus.Grace, graceUntil: 60_000, graceStartedAt: -60_000 },
		{ ...base, status: PackageStatus.Grace, graceUntil: -1, graceStartedAt: -60_000 },
		{ ...base, status: PackageStatus.Suspended },
		{ ...base, situation: Situation.Pending },
		{ ...base, situation: Situation.Stalled },
		{ ...base, situation: Situation.Catalog }
	];
</script>

<div class="legend">
	<span class="caption">La línea de la izquierda codifica el suministro por color y por forma:</span
	>
	{#each CASES as item, i (i)}
		{@const state = supply(item, NOW)}
		<span class="item">
			<SupplyLine supply={state} height={14} />
			<span class="label">{state.label}</span>
		</span>
	{/each}
</div>

<style>
	.legend {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 7px 18px;
		margin: 10px 2px 0;
	}

	.caption {
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.item {
		display: flex;
		align-items: center;
		gap: 6px;
	}

	.label {
		font-size: var(--fs-small);
		color: var(--tx2);
	}
</style>
