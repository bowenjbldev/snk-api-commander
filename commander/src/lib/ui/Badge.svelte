<script lang="ts">
	interface Props {
		/** Un recuento del sistema: mono, y por eso nunca lleva palabra dentro. */
		count: number | string;
		title?: string;
	}

	const { count, title }: Props = $props();
</script>

<span class="mono badge" {title}>{count}</span>

<style>
	/* El halo del color de la tarjeta separa la cifra del icono que lleva debajo: la posición la
	   pone quien la cuelga, aquí solo se resuelve la píldora. */
	.badge {
		display: inline-block;
		min-width: 14px;
		height: 14px;
		padding: 0 3px;
		border-radius: 7px;
		background: var(--er);
		box-shadow: 0 0 0 2px var(--bg-card);
		color: var(--on-pri);
		font-size: var(--fs-micro);
		line-height: 14px;
		text-align: center;
	}
</style>
