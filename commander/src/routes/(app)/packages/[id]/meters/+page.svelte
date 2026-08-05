<script lang="ts">
	import { page } from '$app/state';
	import Meter from '$lib/domain/Meter.svelte';
	import { meterFromQuota } from '$lib/domain/meter';
	import { date, n } from '$lib/format';
	import { OnExceed } from '$lib/model/enums';
	import { data } from '$lib/state/data.svelte';
	import Card from '$lib/ui/Card.svelte';
	import Select from '$lib/ui/Select.svelte';
	import Table from '$lib/ui/Table.svelte';
	import TableEmpty from '$lib/ui/TableEmpty.svelte';
	import TableHead from '$lib/ui/TableHead.svelte';
	import TableRow from '$lib/ui/TableRow.svelte';
	import Td from '$lib/ui/Td.svelte';
	import TextInput from '$lib/ui/TextInput.svelte';
	import Th from '$lib/ui/Th.svelte';
	import { ControlSize } from '$lib/ui/kinds';

	const COLUMNS = 'minmax(140px, 1fr) 180px 120px 110px 120px';

	const POLICIES = Object.values(OnExceed).map((value) => ({ value, label: value }));

	const id = $derived(Number(page.params['id']));
	const pkg = $derived(data.packages.find((p) => p.id === id) ?? null);
	const quotas = $derived(pkg?.quotas ?? []);
</script>

<section>
	<Card flush title="Contadores" note={`${quotas.length} métricas · cadencia de 6 h`}>
		<Table columns={COLUMNS}>
			<TableHead>
				<Th>Métrica</Th>
				<Th>Consumo actual</Th>
				<Th align="right">Límite</Th>
				<Th>Al exceder</Th>
				<Th>Excedida desde</Th>
			</TableHead>

			{#if quotas.length === 0}
				<TableEmpty message="Esta instancia todavía no tiene contadores." />
			{:else}
				{#each quotas as quota (quota.id)}
					{@const reading = meterFromQuota(quota)}
					<TableRow>
						<Td mono small>{quota.metric_key}</Td>
						<Td><Meter meter={reading} /></Td>
						<Td align="right">
							<TextInput
								value={quota.limit_value === null ? '' : String(quota.limit_value)}
								placeholder="ilimitado"
								mono
								size={ControlSize.Dense}
								width="104px"
							/>
						</Td>
						<Td>
							<Select value={quota.on_exceed} options={POLICIES} size={ControlSize.Dense} />
						</Td>
						<Td mono small muted>{quota.exceeded_at ? date(quota.exceeded_at) : '—'}</Td>
					</TableRow>
				{/each}
			{/if}
		</Table>
	</Card>

	<p class="note">
		Límite vacío = ilimitado. La marca del carril es el umbral de aviso al 80 % y el exceso sale
		fuera. Un corte manual, o por impago, no se cura bajando una cuota.
		{#if quotas.length}
			<span class="mono">{n(quotas.length)}</span> métricas medidas.
		{/if}
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
