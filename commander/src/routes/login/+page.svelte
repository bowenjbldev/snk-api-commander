<script lang="ts">
	import { ApiError } from '$lib/api/client';
	import { Route, navigate } from '$lib/nav/routes';
	import { session, SessionStatus } from '$lib/state/session.svelte';
	import Button from '$lib/ui/Button.svelte';
	import { ButtonVariant } from '$lib/ui/kinds';
	import Field from '$lib/ui/Field.svelte';
	import TextInput from '$lib/ui/TextInput.svelte';

	let email = $state('');
	let password = $state('');
	let error = $state<string | null>(null);
	let busy = $state(false);

	$effect(() => {
		if (session.status === SessionStatus.Authenticated) navigate(Route.Overview);
	});

	async function submit(event: SubmitEvent): Promise<void> {
		event.preventDefault();
		busy = true;
		error = null;
		try {
			await session.login(email, password);
			navigate(Route.Overview);
		} catch (cause) {
			// Un error posible y uno solo: no se distingue si falló el correo o la contraseña.
			error =
				cause instanceof ApiError && cause.status === 429
					? 'Demasiados intentos. Espera un minuto.'
					: 'Esas credenciales no entran.';
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head><title>Commander · entrar</title></svelte:head>

<main>
	<form onsubmit={submit}>
		<div class="brand">
			<span class="mark" aria-hidden="true">C</span>
			<span class="name">Commander</span>
		</div>

		<Field label="Correo">
			{#snippet children(id)}
				<TextInput {id} type="email" bind:value={email} width="100%" />
			{/snippet}
		</Field>

		<Field label="Contraseña">
			{#snippet children(id)}
				<TextInput {id} type="password" bind:value={password} width="100%" />
			{/snippet}
		</Field>

		{#if error}<p class="error">{error}</p>{/if}

		<Button variant={ButtonVariant.Primary} type="submit" full disabled={busy}>
			{busy ? 'Entrando…' : 'Entrar'}
		</Button>
	</form>
</main>

<style>
	/* Fondo liso, una pantalla, un error posible, y sin credenciales de ejemplo a la vista. */
	main {
		display: grid;
		place-items: center;
		height: 100vh;
		background: var(--bg-app);
	}

	form {
		display: flex;
		flex-direction: column;
		gap: 12px;
		width: 320px;
		padding: 20px;
		border: 1px solid var(--bd2);
		border-radius: var(--r);
		background: var(--bg-card);
	}

	.brand {
		display: flex;
		align-items: center;
		gap: 8px;
		margin-bottom: 4px;
	}

	.mark {
		display: grid;
		place-items: center;
		width: 28px;
		height: 28px;
		border-radius: var(--r);
		background: var(--pri);
		color: var(--on-pri);
		font-size: var(--fs-card);
		font-weight: 700;
	}

	.name {
		font-size: var(--fs-title);
		font-weight: var(--fw-bold);
		letter-spacing: var(--ls-title);
	}

	.error {
		margin: 0;
		font-size: var(--fs-small);
		color: var(--er);
	}
</style>
