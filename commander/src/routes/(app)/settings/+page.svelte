<script lang="ts">
	import { settings as settingsApi } from '$lib/api/resources';
	import { Theme, Tone } from '$lib/model/enums';
	import type { CommanderSettings } from '$lib/model/types';
	import PageHeader from '$lib/shell/PageHeader.svelte';
	import { session } from '$lib/state/session.svelte';
	import { theme } from '$lib/state/theme.svelte';
	import { toasts } from '$lib/state/toasts.svelte';
	import Button from '$lib/ui/Button.svelte';
	import Card from '$lib/ui/Card.svelte';
	import Field from '$lib/ui/Field.svelte';
	import { ButtonVariant } from '$lib/ui/kinds';
	import Segmented from '$lib/ui/Segmented.svelte';
	import Switch from '$lib/ui/Switch.svelte';
	import TextInput from '$lib/ui/TextInput.svelte';

	const GRACE = ['12', '24', '48', '72'].map((h) => ({ value: h, label: `${h} h` }));
	const THEMES = [
		{ value: Theme.Light, label: 'Claro' },
		{ value: Theme.Dark, label: 'Oscuro' }
	];

	let form = $state<CommanderSettings | null>(null);
	let grace = $state('72');
	let busy = $state(false);
	// Las columnas admiten nulo pero un campo de texto no: se separan del resto del formulario.
	let name = $state('');
	let email = $state('');

	$effect(() => {
		settingsApi
			.read()
			.then((value) => {
				form = value;
				grace = String(value.default_grace_hours);
				name = value.operator_name ?? '';
				email = value.operator_email ?? '';
			})
			.catch(() => {
				form = null;
			});
	});

	let themeChoice = $state<Theme>(theme.value);
	$effect(() => {
		theme.set(themeChoice);
	});

	async function save(): Promise<void> {
		if (!form) return;
		busy = true;
		try {
			form = await settingsApi.write({
				...form,
				operator_name: name,
				operator_email: email,
				default_grace_hours: Number(grace)
			});
			toasts.push('Preferencias guardadas', Tone.Ok);
		} catch {
			toasts.push('No se pudieron guardar las preferencias', Tone.Error);
		} finally {
			busy = false;
		}
	}
</script>

<svelte:head><title>Commander · Preferencias</title></svelte:head>

<PageHeader title="Preferencias">
	{#snippet action()}
		<Button variant={ButtonVariant.Primary} disabled={busy || !form} onclick={save}>
			{busy ? 'Guardando…' : 'Guardar cambios'}
		</Button>
	{/snippet}
</PageHeader>

<section>
	<Card title="Perfil del operador">
		{#if form}
			<div class="fields">
				<Field label="Nombre">
					{#snippet children(id)}
						<TextInput {id} bind:value={name} width="100%" />
					{/snippet}
				</Field>
				<Field label="Correo">
					{#snippet children(id)}
						<TextInput {id} type="email" bind:value={email} width="100%" />
					{/snippet}
				</Field>
			</div>
		{:else}
			<p class="muted">No se pudieron leer las preferencias.</p>
		{/if}
		<div class="theme">
			<span class="label">Tema</span>
			<Segmented bind:value={themeChoice} options={THEMES} label="Tema" />
		</div>
	</Card>

	<Card title="Preaviso">
		<span class="label">Duración por defecto</span>
		<Segmented bind:value={grace} options={GRACE} label="Duración por defecto del preaviso" />
		<p class="note">
			Se aplica a los preavisos que abre la lectura de contadores y a los que abres tú sin
			especificar plazo.
		</p>
	</Card>

	{#if form}
		<Card title="Avisos">
			<div class="switches">
				<Switch
					bind:checked={form.notify_on_suspend}
					label="Corte automático"
					note="La lectura de contadores puede cortar sin que toques nada"
				/>
				<Switch
					bind:checked={form.notify_on_overdue}
					label="Factura vencida"
					note="Enlaza el cobro con el suministro"
				/>
			</div>
		</Card>
	{/if}

	<Card title="Permisos" note="{session.user?.all_permissions.length ?? 0} concedidos">
		<div class="perms">
			{#each session.user?.all_permissions ?? [] as permission (permission)}
				<span class="mono perm">{permission}</span>
			{:else}
				<p class="muted">Sin permisos declarados.</p>
			{/each}
		</div>
		<p class="note">
			La consola oculta lo que no procede, pero no es una frontera de seguridad: el permiso real lo
			comprueba la API en cada controlador. Por eso se leen, no se alternan.
		</p>
	</Card>
</section>

<style>
	section {
		display: flex;
		flex-direction: column;
		gap: 12px;
		max-width: 720px;
		padding: 14px 16px 28px;
	}

	.fields {
		display: grid;
		gap: 12px;
	}

	.theme {
		display: flex;
		align-items: center;
		gap: 12px;
		margin-top: 14px;
	}

	.label {
		display: block;
		margin-bottom: 6px;
		font-size: var(--fs-small);
		color: var(--tx2);
	}

	.theme .label {
		margin-bottom: 0;
	}

	.switches {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}

	.perms {
		display: flex;
		flex-wrap: wrap;
		gap: 4px;
	}

	.perm {
		padding: 2px 6px;
		border: 1px solid var(--bd2);
		border-radius: var(--r-tag);
		background: var(--bg-fill);
		font-size: var(--fs-small);
		color: var(--tx2);
	}

	.note {
		margin: 10px 0 0;
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.muted {
		margin: 0;
		font-size: var(--fs-body);
		color: var(--tx3);
	}
</style>
