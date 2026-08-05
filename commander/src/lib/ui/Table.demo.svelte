<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Data,
		name: 'Table',
		notes: 'Cabecera, filas y esqueleto heredan las columnas por variable: no descuadran.',
		components: [
			'Table',
			'TableHead',
			'Th',
			'TableRow',
			'Td',
			'TableSkeleton',
			'TableEmpty',
			'Pager'
		]
	};
</script>

<script lang="ts">
	import { createPager } from '$lib/table/pager.svelte';
	import { n } from '$lib/format';
	import { Tone } from '$lib/model/enums';
	import Checkbox from './Checkbox.svelte';
	import IconButton from './IconButton.svelte';
	import Pager from './Pager.svelte';
	import Table from './Table.svelte';
	import TableEmpty from './TableEmpty.svelte';
	import TableHead from './TableHead.svelte';
	import TableRow from './TableRow.svelte';
	import TableSkeleton from './TableSkeleton.svelte';
	import Tag from './Tag.svelte';
	import Td from './Td.svelte';
	import Th from './Th.svelte';
	import Button from './Button.svelte';
	import { ControlSize, SortState } from './kinds';

	type Mode = 'rows' | 'loading' | 'empty';

	const COLUMNS = '26px minmax(140px, 1.4fr) 92px minmax(0, 1fr) 96px 30px';
	const MODES: [Mode, string][] = [
		['rows', 'Filas'],
		['loading', 'Cargando'],
		['empty', 'Vacía']
	];
	const SEED = [
		{ name: 'atlas-crm', tone: Tone.Ok, state: 'Conectada', client: 'Kilkana Media' },
		{ name: 'beacon-api', tone: Tone.Warn, state: 'En preaviso', client: 'Basalto' },
		{ name: 'faro-cms', tone: Tone.Error, state: 'Cortada', client: 'Ediciones del Cantábrico' },
		{ name: 'cauce-etl', tone: Tone.Info, state: 'Provisionando', client: 'Basalto' },
		{ name: 'sella-pdf', tone: Tone.Neutral, state: 'Catálogo', client: '—' }
	];
	// Cuarenta filas: con cinco no hay primera, media ni última que enseñar del paginador.
	const ROWS = SEED.flatMap((seed, s) =>
		Array.from({ length: 8 }, (_, i) => ({ ...seed, id: s * 8 + i, reqs: (i + 1) * 1237 }))
	);

	let mode = $state<Mode>('rows');
	let dense = $state(false);
	let selected = $state([2]);
	let sort = $state(SortState.Ascending);
	// El destello es un acuse que se ve una vez; para volver a verlo hay que remontar la fila.
	let pushes = $state(0);
	const pager = createPager(() => ROWS);
	const middle = createPager(() => ROWS);
	const last = createPager(() => ROWS);
	middle.goto(2);
	last.goto(4);
</script>

<div class="bar">
	{#each MODES as [value, label] (value)}
		<Button size={ControlSize.Dense} onclick={() => (mode = value)}>{label}</Button>
	{/each}
	<Button size={ControlSize.Dense} onclick={() => (dense = !dense)}>Compacta</Button>
	<Button size={ControlSize.Dense} onclick={() => pushes++}>Empuje del servidor</Button>
</div>

<Table columns={COLUMNS}>
	<TableHead {dense}>
		<Checkbox label="Seleccionar todo" mixed={selected.length > 0} />
		<Th
			onsort={() =>
				(sort = sort === SortState.Ascending ? SortState.Descending : SortState.Ascending)}
			{sort}>Producto</Th
		>
		<Th align="right" onsort={() => {}} title="Ordenable, pero no gobierna el orden">Plazo</Th>
		<Th>Cliente</Th>
		<Th align="right" onsort={() => {}} sort={SortState.Descending}>Tráfico 24 h</Th>
		<Th />
	</TableHead>

	{#if mode === 'loading'}
		<TableSkeleton {dense} widths={['0%', '70%', '45%', '60%', '50%', '0%']} rows={4} />
	{:else if mode === 'empty'}
		<TableEmpty message="Ninguna instancia coincide con este filtro.">
			{#snippet action()}
				<Button size={ControlSize.Compact}>Quitar filtros</Button>
			{/snippet}
		</TableEmpty>
	{:else}
		{#key pushes}
			{#each pager.slice as row (row.id)}
				<TableRow {dense} flash={row.id === 3} onclick={() => {}}>
					<Checkbox label="Seleccionar {row.name}" checked={selected.includes(row.id)} />
					<Td>
						<span class="mono">{row.name}</span>
						<Tag tone={row.tone}>{row.state}</Tag>
					</Td>
					<Td mono small align="right" muted>—</Td>
					<Td truncate title={row.client}>{row.client}</Td>
					<Td mono align="right">{n(row.reqs)}</Td>
					<Td><IconButton label="Acciones">⋯</IconButton></Td>
				</TableRow>
			{/each}
		{/key}
	{/if}

	<Pager {pager} />
</Table>

<p class="note">El mismo pie en una página del medio y en la última, con sus pasos apagados:</p>
<div class="feet">
	<Pager pager={middle} />
	<Pager pager={last} />
</div>

<style>
	.bar {
		display: flex;
		gap: 6px;
		margin-bottom: 10px;
	}

	.note {
		margin: 14px 2px 6px;
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	/* Los pies sueltos necesitan la caja que en una tabla les da la tarjeta. */
	.feet {
		border: 1px solid var(--bd2);
		border-radius: var(--r);
		overflow: hidden;
	}
</style>
