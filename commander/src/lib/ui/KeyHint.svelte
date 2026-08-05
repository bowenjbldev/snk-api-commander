<script lang="ts">
	interface Props {
		keys: string;
		/**
		 * En el pie de una capa, donde la pista es lo único que hay y tiene que leerse: más pequeña
		 * pero menos apagada. Suelta en una cabecera va al revés —más grande y casi invisible—
		 * porque ahí compite con el rótulo que tiene al lado.
		 */
		micro?: boolean;
	}

	/** Un glifo de tecla es un dato del sistema, no lenguaje nuestro: va en mono. */
	const { keys, micro = false }: Props = $props();
</script>

<span class="mono key" class:micro>{keys}</span>

<style>
	.key {
		font-size: var(--fs-small);
		color: var(--tx4);
		white-space: nowrap;
	}

	.key.micro {
		font-size: var(--fs-micro);
		color: var(--tx3);
	}
</style>
