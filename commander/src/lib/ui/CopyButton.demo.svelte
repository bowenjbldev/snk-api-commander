<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Actions,
		name: 'CopyButton',
		notes: 'El dato ES el botón: se apunta al propio subdominio y al apuntar tira del primario.'
	};
</script>

<script lang="ts">
	import CopyButton from './CopyButton.svelte';

	let copied = $state('');
</script>

<div class="stack">
	<CopyButton value="atlas-crm.snakepc.test" oncopied={(v: string) => (copied = v)} />
	<CopyButton value="pkg_01HQ8XZK3M4N5P6Q7R8S9T0V" oncopied={(v: string) => (copied = v)} />
	<div class="narrow">
		<CopyButton value="un-identificador-larguísimo-que-no-cabe.snakepc.test" />
	</div>
	<span class="note"
		>{copied ? `Copiado: ${copied}` : 'La pantalla es quien avisa, no el botón.'}</span
	>
</div>

<style>
	.stack {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 8px;
	}

	.narrow {
		display: flex;
		width: 180px;
	}

	.note {
		font-size: var(--fs-small);
		color: var(--tx3);
	}
</style>
