<script lang="ts">
	import type { ComponentDoc } from '$lib/uikit/props';

	const { doc }: { doc: ComponentDoc } = $props();
</script>

<table>
	<caption class="mono">{doc.name}</caption>
	<thead>
		<tr>
			<th scope="col">Propiedad</th>
			<th scope="col">Tipo</th>
			<th scope="col">Por defecto</th>
			<th scope="col">Qué decide</th>
		</tr>
	</thead>
	<tbody>
		{#each doc.props as prop (prop.name)}
			<tr>
				<td class="mono name">
					{prop.name}{#if prop.required}<abbr title="Obligatoria">*</abbr>{/if}
				</td>
				<td class="mono type">{prop.type}</td>
				<td class="mono fallback">{prop.fallback ?? '—'}</td>
				<td class="doc">{prop.doc ?? ''}</td>
			</tr>
		{/each}
	</tbody>
</table>

<style>
	table {
		width: 100%;
		border-collapse: collapse;
		table-layout: fixed;
	}

	caption {
		padding: 0 0 6px;
		text-align: left;
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	th {
		padding: 0 8px 5px;
		border-bottom: 1px solid var(--bd);
		text-align: left;
		font-size: var(--fs-micro);
		font-weight: var(--fw-bold);
		letter-spacing: var(--ls-caps);
		text-transform: uppercase;
		color: var(--tx3);
	}

	th:first-child {
		width: 18%;
	}
	th:nth-child(2) {
		width: 22%;
	}
	/* El valor por defecto es un identificador entero —`ButtonVariant.Default`— y partirlo por la
	   mitad lo vuelve ilegible: se le da ancho antes que a la descripción, que sí puede fluir. */
	th:nth-child(3) {
		width: 20%;
	}

	td {
		padding: 6px 8px;
		border-bottom: 1px solid var(--bd2);
		font-size: var(--fs-small);
		vertical-align: top;
		overflow-wrap: anywhere;
	}

	tr:last-child td {
		border-bottom: 0;
	}

	.name {
		color: var(--tx);
	}

	/* La obligatoria se marca con el mismo rojo que advierte en un mando: es lo que rompe si falta. */
	abbr {
		color: var(--er);
		text-decoration: none;
	}

	.type {
		color: var(--pri);
	}

	.fallback {
		color: var(--tx3);
		overflow-wrap: normal;
	}

	.doc {
		font-family: var(--font-ui);
		color: var(--tx2);
		line-height: 1.55;
	}
</style>
