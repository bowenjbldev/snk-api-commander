<script lang="ts">
	import type { Snippet } from 'svelte';
	import { attentionItems } from '$lib/domain/attention';
	import { Route, navigate } from '$lib/nav/routes';
	import { clock } from '$lib/state/clock.svelte';
	import { data } from '$lib/state/data.svelte';
	import { session, SessionStatus } from '$lib/state/session.svelte';
	import { toasts } from '$lib/state/toasts.svelte';
	import AppHeader from '$lib/shell/AppHeader.svelte';
	import CommandPalette from '$lib/shell/CommandPalette.svelte';
	import { buildCommands } from '$lib/shell/commands';
	import Rail from '$lib/shell/Rail.svelte';
	import ShellFooter from '$lib/shell/ShellFooter.svelte';
	import Toaster from '$lib/ui/Toaster.svelte';

	const { children }: { children: Snippet } = $props();

	let palette = $state(false);

	$effect(() => clock.subscribe());

	$effect(() => {
		if (session.status === SessionStatus.Loading) {
			void session.restore();
		} else if (session.status === SessionStatus.Anonymous) {
			navigate(Route.Login);
		}
	});

	// La carga inicial arranca en cuanto hay sesión. El armazón entra primero: no se bloquea
	// esperando a los siete recursos.
	$effect(() => {
		if (session.status === SessionStatus.Authenticated && data.loadedAt === null && !data.loading) {
			void data.load();
		}
	});

	const attention = $derived(
		attentionItems({ packages: data.packages, invoices: data.invoices, now: clock.now })
	);

	const commands = $derived(buildCommands(data.packages, data.clients, data.invoices));

	function onkeydown(event: KeyboardEvent): void {
		if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
			event.preventDefault();
			palette = !palette;
		}
	}
</script>

<svelte:window {onkeydown} />

<div class="shell">
	<Rail />
	<div class="main">
		<AppHeader
			attention={attention.length}
			onsearch={() => (palette = true)}
			onattention={() => {
				navigate(Route.Overview);
			}}
			onlogout={async () => {
				await session.logout();
				data.reset();
				navigate(Route.Login);
			}}
		/>
		<div class="content">
			<div class="page">{@render children()}</div>
		</div>
	</div>
	<ShellFooter
		loadedAt={data.loadedAt}
		now={clock.now}
		attention={attention.length}
		operator={session.user?.full_name ?? session.user?.name ?? '—'}
		onattention={() => {
			navigate(Route.Overview);
		}}
	/>
</div>

{#if palette}
	<CommandPalette {commands} onclose={() => (palette = false)} />
{/if}

<Toaster items={toasts.items} />

<style>
	.shell {
		display: grid;
		grid-template-columns: var(--w-rail) minmax(0, 1fr);
		grid-template-rows: minmax(0, 1fr) var(--h-shell-foot);
		height: 100vh;
	}

	.main {
		display: flex;
		flex-direction: column;
		grid-row: 1;
		min-width: 0;
		overflow: hidden;
	}

	.content {
		flex: 1;
		min-height: 0;
		overflow: auto;
		scrollbar-gutter: stable;
	}

	.page {
		max-width: var(--content-max);
		margin: 0 auto;
	}
</style>
