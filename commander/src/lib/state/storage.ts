import { browser } from '$app/environment';

/**
 * Las claves de `localStorage`. La de tema aparece también, escrita a mano, en el script de
 * arranque de `app.html`: ese script corre antes del bundle y no puede importar nada.
 */
export enum StorageKey {
	Token = 'commander_token',
	Theme = 'commander.theme',
	/** Lo que el operador ya marcó como visto en la bandeja de atención. */
	AttentionSeen = 'commander.attn.v1'
}

/**
 * Lectura y escritura tolerantes: en modo privado, con almacenamiento bloqueado o durante el
 * prerender, esto no debe tumbar la consola. Un fallo aquí degrada, no rompe.
 */
export function readStored(key: StorageKey): string | null {
	if (!browser) return null;
	try {
		return localStorage.getItem(key);
	} catch {
		return null;
	}
}

export function writeStored(key: StorageKey, value: string): void {
	if (!browser) return;
	try {
		localStorage.setItem(key, value);
	} catch {
		/* almacenamiento bloqueado: la preferencia no sobrevive a la recarga y ya está */
	}
}

export function clearStored(key: StorageKey): void {
	if (!browser) return;
	try {
		localStorage.removeItem(key);
	} catch {
		/* ídem */
	}
}
