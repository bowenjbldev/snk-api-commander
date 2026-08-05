<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Foundations,
		name: 'Tipografía',
		notes:
			'Si es un dato que el sistema generó, va en mono. Si es lenguaje que escribimos nosotros, en la de interfaz.'
	};
</script>

<script lang="ts">
	const STEPS = [
		{ size: '--fs-micro', px: 10, ui: 'PRODUCTO', data: '#1002' },
		{ size: '--fs-small', px: 11, ui: 'Nota al pie', data: 'atlas-crm' },
		{ size: '--fs-body', px: 12, ui: 'Cuerpo y controles', data: '82.400 / 100.000' },
		{ size: '--fs-card', px: 13, ui: 'Título de tarjeta', data: 'quota_exceeded:requests_day' },
		{ size: '--fs-title', px: 15, ui: 'Título de pantalla', data: '4 h 11 m' },
		{ size: '--fs-read', px: 20, ui: 'Lectura grande', data: '13.420 €' }
	];
</script>

<table>
	<thead>
		<tr>
			<th class="caps">Talla</th>
			<th class="caps">Interfaz · nuestra palabra</th>
			<th class="caps">Mono · dato del sistema</th>
		</tr>
	</thead>
	<tbody>
		{#each STEPS as step (step.size)}
			<tr>
				<td class="mono step">{step.px}</td>
				<td style:font-size="var({step.size})">{step.ui}</td>
				<td class="mono" style:font-size="var({step.size})">{step.data}</td>
			</tr>
		{/each}
	</tbody>
</table>

<p class="note">
	Dos pesos y nada más: <strong>400</strong> y <strong>600</strong>. Las cifras tabulares van dentro
	de <code class="mono">.mono</code>; no hay forma de pedir una sin la otra.
</p>

<style>
	table {
		width: 100%;
		border-collapse: collapse;
	}

	th {
		text-align: left;
		padding: 0 8px 6px;
		border-bottom: 1px solid var(--bd);
	}

	td {
		padding: 6px 8px;
		border-bottom: 1px solid var(--bd2);
		vertical-align: baseline;
	}

	.step {
		width: 48px;
		color: var(--tx3);
		font-size: var(--fs-small);
	}

	.note {
		margin: 10px 0 0;
		font-size: var(--fs-small);
		color: var(--tx2);
	}

	strong {
		font-weight: var(--fw-bold);
		color: var(--tx);
	}
</style>
