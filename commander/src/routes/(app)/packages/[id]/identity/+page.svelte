<script lang="ts">
	import { page } from '$app/state';
	import { date, k } from '$lib/format';
	import { displayName } from '$lib/model/situation';
	import { data } from '$lib/state/data.svelte';
	import Card from '$lib/ui/Card.svelte';
	import Field from '$lib/ui/Field.svelte';
	import Select from '$lib/ui/Select.svelte';
	import TextInput from '$lib/ui/TextInput.svelte';
	import { ListingStatus } from '$lib/model/enums';

	const id = $derived(Number(page.params['id']));
	const pkg = $derived(data.packages.find((p) => p.id === id) ?? null);

	const LISTING = [
		{ value: ListingStatus.Active, label: 'Publicado' },
		{ value: ListingStatus.Inactive, label: 'Oculto' }
	];

	let label = $state('');
	let description = $state('');
	let listing = $state<string>(ListingStatus.Active);

	$effect(() => {
		if (!pkg) return;
		label = pkg.label ?? '';
		description = pkg.description ?? '';
		listing = pkg.listing_status;
	});

	const tech = $derived(
		pkg
			? [
					['identificador', String(pkg.id)],
					['slug', pkg.slug ?? '—'],
					['nombre técnico', pkg.name],
					['cliente', pkg.client?.slug ?? '—'],
					['alta', pkg.provisioned_at ? date(pkg.provisioned_at) : 'sin provisionar'],
					['en disco', pkg.on_disk ? 'sí' : 'no'],
					['tarifas del producto', String(pkg.plan_count)],
					['tráfico 24 h', k(pkg.requests_24h)]
				]
			: []
	);

	// Los metadatos de catálogo son del producto, no de la instancia: cambian para todas a la vez.
	const siblings = $derived(
		pkg ? data.packages.filter((p) => p.slug === pkg.slug && p.id !== pkg.id) : []
	);
</script>

{#if pkg}
	<section>
		<Card title="Metadatos de catálogo">
			<div class="fields">
				<Field label="Rótulo">
					{#snippet children(fid)}<TextInput id={fid} bind:value={label} width="100%" />{/snippet}
				</Field>
				<Field label="Descripción">
					{#snippet children(fid)}
						<TextInput id={fid} bind:value={description} width="100%" />
					{/snippet}
				</Field>
				<Field
					label="Listado"
					hint="Oculto lo saca del catálogo para futuras altas; no toca el tráfico de las vivas."
				>
					{#snippet children(fid)}<Select
							id={fid}
							bind:value={listing}
							options={LISTING}
						/>{/snippet}
				</Field>
			</div>
		</Card>

		<Card title="Datos técnicos">
			<dl>
				{#each tech as [key, value] (key)}
					<dt>{key}</dt>
					<dd class="mono">{value}</dd>
				{/each}
			</dl>
		</Card>

		{#if siblings.length}
			<Card title="Instancias de este producto" note={String(siblings.length)}>
				<p class="note">Los metadatos de catálogo son comunes a estas instancias.</p>
				<ul>
					{#each siblings as sib (sib.id)}
						<li><span class="mono">{displayName(sib)}</span> · {sib.client?.slug ?? '—'}</li>
					{/each}
				</ul>
			</Card>
		{/if}
	</section>
{/if}

<style>
	section {
		display: flex;
		flex-direction: column;
		gap: 12px;
		padding: 12px 16px 28px;
	}

	.fields {
		display: grid;
		gap: 12px;
		max-width: 480px;
	}

	dl {
		display: grid;
		grid-template-columns: 180px minmax(0, 1fr);
		gap: 6px 12px;
		margin: 0;
	}

	dt {
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	dd {
		margin: 0;
		font-size: var(--fs-body);
	}

	ul {
		margin: 8px 0 0;
		padding-left: 16px;
	}

	li,
	.note {
		font-size: var(--fs-small);
		color: var(--tx2);
	}

	.note {
		margin: 0;
		color: var(--tx3);
	}
</style>
