<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Layers,
		name: 'Modal · las cinco clases',
		notes:
			'La caja nunca cambia: 460 px, marca del tono en la cabecera, un solo mando en el pie. Lo que cambia es el cuerpo. El tono tiñe marca y mando a la vez, así que un pie rojo siempre significa lo mismo.'
	};
</script>

<script lang="ts">
	import SupplyLine from '$lib/domain/SupplyLine.svelte';
	import { SupplyShape, Tone } from '$lib/model/enums';
	import Button from './Button.svelte';
	import Field from './Field.svelte';
	import Modal from './Modal.svelte';
	import TextInput from './TextInput.svelte';
	import { ControlSize } from './kinds';

	// `ok` vacío = el pie no trae mando: la decisión está en el cuerpo y solo queda cerrar.
	const CLASSES = [
		{ id: 'list', title: 'Cortar 3 instancias', tone: Tone.Error, ok: 'Cortar el suministro' },
		{ id: 'hours', title: 'Iniciar preaviso', tone: Tone.Warn, ok: 'Iniciar preaviso' },
		{ id: 'confirm', title: 'Destruir instancia', tone: Tone.Error, ok: 'Destruir instancia' },
		{ id: 'decision', title: 'Factura vencida', tone: Tone.Error, ok: '' },
		{ id: 'plan', title: 'Cambiar de tarifa', tone: Tone.Neutral, ok: 'Cambiar a Escala' }
	];

	// La lista dice a QUÉ se le va a hacer, y cada fila lleva su suministro: el lote no es uniforme.
	const LINES = [
		{ name: 'atlas-crm · Trama', s: SupplyShape.Solid, t: Tone.Ok, note: 'Sirviendo' },
		{ name: 'kilkana · El Faro', s: SupplyShape.Shrink, t: Tone.Warn, note: 'Vence en 12 h' },
		{ name: 'atlas-crm · Norte', s: SupplyShape.Hollow, t: Tone.Error, note: 'Cortada' }
	];

	const CHOICES = [
		{ label: 'Dejar correr', note: 'Solo queda registrado.', tone: Tone.Neutral },
		{ label: 'Abrir preaviso de 72 h', note: 'Sirve con cabeceras de aviso.', tone: Tone.Warn },
		{ label: 'Cortar ya', note: 'Sus peticiones reciben 402 ya.', tone: Tone.Error }
	];

	const HOURS = [12, 24, 48, 72];

	let modal = $state('');
	let hours = $state(48);
	let typed = $state('');

	const open = $derived(CLASSES.find((c) => c.id === modal));
	const close = (): void => {
		modal = '';
	};
</script>

<div class="row">
	{#each CLASSES as item (item.id)}
		<Button size={ControlSize.Compact} onclick={() => (modal = item.id)}>{item.title}</Button>
	{/each}
</div>

{#if open}
	<Modal
		title={open.title}
		tone={open.tone}
		confirmLabel={open.ok}
		cancelLabel={open.id === 'decision' ? 'Cerrar' : 'Cancelar'}
		confirmDisabled={open.id === 'confirm' && typed !== '#41'}
		onconfirm={close}
		onclose={close}
	>
		{#if open.id === 'list'}
			<p>Cada una devolverá <span class="mono">402</span> en el acto, con el mensaje redactado.</p>
			<div class="rows">
				{#each LINES as line (line.name)}
					<div class="line">
						<SupplyLine
							supply={{ shape: line.s, tone: line.t, label: line.note, ratio: 0.4 }}
							height={14}
						/>
						<span class="mono name">{line.name}</span>
						<span class="note">{line.note}</span>
					</div>
				{/each}
			</div>
		{:else if open.id === 'hours'}
			<p>Sigue sirviendo, con cabeceras de aviso, hasta la fecha límite.</p>
			<p class="lbl">Plazo del preaviso</p>
			<div class="hours" role="group" aria-label="Plazo del preaviso">
				{#each HOURS as h (h)}
					<button type="button" class:on={hours === h} onclick={() => (hours = h)}>{h} h</button>
				{/each}
			</div>
		{:else if open.id === 'confirm'}
			<p>Se borran base de datos, usuario, ficheros y cuotas. Es irreversible.</p>
			<Field label="Escribe #41 para confirmar">
				{#snippet children(id)}<TextInput {id} bind:value={typed} mono width="100%" />{/snippet}
			</Field>
		{:else if open.id === 'decision'}
			<p>Cobro y suministro son campos distintos. Decide qué pasa con el suministro.</p>
			<div class="choices">
				{#each CHOICES as choice (choice.label)}
					<button type="button" class="choice" data-tone={choice.tone} onclick={close}>
						<span class="label">{choice.label}</span>
						<span class="note">{choice.note}</span>
					</button>
				{/each}
			</div>
		{:else}
			<p>Cierra el término vigente y abre otro con instantánea de nombre y precio.</p>
		{/if}
	</Modal>
{/if}

<style>
	.row {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
	}

	.rows {
		max-height: 152px;
		margin-bottom: 12px;
		border: 1px solid var(--bd2);
		border-radius: var(--r);
		overflow: auto;
	}
	.line {
		display: flex;
		align-items: center;
		gap: 8px;
		padding: 6px 8px;
		border-bottom: 1px solid var(--bd2);
	}
	.name {
		flex: 1;
		font-size: var(--fs-small);
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}
	.note {
		color: var(--tx3);
		font-size: var(--fs-small);
		white-space: nowrap;
	}

	.lbl {
		margin: 0 0 6px;
		color: var(--tx2);
		font-size: var(--fs-small);
		letter-spacing: var(--ls-caps);
		text-transform: uppercase;
	}
	/* Botones sueltos con separación, no una caja segmentada: aquí se elige una cifra, no un modo. */
	.hours {
		display: flex;
		gap: 6px;
		margin-bottom: 12px;
	}
	.hours button {
		height: var(--h-ctl);
		padding: 0 12px;
		border: 1px solid var(--bd);
		border-radius: var(--r);
		background: var(--bg-card);
		font-family: var(--font-mono);
		font-size: var(--fs-body);
	}
	.hours .on {
		border-color: var(--pri-bd);
		background: var(--pri-bg);
		color: var(--pri);
	}

	/* Cada camino se nombra por su efecto y lo dice en su color: por eso el pie no trae mando. */
	.choices {
		display: flex;
		flex-direction: column;
		gap: 6px;
		margin-bottom: 12px;
	}
	.choice {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 2px;
		padding: 8px 10px;
		border: 1px solid var(--bd);
		border-radius: var(--r);
		background: var(--bg-card);
		text-align: left;
	}
	.choice:hover {
		border-color: var(--pri);
		background: var(--bg-hover);
	}
	.label {
		font-size: var(--fs-body);
		font-weight: var(--fw-bold);
	}
	.choice[data-tone='warn'] .label {
		color: var(--wn);
	}
	.choice[data-tone='error'] .label {
		color: var(--er);
	}
</style>
