<script lang="ts">
	// Marcador de la Fase 1. La Fase 2 lo sustituye por la redirección al Panorama y el armazón.
	import { Route } from '$lib/nav/routes';
</script>

<main>
	<h1>Commander</h1>
	<p>Cimientos montados. La guía viva vivirá en <code class="mono">{Route.UiKit}</code>.</p>
</main>

<style>
	main {
		padding: 24px;
	}

	h1 {
		margin: 0 0 8px;
		font-size: var(--fs-title);
		font-weight: var(--fw-bold);
		letter-spacing: var(--ls-title);
	}

	p {
		margin: 0;
		font-size: var(--fs-body);
		color: var(--tx2);
	}

	code {
		font-size: var(--fs-small);
		color: var(--tx);
	}
</style>
