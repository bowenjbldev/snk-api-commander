<script lang="ts">
	import type { Snippet } from 'svelte';
	import { page } from '$app/state';
	import SupplyLine from '$lib/domain/SupplyLine.svelte';
	import { supply } from '$lib/domain/supply';
	import { cd } from '$lib/format';
	import { PackageStatus, Situation, SupplyShape, Tone } from '$lib/model/enums';
	import { displayName, situationOf, toSupplyInput } from '$lib/model/situation';
	import { PackageTab, packageRoute } from '$lib/nav/routes';
	import PageHeader from '$lib/shell/PageHeader.svelte';
	import { clock } from '$lib/state/clock.svelte';
	import { data } from '$lib/state/data.svelte';
	import { session } from '$lib/state/session.svelte';
	import Alert from '$lib/ui/Alert.svelte';
	import Button from '$lib/ui/Button.svelte';
	import { ButtonVariant, ControlSize } from '$lib/ui/kinds';
	import Tabs from '$lib/ui/Tabs.svelte';

	const { children }: { children: Snippet } = $props();

	const id = $derived(Number(page.params['id']));
	const pkg = $derived(data.packages.find((candidate) => candidate.id === id) ?? null);
	const state = $derived(pkg ? supply(toSupplyInput(pkg, clock.now), clock.now) : null);
	const situation = $derived(pkg ? situationOf(pkg, clock.now) : null);

	const TABS = [
		{ value: PackageTab.Identity, label: 'Identidad' },
		{ value: PackageTab.Plans, label: 'Tarifas' },
		{ value: PackageTab.Meters, label: 'Contadores' },
		{ value: PackageTab.Usage, label: 'Uso' },
		{ value: PackageTab.Logs, label: 'Registros' },
		{ value: PackageTab.Code, label: 'Código' },
		{ value: PackageTab.Settings, label: 'Configuración' }
	];

	const tabs = $derived(TABS.map((tab) => ({ ...tab, href: packageRoute(id, tab.value) })));
	const current = $derived(page.url.pathname.split('/').pop() ?? PackageTab.Identity);

	// La consola oculta lo que no procede; la API vuelve a comprobarlo.
	const canControl = $derived(session.can('control packages'));
</script>

{#if pkg === null}
	<PageHeader title="Instancia" />
	<section><p class="missing">No hay ninguna instancia con ese identificador.</p></section>
{:else}
	<PageHeader
		title={displayName(pkg)}
		subtitle={pkg.client?.slug
			? `${pkg.client.slug}.snakepc.test/api/v1/${pkg.slug ?? ''}`
			: undefined}
	>
		{#snippet action()}
			{#if canControl && situation === Situation.Live}
				{#if pkg.status === PackageStatus.Active}
					<Button size={ControlSize.Regular}>Iniciar preaviso</Button>
					<Button size={ControlSize.Regular} tone={Tone.Error}>Cortar suministro</Button>
				{:else}
					<Button size={ControlSize.Regular} variant={ButtonVariant.Primary}>
						Restablecer suministro
					</Button>
				{/if}
			{/if}
		{/snippet}
	</PageHeader>

	{#if state}
		<div class="banner">
			<Alert
				tone={state.tone}
				title={state.label}
				body={pkg.grace_until && state.shape === SupplyShape.Shrink
					? `${pkg.reason ?? 'sin motivo registrado'} · corta en ${cd(new Date(pkg.grace_until).getTime(), clock.now)}`
					: (pkg.reason ?? undefined)}
			>
				{#snippet leading()}
					<SupplyLine supply={state} height="stretch" />
				{/snippet}
			</Alert>
		</div>
	{/if}

	<div class="tabs"><Tabs {tabs} {current} label="Vistas de la instancia" /></div>

	{@render children()}
{/if}

<style>
	section,
	.banner {
		padding: 12px 16px 0;
	}

	.tabs {
		padding: 12px 16px 0;
	}

	.missing {
		margin: 0;
		font-size: var(--fs-body);
		color: var(--tx3);
	}
</style>
