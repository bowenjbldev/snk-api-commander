<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Data,
		name: 'Alert',
		notes:
			'En caja arriba de una pantalla; en fila dentro de la bandeja de atención. El cuerpo es un motivo del sistema, así que va en mono.'
	};
</script>

<script lang="ts">
	import { Tone } from '$lib/model/enums';
	import Alert from './Alert.svelte';
	import Button from './Button.svelte';
	import Card from './Card.svelte';
	import IconButton from './IconButton.svelte';
	import { ControlSize } from './kinds';
</script>

<div class="stack">
	<Alert tone={Tone.Warn} title="Preaviso corriendo · 4 h 11 m" body="quota_exceeded:requests_day">
		{#snippet action()}
			<Button size={ControlSize.Dense}>Extender preaviso</Button>
		{/snippet}
	</Alert>

	<Alert tone={Tone.Error} title="Preaviso caducado" body="beacon-api · ya no está servido">
		{#snippet action()}
			<Button size={ControlSize.Dense}>Decidir</Button>
		{/snippet}
	</Alert>

	<Alert tone={Tone.Info} title="Alta en curso" body="aceptada hace 2 min · 202" />
	<Alert tone={Tone.Ok} title="Al día" body="sin nada abierto desde hace 6 d" />
	<Alert title="Solo catálogo" body="nunca se ha provisionado" />

	<Alert
		pending
		title="Dado de alta hace 52 d y sin desplegar"
		body="no existe packages/tinta-render en disco"
	>
		{#snippet action()}
			<Button size={ControlSize.Dense}>Comprobar en disco</Button>
		{/snippet}
	</Alert>
</div>

<div class="tray">
	<Card flush title="Bandeja de atención" note="4 abiertas">
		{#snippet aside()}
			<span class="hint">Cada línea exige una decisión</span>
		{/snippet}
		<Alert row tone={Tone.Error} title="Preaviso caducado" body="beacon-api · sin servir 2 h">
			{#snippet action()}
				<Button size={ControlSize.Dense}>Decidir</Button>
			{/snippet}
		</Alert>
		<Alert row tone={Tone.Warn} title="Contador al 96 %" body="atlas-crm · requests_day">
			{#snippet action()}
				<Button size={ControlSize.Dense}>Contadores</Button>
				<IconButton label="Marcar como visto">×</IconButton>
			{/snippet}
		</Alert>
		<Alert row tone={Tone.Info} title="Alta atascada" body="faro-cms · aceptada hace 2 d" />
		<Alert row title="Factura vencida" body="F-2026-0114 · 1.240 € · vence hace 9 d" />
	</Card>
</div>

<style>
	.stack {
		display: flex;
		flex-direction: column;
		gap: 8px;
	}

	.tray {
		margin-top: 12px;
	}

	.hint {
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
