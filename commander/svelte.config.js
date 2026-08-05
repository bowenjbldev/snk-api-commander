import adapter from '@sveltejs/adapter-static';
import { vitePreprocess } from '@sveltejs/vite-plugin-svelte';

/** @type {import('@sveltejs/kit').Config} */
export default {
	preprocess: vitePreprocess(),
	kit: {
		// SPA pura: un único index.html y enrutado en cliente. nginx.conf hace el mismo fallback
		// en producción, y el nginx del compose proxifica hacia aquí todo lo que no sea /api.
		adapter: adapter({ fallback: 'index.html', precompress: false, strict: false })
	}
};
