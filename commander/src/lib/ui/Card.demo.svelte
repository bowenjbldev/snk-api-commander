<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Surfaces,
		name: 'Card',
		notes:
			'Jerarquía de planos por relleno, no por sombra. Una tabla va a sangre dentro de su tarjeta: el ancho es el recurso escaso.'
	};
</script>

<script lang="ts">
	import Button from './Button.svelte';
	import Card from './Card.svelte';
	import { ButtonVariant, ControlSize } from './kinds';
</script>

<div class="grid">
	<Card title="Peticiones por instancia" note="agregados de hace 4 min">
		{#snippet aside()}
			<span class="hint">apunte a la derecha</span>
		{/snippet}
		<div class="placeholder">cuerpo con relleno de 12</div>
	</Card>

	<Card title="Tarifas del producto" note="3 activas">
		{#snippet actions()}
			<Button variant={ButtonVariant.Default} size={ControlSize.Compact}>Dar de alta tarifa</Button>
		{/snippet}
		<div class="placeholder">cabecera con mando a la derecha</div>
	</Card>

	<Card flush title="Flota" note="31 instancias">
		<div class="placeholder edge">cuerpo a sangre, para tablas</div>
	</Card>

	<Card>
		<div class="placeholder">sin cabecera</div>
	</Card>

	<Card
		flush
		title="Instancias de este producto"
		note="3"
		subhead="Los metadatos de catálogo son comunes a estas instancias."
	>
		<div class="placeholder edge">franja de advertencia bajo la cabecera</div>
	</Card>

	<Card title="Datos técnicos">
		{#snippet aside()}
			<span class="hint">apunte</span>
		{/snippet}
		{#snippet actions()}
			<Button variant={ButtonVariant.Default} size={ControlSize.Compact}>Editar</Button>
		{/snippet}
		<div class="placeholder">apunte y mando a la vez</div>
	</Card>
</div>

<style>
	.grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
		gap: 12px;
		align-items: start;
	}

	.hint {
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.placeholder {
		padding: 18px 0;
		text-align: center;
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.edge {
		background: var(--bg-fill);
		border-bottom: 1px solid var(--bd2);
	}
</style>
