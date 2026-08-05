<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Foundations,
		name: 'Alturas y radios',
		notes: 'Todo en pasos de 4. La densidad es una propiedad del producto, no una preferencia.'
	};
</script>

<script lang="ts">
	const HEIGHTS = [
		{ token: '--h-ctl-dense', px: 24, use: 'control compacto' },
		{ token: '--h-ctl-bar', px: 26, use: 'control dentro de una barra' },
		{ token: '--h-shell-foot', px: 26, use: 'pie del armazón' },
		{ token: '--h-ctl', px: 28, use: 'control · fila compacta' },
		{ token: '--h-row', px: 32, use: 'fila de tabla · pestaña' },
		{ token: '--h-card-head', px: 40, use: 'cabecera de tarjeta' },
		{ token: '--h-app-head', px: 48, use: 'cabecera de aplicación · raíl' }
	];

	const RADII = [
		{ token: '--r-line', px: 1, use: 'línea de suministro' },
		{ token: '--r-tag', px: 2, use: 'etiquetas' },
		{ token: '--r', px: 4, use: 'general' }
	];
</script>

<div class="bars">
	{#each HEIGHTS as h (h.token)}
		<div class="row">
			<span class="bar" style:height="var({h.token})"></span>
			<span class="mono px">{h.px}</span>
			<span class="mono token">{h.token}</span>
			<span class="use">{h.use}</span>
		</div>
	{/each}
</div>

<div class="radii">
	{#each RADII as r (r.token)}
		<div class="radius">
			<span class="box" style:border-radius="var({r.token})"></span>
			<span class="mono px">{r.px}</span>
			<span class="use">{r.use}</span>
		</div>
	{/each}
</div>

<style>
	.bars {
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	.row {
		display: flex;
		align-items: center;
		gap: 10px;
	}

	.bar {
		width: 120px;
		background: var(--pri-bg);
		border-left: 3px solid var(--pri);
		border-radius: var(--r-tag);
	}

	.px {
		width: 24px;
		text-align: right;
		font-size: var(--fs-small);
		color: var(--tx);
	}

	.token {
		width: 130px;
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.use {
		font-size: var(--fs-body);
		color: var(--tx2);
	}

	.radii {
		display: flex;
		gap: 16px;
		margin-top: 14px;
	}

	.radius {
		display: flex;
		align-items: center;
		gap: 6px;
	}

	.box {
		width: 28px;
		height: 28px;
		background: var(--bg-fill2);
		border: 1px solid var(--bd);
	}
</style>
