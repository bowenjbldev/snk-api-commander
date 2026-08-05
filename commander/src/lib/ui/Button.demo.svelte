<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Actions,
		name: 'Button',
		notes:
			'Dos ejes: la variante da la caja, el tono da el color. No hay variante «peligro».'
	};
</script>

<script lang="ts">
	import { Tone } from '$lib/model/enums';
	import Button from './Button.svelte';
	import { ButtonVariant, ControlSize } from './kinds';

	const VARIANTS = [
		[ButtonVariant.Primary, 'Provisionar instancia'],
		[ButtonVariant.Default, 'Descartar'],
		[ButtonVariant.Accent, '3 novedades']
	] as const;

	const SIZES = [
		[ControlSize.Regular, '32 · cabecera'],
		[ControlSize.Compact, '28 · barra'],
		[ControlSize.Bar, '26 · selección'],
		[ControlSize.Dense, '24 · fila']
	] as const;

	/** Los tres mandos de suministro, en el mismo orden y color que la línea de la fila. */
	const TONES = [
		[Tone.Warn, 'Iniciar preaviso'],
		[Tone.Ok, 'Restablecer suministro'],
		[Tone.Error, 'Cortar suministro']
	] as const;
</script>

{#each SIZES as [size, note] (size)}
	<div class="row">
		<span class="note">{note}</span>
		{#each VARIANTS as [variant, label] (variant)}
			<Button {variant} {size}>{label}</Button>
		{/each}
		<Button variant={ButtonVariant.Primary} {size} caret>Provisionar</Button>
		<Button {size} caret>Exportar</Button>
	</div>
{/each}

<div class="row">
	<span class="note">tono, en tinta</span>
	{#each TONES as [tone, label] (tone)}
		<Button {tone} size={ControlSize.Bar}>{label}</Button>
	{/each}
</div>

<div class="row">
	<span class="note">tono, relleno</span>
	{#each TONES as [tone, label] (tone)}
		<Button variant={ButtonVariant.Primary} {tone} size={ControlSize.Compact}>{label}</Button>
	{/each}
	<span class="note">el mando de confirmar de un modal</span>
</div>

<div class="row hovered">
	<span class="note">apuntado</span>
	{#each VARIANTS as [variant, label] (variant)}
		<Button {variant}>{label}</Button>
	{/each}
	{#each TONES as [tone, label] (tone)}
		<Button {tone}>{label}</Button>
	{/each}
	<span class="note">el tono se llena; el resto tira del primario</span>
</div>

<div class="row">
	<span class="note">sin permiso</span>
	{#each VARIANTS as [variant, label] (variant)}
		<Button {variant} disabled>{label}</Button>
	{/each}
</div>

<div class="row">
	<span class="note">entrada</span>
	<div class="form">
		<Button variant={ButtonVariant.Primary} size={ControlSize.Compact} type="submit" full>
			Entrar
		</Button>
	</div>
</div>

<style>
	.row {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		margin-bottom: 10px;
	}

	.row:last-child {
		margin-bottom: 0;
	}

	.note {
		flex: 0 0 96px;
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.note:last-child {
		flex: 0 1 auto;
	}

	.form {
		width: 200px;
	}

	/* No se puede forzar `:hover` desde fuera, pero sí pintar su resultado: estas tres líneas reusan
	   los tokens que el propio botón declara para apuntar, no copian sus colores. */
	.hovered :global(button) {
		background: var(--bg-h, var(--bg));
		border-color: var(--bd-h, var(--pri));
		color: var(--fg-h, var(--pri));
	}
</style>
