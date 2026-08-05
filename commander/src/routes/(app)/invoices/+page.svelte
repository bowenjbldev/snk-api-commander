<script lang="ts">
	import { date, eur } from '$lib/format';
	import { BillingStatus, Tone } from '$lib/model/enums';
	import { displayName } from '$lib/model/situation';
	import PageHeader from '$lib/shell/PageHeader.svelte';
	import { clock } from '$lib/state/clock.svelte';
	import { data } from '$lib/state/data.svelte';
	import { createPager } from '$lib/table/pager.svelte';
	import Button from '$lib/ui/Button.svelte';
	import Card from '$lib/ui/Card.svelte';
	import IconButton from '$lib/ui/IconButton.svelte';
	import { ButtonVariant, ControlSize } from '$lib/ui/kinds';
	import Pager from '$lib/ui/Pager.svelte';
	import Select from '$lib/ui/Select.svelte';
	import Table from '$lib/ui/Table.svelte';
	import TableEmpty from '$lib/ui/TableEmpty.svelte';
	import TableHead from '$lib/ui/TableHead.svelte';
	import TableRow from '$lib/ui/TableRow.svelte';
	import Tag from '$lib/ui/Tag.svelte';
	import Td from '$lib/ui/Td.svelte';
	import Th from '$lib/ui/Th.svelte';
	import Toolbar from '$lib/ui/Toolbar.svelte';

	const COLUMNS = '120px minmax(140px, 1fr) minmax(120px, 1fr) 96px 100px 150px 92px 30px';

	const STATUS = [
		{ value: 'all', label: 'Estado: todos' },
		{ value: BillingStatus.Paid, label: 'Pagadas' },
		{ value: BillingStatus.Pending, label: 'Pendientes' },
		{ value: BillingStatus.Overdue, label: 'Vencidas' }
	];

	const TONE: Record<BillingStatus, Tone> = {
		[BillingStatus.Paid]: Tone.Ok,
		[BillingStatus.Pending]: Tone.Neutral,
		[BillingStatus.Overdue]: Tone.Error
	};
	const LABEL: Record<BillingStatus, string> = {
		[BillingStatus.Paid]: 'pagada',
		[BillingStatus.Pending]: 'pendiente',
		[BillingStatus.Overdue]: 'vencida'
	};

	let status = $state<'all' | BillingStatus>('all');

	const clients = $derived(new Map(data.clients.map((c) => [c.id, c.company ?? c.name])));
	const packages = $derived(new Map(data.packages.map((p) => [p.id, displayName(p)])));

	const rows = $derived(
		data.invoices
			.filter((invoice) => status === 'all' || invoice.status === status)
			.map((invoice) => {
				const due = invoice.due_at ? new Date(invoice.due_at).getTime() : null;
				const late = due !== null ? Math.round((clock.now - due) / 86_400_000) : 0;
				return {
					invoice,
					client: clients.get(invoice.client_id) ?? '—',
					pkg: invoice.package_id === null ? '—' : (packages.get(invoice.package_id) ?? '—'),
					// El retraso solo se enseña si de verdad lo hay: una pendiente en plazo no es tarde.
					late: invoice.status === BillingStatus.Overdue && late > 0 ? `${late} d` : null
				};
			})
	);

	const pager = createPager(() => rows);
</script>

<svelte:head><title>Commander · Facturas</title></svelte:head>

<PageHeader
	title="Libro de facturas"
	parts={[{ value: String(data.invoices.length), text: 'facturas' }]}
>
	{#snippet action()}
		<Button variant={ButtonVariant.Primary}>Emitir factura</Button>
	{/snippet}
</PageHeader>

<section>
	<Card flush>
		<Toolbar>
			<Select bind:value={status} options={STATUS} />
			{#snippet trailing()}
				<span class="mono count">{rows.length} de {data.invoices.length}</span>
			{/snippet}
		</Toolbar>

		<Table columns={COLUMNS}>
			<TableHead>
				<Th>Número</Th>
				<Th>Cliente</Th>
				<Th>Instancia</Th>
				<Th align="right">Importe</Th>
				<Th>Emisión</Th>
				<Th>Vencimiento</Th>
				<Th>Estado</Th>
				<Th />
			</TableHead>

			{#if rows.length === 0}
				<TableEmpty message="Ninguna factura con este estado.">
					{#snippet action()}
						<Button size={ControlSize.Compact} onclick={() => (status = 'all')}>Ver todas</Button>
					{/snippet}
				</TableEmpty>
			{:else}
				{#each pager.slice as row (row.invoice.id)}
					<TableRow>
						<Td mono>{row.invoice.number}</Td>
						<Td truncate muted>{row.client}</Td>
						<Td truncate muted>{row.pkg}</Td>
						<Td mono align="right">{eur(Number(row.invoice.amount))}</Td>
						<Td mono small muted>{row.invoice.issued_at ? date(row.invoice.issued_at) : '—'}</Td>
						<Td mono small>
							<span>{row.invoice.due_at ? date(row.invoice.due_at) : '—'}</span>
							{#if row.late}<span class="late">{row.late}</span>{/if}
						</Td>
						<Td><Tag tone={TONE[row.invoice.status]}>{LABEL[row.invoice.status]}</Tag></Td>
						<Td><IconButton label="Acciones sobre {row.invoice.number}">⋯</IconButton></Td>
					</TableRow>
				{/each}
			{/if}

			<Pager {pager} />
		</Table>
	</Card>

	<p class="note">
		Cobro y suministro se leen juntos, pero son campos distintos: marcar vencida abre la decisión de
		suministro, y marcar pagada ofrece restablecer si ese impago causó el corte.
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

	.late {
		color: var(--er);
	}

	.note {
		margin: 10px 2px 0;
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
