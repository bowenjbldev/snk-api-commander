<script lang="ts">
	interface Props {
		/** Serie ya muestreada. Se escala al máximo propio: es tendencia, no magnitud. */
		points: number[];
		width?: number;
		height?: number;
		/** Token de color, resuelto por quien llama. */
		color?: string;
		label?: string;
	}

	const { points, width = 48, height = 16, color, label }: Props = $props();

	// SVG a mano y no una gráfica por fila: con cien mil filas, una instancia de librería por fila
	// es inaceptable. Esto es un `path` y nada más.
	const path = $derived.by(() => {
		if (points.length < 2) return '';
		const max = Math.max(...points) || 1;
		const step = width / (points.length - 1);
		return points
			.map((value, i) => {
				const x = (i * step).toFixed(1);
				const y = (height - 1 - (value / max) * (height - 2)).toFixed(1);
				return `${i ? 'L' : 'M'}${x} ${y}`;
			})
			.join(' ');
	});
</script>

<svg {width} {height} viewBox="0 0 {width} {height}" role="img" aria-label={label ?? 'Tendencia'}>
	{#if path}
		<path d={path} fill="none" stroke={color ?? 'currentColor'} stroke-width="1.2" />
	{:else}
		<line x1="0" y1={height - 1} x2={width} y2={height - 1} stroke="currentColor" opacity="0.25" />
	{/if}
</svg>

<style>
	svg {
		display: block;
		flex: 0 0 auto;
		color: var(--tx4);
		overflow: visible;
	}
</style>
