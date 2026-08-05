<script lang="ts">
	import { trapFocus } from '$lib/ui/focus-trap';
	import KeyHint from '$lib/ui/KeyHint.svelte';
	import type { Command } from './command';

	interface Props {
		commands: Command[];
		onclose: () => void;
	}

	const { commands, onclose }: Props = $props();

	let query = $state('');
	let cursor = $state(0);

	const matches = $derived.by(() => {
		const needle = query.trim().toLowerCase();
		const hits = needle
			? commands.filter((c) =>
					`${c.kind} ${c.label} ${c.hint ?? ''}`.toLowerCase().includes(needle)
				)
			: commands;
		return hits.slice(0, 40);
	});

	$effect(() => {
		// Cambiar la búsqueda devuelve el cursor arriba: si no, se ejecuta lo que no se ve.
		void query;
		cursor = 0;
	});

	function onkeydown(event: KeyboardEvent): void {
		if (event.key === 'Escape') {
			onclose();
			return;
		}
		if (event.key === 'ArrowDown') {
			event.preventDefault();
			cursor = Math.min(cursor + 1, matches.length - 1);
		} else if (event.key === 'ArrowUp') {
			event.preventDefault();
			cursor = Math.max(cursor - 1, 0);
		} else if (event.key === 'Enter') {
			event.preventDefault();
			matches[cursor]?.run();
			onclose();
		}
	}
</script>

<!-- El equivalente de teclado del clic en el velo es Escape, atado en el diálogo. -->
<div class="scrim" role="presentation" onclick={onclose}>
	<div
		class="palette"
		role="dialog"
		aria-modal="true"
		aria-label="Paleta de comandos"
		tabindex="-1"
		use:trapFocus
		{onkeydown}
		onclick={(event: MouseEvent) => {
			event.stopPropagation();
		}}
	>
		<!-- La entrada no es un control dentro de una caja: es la caja. Por eso no lleva borde
		     propio ni la altura de control, sino la del renglón de la capa. -->
		<!-- svelte-ignore a11y_autofocus -->
		<input
			class="mono"
			bind:value={query}
			autofocus
			aria-label="Buscar"
			placeholder="Salta a una instancia, un cliente o una factura · ejecuta un mando"
		/>

		<div class="list">
			{#each matches as command, index (command.id)}
				<button
					type="button"
					class:active={index === cursor}
					onmouseenter={() => (cursor = index)}
					onclick={() => {
						command.run();
						onclose();
					}}
				>
					<span class="kind" data-kind={command.kind}>{command.kind}</span>
					<span class="label">{command.label}</span>
					{#if command.hint}<span class="mono hint">{command.hint}</span>{/if}
				</button>
			{/each}

			{#if matches.length === 0}
				<div class="empty">Nada coincide con eso.</div>
			{/if}
		</div>

		<div class="foot">
			<KeyHint micro keys="↑↓ moverse" />
			<KeyHint micro keys="⏎ ejecutar" />
			<KeyHint micro keys="esc cerrar" />
		</div>
	</div>
</div>

<style>
	.scrim {
		position: fixed;
		inset: 0;
		z-index: 70;
		display: flex;
		align-items: flex-start;
		justify-content: center;
		padding-top: 96px;
		background: var(--scrim);
	}

	.palette {
		width: 620px;
		max-width: 92vw;
		border: 1px solid var(--bd);
		border-radius: var(--r);
		background: var(--bg-elev);
		box-shadow: var(--sh);
		overflow: hidden;
	}

	input {
		width: 100%;
		height: 40px;
		padding: 0 12px;
		border: 0;
		border-bottom: 1px solid var(--bd2);
		background: transparent;
		font-size: var(--fs-card);
	}

	input::placeholder {
		color: var(--tx3);
	}

	.list {
		max-height: 340px;
		overflow: auto;
	}

	button {
		display: grid;
		grid-template-columns: 104px minmax(0, 1fr) auto;
		align-items: center;
		gap: 10px;
		width: 100%;
		height: var(--h-row);
		padding: 0 12px;
		border: 0;
		background: transparent;
		text-align: left;
	}

	/* Apuntar y estar gobernado por el cursor no son lo mismo, y el sistema ya tiene dos colores
	   para eso: el de apuntar es pasajero, el de selección dice qué se ejecuta con ⏎. */
	button:hover {
		background: var(--bg-hover);
	}

	button.active {
		background: var(--bg-sel);
	}

	/* El tipo va en color: con cuarenta filas mezcladas se reconoce antes por el tono que leyendo
	   la palabra. Cliente y factura tiran de la paleta de series porque no son un estado. */
	.kind {
		font-size: var(--fs-micro);
		text-transform: uppercase;
		letter-spacing: var(--ls-caps);
		color: var(--tx3);
	}
	.kind[data-kind='Instancia'] {
		color: var(--pri);
	}
	.kind[data-kind='Cliente'] {
		color: var(--s2);
	}
	.kind[data-kind='Factura'] {
		color: var(--s4);
	}
	.kind[data-kind='Control'] {
		color: var(--er);
	}

	.label {
		font-size: var(--fs-body);
		color: var(--tx);
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.hint {
		font-size: var(--fs-small);
		color: var(--tx3);
	}

	.empty {
		padding: 28px;
		text-align: center;
		font-size: var(--fs-body);
		color: var(--tx3);
	}

	.foot {
		display: flex;
		align-items: center;
		gap: 12px;
		height: var(--h-shell-foot);
		padding: 0 12px;
		border-top: 1px solid var(--bd2);
		background: var(--bg-fill);
	}
</style>
