<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Data,
		name: 'Tag',
		notes:
			'Una etiqueta de estado no es un botón: nunca lleva el primario, aunque sea pulsable. Ancho mínimo fijo para que la columna cuadre.'
	};
</script>

<script lang="ts">
	import { Tone } from '$lib/model/enums';
	import Tag from './Tag.svelte';

	const STATES = [
		[Tone.Ok, 'Conectada', false],
		[Tone.Warn, 'En preaviso', false],
		[Tone.Error, 'Cortada', false],
		[Tone.Error, 'Caducado', false],
		[Tone.Info, 'Provisionando', false],
		[Tone.Neutral, 'Solo catálogo', false],
		[Tone.Neutral, 'Dada de baja', false],
		[Tone.Neutral, 'Sin desplegar', true],
		[Tone.Neutral, 'Sin dar de alta', true]
	] as const;

	const TONES = [Tone.Ok, Tone.Warn, Tone.Error, Tone.Info, Tone.Neutral] as const;
</script>

<div class="column">
	{#each STATES as [tone, label, pending], i (i)}
		<Tag {tone} {pending} title={label}>{label}</Tag>
	{/each}
</div>

<p class="note">
	Los dos últimos van con borde de trazos: son un paso pendiente de alguien, no una avería del
	suministro.
</p>

<div class="row">
	{#each TONES as tone (tone)}
		<Tag {tone}>{tone}</Tag>
	{/each}
</div>
<div class="row">
	{#each TONES as tone (tone)}
		<Tag {tone} pending>{tone}</Tag>
	{/each}
</div>

<p class="note">
	Los cinco tonos, continuo y a trazos. El trazo no sustituye al tono: se le suma, porque
	«pendiente» y «averiado» son preguntas distintas.
</p>

<div class="row">
	<Tag>ok</Tag>
	<Tag tone={Tone.Warn}>una etiqueta larga de verdad</Tag>
</div>

<p class="note">
	Ancho mínimo de 62 abajo, ninguno arriba: la etiqueta corta no encoge la columna.
</p>

<style>
	/* En columna a propósito: así se ve que todas cuadran al mismo ancho. */
	.column {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 4px;
	}

	.row {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
		margin-top: 10px;
	}

	.note {
		margin: 10px 0 0;
		font-size: var(--fs-small);
		color: var(--tx2);
	}
</style>
