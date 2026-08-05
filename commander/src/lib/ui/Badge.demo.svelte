<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Data,
		name: 'Badge',
		notes:
			'Contador de aviso del campanario. Siempre rojo: si no exige mirar ahora, no lleva contador.'
	};
</script>

<script lang="ts">
	import Badge from './Badge.svelte';

	const COUNTS = [1, 4, 12, 99, '99+'];
</script>

<div class="row">
	{#each COUNTS as count (count)}
		<Badge {count} title={`${count} en la bandeja de atención`} />
	{/each}
</div>

<div class="row">
	<span class="bell">
		<svg
			width="16"
			height="16"
			viewBox="0 0 16 16"
			fill="none"
			stroke="currentColor"
			aria-hidden="true"
		>
			<path d="M4.2 11.4V7.4a3.8 3.8 0 0 1 7.6 0v4" stroke-width="1.3" stroke-linecap="round" />
			<line x1="2.8" y1="11.4" x2="13.2" y2="11.4" stroke-width="1.3" stroke-linecap="round" />
			<path d="M6.6 13.2a1.5 1.5 0 0 0 2.8 0" stroke-width="1.3" stroke-linecap="round" />
		</svg>
		<span class="pin"><Badge count={4} /></span>
	</span>
	<span class="note">Colgado del campanario: la posición la pone quien lo cuelga.</span>
</div>

<style>
	.row {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 12px;
	}

	.row + .row {
		margin-top: 14px;
	}

	.bell {
		position: relative;
		display: grid;
		place-items: center;
		width: 32px;
		height: 32px;
		color: var(--tx3);
	}

	.pin {
		position: absolute;
		top: 3px;
		right: 2px;
	}

	.note {
		font-size: var(--fs-small);
		color: var(--tx2);
	}
</style>
