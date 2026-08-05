<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Data,
		name: 'DefRow',
		notes:
			'Clave a la izquierda, dato a la derecha. Lo que el sistema sabe de una instancia y no se edita desde aquí.'
	};
</script>

<script lang="ts">
	import { Tone } from '$lib/model/enums';
	import Card from './Card.svelte';
	import DefRow from './DefRow.svelte';
	import Tag from './Tag.svelte';

	const TECH: [string, string][] = [
		['identificador', '17'],
		['clave', 'kilkana'],
		['base de datos', 'demo_kilkana'],
		['host', 'demo.snakepc.test'],
		['ruta', 'src/packages/Demo/Kilkana'],
		['alta', '12/07/2026 09:41'],
		['último cambio', '04/08/2026 18:02']
	];
</script>

<div class="grid">
	<Card title="Datos técnicos">
		{#each TECH as [label, value] (label)}
			<DefRow {label} {value} />
		{/each}
	</Card>

	<Card title="Mezcla">
		<DefRow label="cliente" value="Demo, S. L." mono={false} />
		<DefRow label="suministro">
			<Tag tone={Tone.Warn}>En preaviso</Tag>
		</DefRow>
		<DefRow label="motivo" value="payment_overdue" />
		<DefRow label="sin valor" value="—" />
	</Card>
</div>

<p class="note">
	El rótulo es palabra nuestra y el valor es del sistema: por eso uno va en versalitas de interfaz y
	el otro en mono. Un nombre comercial es la excepción, y para eso está <code class="mono"
		>mono={false}</code
	>.
</p>

<style>
	.grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
		gap: 12px;
		align-items: start;
	}

	.note {
		margin: 12px 0 0;
		font-size: var(--fs-small);
		color: var(--tx2);
	}

	code {
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
