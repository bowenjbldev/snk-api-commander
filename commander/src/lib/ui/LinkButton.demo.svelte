<script module lang="ts">
	import { DemoGroup, type DemoMeta } from '$lib/uikit/demo-meta';

	export const meta: DemoMeta = {
		group: DemoGroup.Actions,
		name: 'LinkButton',
		notes: 'La salida lateral al pie de un campo. Al apuntar se subraya, como cualquier enlace.'
	};
</script>

<script lang="ts">
	import LinkButton from './LinkButton.svelte';
</script>

<div class="stack">
	<LinkButton>Dar de alta un cliente</LinkButton>
	<LinkButton>Ver la tarifa vigente</LinkButton>
	<LinkButton disabled>Sin permiso para dar de alta</LinkButton>
</div>

<style>
	.stack {
		display: flex;
		flex-direction: column;
		align-items: flex-start;
		gap: 6px;
	}
</style>
