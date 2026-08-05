import { browser } from '$app/environment';
import { Theme } from '$lib/model/enums';
import { readStored, StorageKey, writeStored } from './storage';

function stored(): Theme {
	const raw = readStored(StorageKey.Theme);
	return raw === Theme.Dark ? Theme.Dark : Theme.Light;
}

let current = $state<Theme>(stored());

/**
 * El tema, en un sitio. El primer pintado ya lo aplicó el script de arranque de `app.html`; esto
 * solo lo cambia después. El atributo vive en `<html>` porque los tokens cuelgan de `:root`.
 */
export const theme = {
	get value(): Theme {
		return current;
	},

	set(next: Theme): void {
		current = next;
		writeStored(StorageKey.Theme, next);
		if (browser) document.documentElement.dataset['theme'] = next;
	},

	toggle(): void {
		this.set(current === Theme.Dark ? Theme.Light : Theme.Dark);
	}
};
