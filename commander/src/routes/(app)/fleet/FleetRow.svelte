<script lang="ts">
	/* eslint-disable svelte/no-navigation-without-resolve --
	 * El href sale de `packageRoute`, en nav/routes.ts. Ver el motivo allí. */
	import SupplyLine from '$lib/domain/SupplyLine.svelte';
	import Meter from '$lib/domain/Meter.svelte';
	import type { FleetRow } from '$lib/fleet/rows.svelte';
	import { packageRoute } from '$lib/nav/routes';
	import Checkbox from '$lib/ui/Checkbox.svelte';
	import IconButton from '$lib/ui/IconButton.svelte';
	import Tag from '$lib/ui/Tag.svelte';
	import TableRow from '$lib/ui/TableRow.svelte';
	import Td from '$lib/ui/Td.svelte';
	import { Situation } from '$lib/model/enums';

	interface Props {
		row: FleetRow;
		selected: boolean;
		ontoggle: (checked: boolean) => void;
		onmenu: (event: MouseEvent) => void;
		countdown: string | null;
	}

	const { row, selected, ontoggle, onmenu, countdown }: Props = $props();
</script>

<TableRow>
	<SupplyLine supply={row.supply} />
	<Checkbox label="Seleccionar {row.name}" checked={selected} onchange={ontoggle} />
	<Td truncate>
		<a class="mono name" href={packageRoute(row.id)} title={row.name}>{row.name}</a>
		<Tag
			tone={row.supply.tone}
			pending={row.situation === Situation.Undeployed || row.situation === Situation.Unregistered}
		>
			{row.supply.label}
		</Tag>
	</Td>
	<Td mono small align="right" muted>{countdown ?? '—'}</Td>
	<Td truncate muted>{row.client}</Td>
	<Td small muted>{row.plan}</Td>
	<Td mono align="right">{row.requests}</Td>
	<Td>
		{#if row.meter}
			<Meter meter={row.meter} />
		{:else}
			<span class="none">—</span>
		{/if}
	</Td>
	<Td mono small muted truncate>{row.seen}</Td>
	<Td><IconButton label="Acciones sobre {row.name}" onclick={onmenu}>⋯</IconButton></Td>
</TableRow>

<style>
	.name {
		flex: 0 1 auto;
		min-width: 44px;
		color: var(--tx);
		font-size: var(--fs-body);
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.name:hover {
		color: var(--pri);
	}

	.none {
		font-size: var(--fs-small);
		color: var(--tx4);
	}
</style>
