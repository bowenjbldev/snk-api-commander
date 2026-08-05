<script lang="ts">
	import { page } from '$app/state';
	import { eur, k } from '$lib/format';
	import { Tone } from '$lib/model/enums';
	import { data } from '$lib/state/data.svelte';
	import Button from '$lib/ui/Button.svelte';
	import Card from '$lib/ui/Card.svelte';
	import { ButtonVariant, ControlSize } from '$lib/ui/kinds';
	import Table from '$lib/ui/Table.svelte';
	import TableEmpty from '$lib/ui/TableEmpty.svelte';
	import TableHead from '$lib/ui/TableHead.svelte';
	import TableRow from '$lib/ui/TableRow.svelte';
	import Tag from '$lib/ui/Tag.svelte';
	import Td from '$lib/ui/Td.svelte';
	import Th from '$lib/ui/Th.svelte';

	const COLUMNS = 'minmax(120px, 1fr) 100px minmax(220px, 2fr) 92px';

	const id = $derived(Number(page.params['id']));
	const pkg = $derived(data.packages.find((p) => p.id === id) ?? null);

	// Una tarifa es del producto, no global: `Pro` de dos productos son dos tarifas distintas.
	const plans = $derived(pkg ? data.plans.filter((plan) => plan.package === pkg.name) : []);

	const limitsOf = (plan: (typeof plans)[number]): string =>
		[
			['requests_day', plan.q_requests_day],
			['disk_db_mb', plan.q_disk_db_mb],
			['disk_storage_mb', plan.q_disk_storage_mb],
			['users_total', plan.q_users_total]
		]
			.map(([key, value]) => `${key} ${value === null ? '∞' : k(Number(value))}`)
			.join(' · ');
</script>

<section>
	<Card flush title="Tarifas del producto" note={`${plans.length} declaradas`}>
		{#snippet actions()}
			<Button variant={ButtonVariant.Default} size={ControlSize.Compact}>Dar de alta tarifa</Button>
		{/snippet}
		<Table columns={COLUMNS}>
			<TableHead>
				<Th>Plan</Th>
				<Th align="right">Precio/mes</Th>
				<Th>Límites de cuota</Th>
				<Th>Estado</Th>
			</TableHead>
			{#if plans.length === 0}
				<TableEmpty
					message="Sin tarifas. Este producto no se puede vender hasta que tenga una activa."
				>
					{#snippet action()}
						<Button size={ControlSize.Compact}>Dar de alta tarifa</Button>
					{/snippet}
				</TableEmpty>
			{:else}
				{#each plans as plan (plan.id)}
					<TableRow>
						<Td>{plan.name}</Td>
						<Td mono align="right">{eur(Number(plan.price_monthly))}</Td>
						<Td mono small muted truncate title={limitsOf(plan)}>{limitsOf(plan)}</Td>
						<Td>
							<Tag tone={plan.status === 'active' ? Tone.Ok : Tone.Neutral}>{plan.status}</Tag>
						</Td>
					</TableRow>
				{/each}
			{/if}
		</Table>
	</Card>

	<p class="note">
		Los límites de la tarifa siembran los contadores de cada instancia nueva; a partir de ahí la
		cuota vive en la instancia. Cambiarlos aquí no reajusta lo ya provisionado — eso solo lo hace un
		cambio de plan.
	</p>
</section>

<style>
	section {
		padding: 12px 16px 28px;
	}

	.note {
		margin: 10px 2px 0;
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
