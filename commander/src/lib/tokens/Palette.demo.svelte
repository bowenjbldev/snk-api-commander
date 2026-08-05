<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Foundations,
		name: 'Color',
		notes:
			'El primario significa «esto se toca». El semántico, «esto es así». Nunca los dos en el mismo elemento.'
	};
</script>

<script lang="ts">
	import { CssVar } from './tokens';

	const ROWS: { title: string; vars: CssVar[] }[] = [
		{
			title: 'Primario · esto se toca',
			vars: [
				CssVar.Primary,
				CssVar.PrimaryHover,
				CssVar.PrimaryActive,
				CssVar.PrimaryBg,
				CssVar.PrimaryBorder,
				CssVar.OnPrimary
			]
		},
		{ title: 'Conectada', vars: [CssVar.Ok, CssVar.OkBg, CssVar.OkBorder] },
		{ title: 'Preaviso', vars: [CssVar.Warn, CssVar.WarnBg, CssVar.WarnBorder] },
		{ title: 'Corte', vars: [CssVar.Error, CssVar.ErrorBg, CssVar.ErrorBorder] },
		{ title: 'Alta en curso', vars: [CssVar.Info, CssVar.InfoBg, CssVar.InfoBorder] },
		{
			title: 'Series · nunca para un estado',
			vars: [CssVar.Series1, CssVar.Series2, CssVar.Series3, CssVar.Series4, CssVar.Series5]
		},
		{
			title: 'Planos',
			vars: [
				CssVar.BgApp,
				CssVar.BgCard,
				CssVar.BgElev,
				CssVar.BgFill,
				CssVar.BgFill2,
				CssVar.BgHover,
				CssVar.BgSel
			]
		},
		{
			title: 'Bordes y texto',
			vars: [
				CssVar.Border,
				CssVar.BorderSubtle,
				CssVar.Text,
				CssVar.Text2,
				CssVar.Text3,
				CssVar.Text4
			]
		}
	];
</script>

<div class="rows">
	{#each ROWS as row (row.title)}
		<section>
			<h4>{row.title}</h4>
			<div class="swatches">
				{#each row.vars as name (name)}
					<div class="swatch">
						<span class="chip" style:background="var({name})"></span>
						<span class="mono name">{name}</span>
					</div>
				{/each}
			</div>
		</section>
	{/each}
</div>

<style>
	.rows {
		display: flex;
		flex-direction: column;
		gap: 14px;
	}

	h4 {
		margin: 0 0 6px;
		font-size: var(--fs-micro);
		letter-spacing: var(--ls-caps);
		text-transform: uppercase;
		color: var(--tx2);
		font-weight: var(--fw-bold);
	}

	.swatches {
		display: flex;
		flex-wrap: wrap;
		gap: 6px;
	}

	.swatch {
		display: flex;
		align-items: center;
		gap: 6px;
		height: var(--h-ctl);
		padding: 0 8px 0 4px;
		border: 1px solid var(--bd2);
		border-radius: var(--r);
		background: var(--bg-card);
	}

	.chip {
		width: 16px;
		height: 16px;
		border-radius: var(--r-tag);
		border: 1px solid var(--bd);
	}

	.name {
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
