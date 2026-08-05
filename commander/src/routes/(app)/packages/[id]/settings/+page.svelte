<script lang="ts">
	import { page } from '$app/state';
	import { control } from '$lib/api/resources';
	import { date } from '$lib/format';
	import { Situation, Tone } from '$lib/model/enums';
	import { displayName, situationOf } from '$lib/model/situation';
	import { navigate, Route } from '$lib/nav/routes';
	import { clock } from '$lib/state/clock.svelte';
	import { data } from '$lib/state/data.svelte';
	import { session } from '$lib/state/session.svelte';
	import { toasts } from '$lib/state/toasts.svelte';
	import Button from '$lib/ui/Button.svelte';
	import Card from '$lib/ui/Card.svelte';
	import Field from '$lib/ui/Field.svelte';
	import Modal from '$lib/ui/Modal.svelte';
	import TextInput from '$lib/ui/TextInput.svelte';

	const id = $derived(Number(page.params['id']));
	const pkg = $derived(data.packages.find((p) => p.id === id) ?? null);
	const situation = $derived(pkg ? situationOf(pkg, clock.now) : null);
	const canDelete = $derived(session.can('delete packages'));

	let confirming = $state(false);
	let typed = $state('');
	let busy = $state(false);

	// Exige escribir el identificador exacto: destruye base de datos, ficheros, registros y cuotas.
	const matches = $derived(pkg !== null && typed.trim() === String(pkg.id));

	async function destroy(): Promise<void> {
		if (!pkg || !matches) return;
		busy = true;
		try {
			await control.destroy(pkg.id);
			toasts.push(`Baja de ${displayName(pkg)} encolada`, Tone.Warn);
			confirming = false;
			await data.load();
			navigate(Route.Fleet);
		} catch {
			toasts.push('No se pudo dar de baja la instancia', Tone.Error);
		} finally {
			busy = false;
		}
	}
</script>

{#if pkg}
	<section>
		<Card title="Datos técnicos">
			<dl>
				<dt>base de datos</dt>
				<dd class="mono">oculta — las credenciales nunca se muestran</dd>
				<dt>alta</dt>
				<dd class="mono">{pkg.provisioned_at ? date(pkg.provisioned_at) : 'sin provisionar'}</dd>
				<dt>último cambio de estado</dt>
				<dd class="mono">{pkg.changed_at ? date(pkg.changed_at) : '—'}</dd>
				<dt>motivo actual</dt>
				<dd class="mono">{pkg.reason ?? '—'}</dd>
			</dl>
		</Card>

		{#if situation === Situation.Live}
			<Card title="Zona irreversible">
				<p class="warn">
					Dar de baja destruye base de datos, usuario, ficheros, registros y cuotas, y corta el
					tráfico en el acto. Conserva la fila, los metadatos de catálogo y todo el histórico de
					facturación.
				</p>
				<Button
					tone={Tone.Error}
					disabled={!canDelete}
					onclick={() => {
						typed = '';
						confirming = true;
					}}
				>
					Dar de baja la instancia
				</Button>
				{#if !canDelete}
					<p class="note">Sin permiso para dar de baja productos.</p>
				{/if}
			</Card>
		{/if}
	</section>

	{#if confirming}
		<Modal
			title="Dar de baja {displayName(pkg)}"
			tone={Tone.Error}
			confirmLabel={busy ? 'Encolando…' : 'Destruir instancia'}
			confirmDisabled={!matches || busy}
			onconfirm={() => void destroy()}
			onclose={() => (confirming = false)}
		>
			<p>Es irreversible y corta el tráfico en el acto.</p>
			<Field label="Escribe el identificador {pkg.id} para confirmar">
				{#snippet children(fid)}<TextInput
						id={fid}
						bind:value={typed}
						mono
						width="100%"
					/>{/snippet}
			</Field>
		</Modal>
	{/if}
{/if}

<style>
	section {
		display: flex;
		flex-direction: column;
		gap: 12px;
		max-width: 640px;
		padding: 12px 16px 28px;
	}

	dl {
		display: grid;
		grid-template-columns: 200px minmax(0, 1fr);
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

	p {
		margin: 0 0 12px;
		font-size: var(--fs-body);
		color: var(--tx2);
		line-height: 1.6;
	}

	.warn {
		color: var(--er);
	}

	.note {
		margin: 8px 0 0;
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
