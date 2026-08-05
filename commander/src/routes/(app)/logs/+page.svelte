<script lang="ts">
	import { logs } from '$lib/api/resources';
	import { stamp } from '$lib/format';
	import { HttpMethod, StatusClass, Tone } from '$lib/model/enums';
	import type { CommanderRequestLog } from '$lib/model/types';
	import PageHeader from '$lib/shell/PageHeader.svelte';
	import { data } from '$lib/state/data.svelte';
	import { createPager } from '$lib/table/pager.svelte';
	import Button from '$lib/ui/Button.svelte';
	import Card from '$lib/ui/Card.svelte';
	import { ControlSize } from '$lib/ui/kinds';
	import Pager from '$lib/ui/Pager.svelte';
	import Select from '$lib/ui/Select.svelte';
	import Table from '$lib/ui/Table.svelte';
	import TableEmpty from '$lib/ui/TableEmpty.svelte';
	import TableHead from '$lib/ui/TableHead.svelte';
	import TableRow from '$lib/ui/TableRow.svelte';
	import TableSkeleton from '$lib/ui/TableSkeleton.svelte';
	import Td from '$lib/ui/Td.svelte';
	import Th from '$lib/ui/Th.svelte';
	import Toolbar from '$lib/ui/Toolbar.svelte';

	const COLUMNS = '150px minmax(120px, 1fr) 70px minmax(180px, 2fr) 64px 80px 110px';

	const METHODS = [
		{ value: 'all', label: 'Método: todos' },
		...Object.values(HttpMethod).map((m) => ({ value: m, label: m }))
	];
	const CLASSES = [
		{ value: 'all', label: 'Clase: todas' },
		{ value: StatusClass.Success, label: '2xx' },
		{ value: StatusClass.ClientError, label: '4xx' },
		{ value: StatusClass.ServerError, label: '5xx' }
	];

	let method = $state('all');
	let statusClass = $state('all');
	let rows = $state<CommanderRequestLog[]>([]);
	let loading = $state(true);
	let failed = $state(false);

	$effect(() => {
		const filters = {
			...(method === 'all' ? {} : { method: method as HttpMethod }),
			...(statusClass === 'all' ? {} : { statusClass: statusClass as StatusClass })
		};
		loading = true;
		logs
			.read(filters)
			.then((result) => {
				rows = result;
				failed = false;
			})
			.catch(() => {
				rows = [];
				failed = true;
			})
			.finally(() => (loading = false));
	});

	const names = $derived(new Map(data.packages.map((p) => [p.id, p.slug ?? p.name])));
	const pager = createPager(() => rows);

	function toneOf(status: number): Tone {
		if (status >= 500) return Tone.Error;
		if (status >= 400) return Tone.Warn;
		return Tone.Neutral;
	}
</script>

<svelte:head><title>Commander · Registros</title></svelte:head>

<PageHeader
	title="Explorador de registros"
	parts={[{ value: String(rows.length), text: 'peticiones' }]}
/>

<section>
	<Card flush>
		<Toolbar>
			<Select bind:value={method} options={METHODS} />
			<Select bind:value={statusClass} options={CLASSES} />
			{#snippet trailing()}
				<span class="mono count">tope de la API: 500</span>
			{/snippet}
		</Toolbar>

		<Table columns={COLUMNS}>
			<TableHead>
				<Th>Instante</Th>
				<Th>Instancia</Th>
				<Th>Método</Th>
				<Th>Ruta</Th>
				<Th align="right">Código</Th>
				<Th align="right">Duración</Th>
				<Th>IP</Th>
			</TableHead>

			{#if loading && rows.length === 0}
				<TableSkeleton dense widths={['70%', '55%', '40%', '85%', '30%', '40%', '60%']} rows={12} />
			{:else if failed}
				<TableEmpty message="No se pudieron leer los registros." />
			{:else if rows.length === 0}
				<TableEmpty message="Ninguna petición registrada con estos filtros.">
					{#snippet action()}
						<Button
							size={ControlSize.Compact}
							onclick={() => {
								method = 'all';
								statusClass = 'all';
							}}>Quitar los filtros</Button
						>
					{/snippet}
				</TableEmpty>
			{:else}
				{#each pager.slice as log (log.id)}
					<TableRow dense>
						<Td mono small muted>{stamp(log.created_at)}</Td>
						<Td mono small truncate muted>{names.get(log.package_id) ?? `#${log.package_id}`}</Td>
						<Td mono small>{log.method}</Td>
						<Td mono small truncate>{log.route}</Td>
						<Td mono small align="right"
							><span data-tone={toneOf(log.status)}>{log.status}</span></Td
						>
						<Td mono small align="right" muted>{log.duration_ms} ms</Td>
						<Td mono small muted truncate>{log.ip ?? '—'}</Td>
					</TableRow>
				{/each}
			{/if}

			<Pager {pager} />
		</Table>
	</Card>

	<p class="note">
		Solo lectura. La API topa en 500 filas por consulta y no acepta paginación, así que no hay cien
		mil filas que recorrer ni desplazamiento virtual que montar.
	</p>
</section>

<style>
	section {
		padding: 14px 16px 28px;
	}

	.count {
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	[data-tone='warn'] {
		color: var(--wn);
	}

	[data-tone='error'] {
		color: var(--er);
	}

	.note {
		margin: 10px 2px 0;
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
