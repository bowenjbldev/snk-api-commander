<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Domain,
		name: 'AttentionTray',
		notes:
			'No es un centro de notificaciones: es la cola de trabajo del operador. Cada línea exige una decisión y trae su acción directa.'
	};
</script>

<script lang="ts">
	import { Tone } from '$lib/model/enums';
	import Card from '$lib/ui/Card.svelte';
	import type { AttentionItem } from './attention';
	import AttentionTray from './AttentionTray.svelte';

	const ALL: AttentionItem[] = [
		{
			id: 'expired:2',
			tone: Tone.Error,
			title: 'Preaviso caducado',
			body: 'beacon-api · Kilkana Media — ya no está servido',
			action: 'Decidir'
		},
		{
			id: 'invoice:7',
			tone: Tone.Error,
			title: 'Factura vencida F-2026-0188',
			body: '149 € · 9 días de retraso',
			action: 'Cobro'
		},
		{
			id: 'stalled:9',
			tone: Tone.Error,
			title: 'Alta sin completar hace 3 d',
			body: 'cauce-etl · Basalto — revisa el trabajador de la cola',
			action: 'Ver instancia'
		},
		{
			id: 'grace:4',
			tone: Tone.Warn,
			title: 'Preaviso corriendo · 4 h 11 m',
			body: 'sonda-analytics · Basalto — manual:contrato en revisión',
			action: 'Extender preaviso'
		},
		{
			id: 'quota:1',
			tone: Tone.Warn,
			title: 'Contador al 88 % · requests_day',
			body: 'atlas-crm · Kilkana Media — política suspend',
			action: 'Contadores'
		}
	];

	let seen = $state<string[]>([]);
	const items = $derived(ALL.filter((item) => !seen.includes(item.id)));
</script>

<Card flush title="Bandeja de atención" note="{items.length} pendientes">
	{#snippet aside()}
		<span class="hint">Cada línea exige una decisión</span>
	{/snippet}
	<AttentionTray
		{items}
		ondismiss={(item: AttentionItem) => {
			seen = [...seen, item.id];
		}}
	/>
</Card>

{#if seen.length}
	<button
		class="reset"
		type="button"
		onclick={() => {
			seen = [];
		}}>Reponer las descartadas</button
	>
{/if}

<style>
	.hint {
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.reset {
		margin-top: 10px;
		padding: 0;
		border: 0;
		background: transparent;
		color: var(--pri);
		font-size: var(--fs-small);
	}
</style>
