<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Charts,
		name: 'EChart',
		notes:
			'ECharts no hereda variables CSS: la paleta se lee del DOM una vez por cambio de tema y se pasa como argumento. Alterna el tema arriba y compruébalo.'
	};
</script>

<script lang="ts">
	import type { CommanderUsageBucket } from '$lib/model/types';
	import EChart from './EChart.svelte';
	import { errorRate, requestsByInstance, toTraffic } from './options/traffic';

	// Serie determinista: la guía tiene que verse igual en cada recarga.
	const START = Date.UTC(2026, 7, 4, 0, 0, 0);
	const NAMES = new Map([
		[1, 'atlas-crm'],
		[2, 'beacon-api'],
		[3, 'faro-cms'],
		[4, 'cauce-etl']
	]);

	const rows: CommanderUsageBucket[] = [];
	for (let hour = 0; hour < 24; hour++) {
		for (const id of NAMES.keys()) {
			const wave = Math.abs(Math.sin(hour / 3 + id)) * 400 * id;
			rows.push({
				package_id: id,
				period_start: new Date(START + hour * 3_600_000).toISOString(),
				request_count: Math.round(wave + 120),
				error_count: Math.round(wave / 22),
				count_4xx: Math.round(wave / 30),
				count_5xx: Math.round(wave / 90)
			});
		}
	}

	const traffic = toTraffic(rows, (id) => NAMES.get(id) ?? `#${id}`);
</script>

<div class="grid">
	<div>
		<h4>Peticiones por instancia · área apilada</h4>
		<EChart build={requestsByInstance(traffic)} label="Peticiones por instancia" />
	</div>
	<div>
		<h4>Proporción de error · la serie ES un estado, va en semántico</h4>
		<EChart build={errorRate(rows)} label="Proporción de error" />
	</div>
</div>

<style>
	.grid {
		display: grid;
		grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr);
		gap: 12px;
	}

	h4 {
		margin: 0 0 8px;
		font-size: var(--fs-micro);
		letter-spacing: var(--ls-caps);
		text-transform: uppercase;
		color: var(--tx2);
		font-weight: var(--fw-bold);
	}
</style>
