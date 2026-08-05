<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Surfaces,
		name: 'Toolbar',
		notes:
			'Filtros a la izquierda, recuento a la derecha. La barra de selección cambia de plano para acusar que hay filas marcadas.'
	};
</script>

<script lang="ts">
	import Button from './Button.svelte';
	import Card from './Card.svelte';
	import Select from './Select.svelte';
	import TextInput from './TextInput.svelte';
	import Toolbar from './Toolbar.svelte';
	import { Tone } from '$lib/model/enums';
	import { ButtonVariant, ControlSize } from './kinds';

	let query = $state('');
	let listing = $state('all');

	const LISTING = [
		{ value: 'all', label: 'Listado: todos' },
		{ value: 'published', label: 'Publicado' },
		{ value: 'hidden', label: 'Oculto' }
	];
</script>

<Card flush>
	<Toolbar>
		<TextInput
			bind:value={query}
			mono
			width="280px"
			placeholder="Buscar producto, cliente o identificador"
		/>
		<Select bind:value={listing} options={LISTING} />
		{#snippet trailing()}
			<Button variant={ButtonVariant.Accent} size={ControlSize.Compact}>3 novedades</Button>
			<span class="mono count">31 instancias</span>
		{/snippet}
	</Toolbar>

	<Toolbar selected>
		<span class="sel"><span class="mono">2</span> seleccionadas</span>
		<span class="rule">│</span>
		<Button size={ControlSize.Bar} tone={Tone.Warn}>Iniciar preaviso</Button>
		<Button size={ControlSize.Bar} tone={Tone.Ok}>Restablecer</Button>
		<Button size={ControlSize.Bar} tone={Tone.Error}>Cortar suministro</Button>
		{#snippet trailing()}
			<Button size={ControlSize.Bar}>Limpiar selección</Button>
		{/snippet}
	</Toolbar>

	<div class="placeholder">las filas de la tabla</div>
</Card>

<div class="apart">
	<Card flush>
		<Toolbar>
			<Select bind:value={listing} options={LISTING} />
		</Toolbar>
		<div class="placeholder">solo izquierda: no hay recuento que dar</div>
	</Card>

	<Card flush>
		<Toolbar>
			{#snippet trailing()}
				<span class="mono count">1.284 de 9.412</span>
			{/snippet}
		</Toolbar>
		<div class="placeholder">solo derecha: el filtro vive en la cabecera</div>
	</Card>
</div>

<style>
	.count {
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.sel {
		font-size: var(--fs-body);
		white-space: nowrap;
	}

	/* La raya separa el recuento de los mandos que actúan sobre él. */
	.rule {
		color: var(--bd);
	}

	.apart {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
		gap: 12px;
		margin-top: 12px;
	}

	.placeholder {
		padding: 18px 0;
		text-align: center;
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
