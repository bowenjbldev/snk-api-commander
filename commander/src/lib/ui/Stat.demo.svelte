<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Data,
		name: 'Stat',
		notes:
			'La cifra siempre en mono y tabular: es una lectura. El apunte mezcla cifra en mono y palabra en interfaz.'
	};
</script>

<script lang="ts">
	import { Tone } from '$lib/model/enums';
	import Stat from './Stat.svelte';
</script>

<div class="grid">
	<Stat
		label="Instancias vivas"
		value="24"
		subValue="31"
		subText="provisionadas"
		onclick={() => {}}
		hint="Ver en Flota las instancias conectadas"
	/>
	<Stat
		label="En preaviso"
		value="2"
		subValue="1"
		subText="caducado"
		tone={Tone.Warn}
		onclick={() => {}}
		hint="Ver en Flota las instancias en preaviso"
	/>
	<Stat
		label="Cortadas"
		value="1"
		subValue="402"
		subText="de respuesta"
		tone={Tone.Error}
		onclick={() => {}}
	/>
	<Stat label="Alta en curso" value="3" subText="aceptadas" tone={Tone.Info} onclick={() => {}} />
	<Stat label="Al día" value="28" subText="sin incidencia" tone={Tone.Ok} onclick={() => {}} />
	<Stat
		label="Cortadas"
		value="0"
		subText="nada que decidir"
		tone={Tone.Error}
		dim
		onclick={() => {}}
	/>
</div>

<p class="note">
	La última va apagada: la cifra es cero, y un cero con color de avería miente. El tono se conserva
	para cuando deje de serlo.
</p>

<div class="grid strip">
	<Stat label="Peticiones" value="1,7M" />
	<Stat label="Proporción de error" value="0,8 %" tone={Tone.Warn} />
	<Stat label="Pico por hora" value="94,2K" />
	<Stat label="Instancias con tráfico" value="24" />
</div>

<p class="note">
	Sin <code class="mono">onclick</code> no hay galón ni apunte: es una lectura de tira, no una puerta.
</p>

<style>
	.grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(172px, 1fr));
		gap: 10px;
	}

	.strip {
		margin-top: 12px;
	}

	.note {
		margin: 10px 0 0;
		font-size: var(--fs-small);
		color: var(--tx2);
	}

	code {
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
