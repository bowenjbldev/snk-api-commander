/// <reference types="vitest/config" />
import { sveltekit } from '@sveltejs/kit/vite';
import { defineConfig } from 'vitest/config';

// En docker, nginx termina TLS en 443 y proxifica el websocket de HMR hacia commander-web:80
// (docker/nginx/conf.d/commander.conf.template). El cliente de Vite tiene que apuntar al puerto
// público, no al interno del contenedor. Fuera de docker: COMMANDER_HMR_CLIENT_PORT=5173.
const hmrClientPort = Number(process.env.COMMANDER_HMR_CLIENT_PORT ?? 443);

export default defineConfig({
	plugins: [sveltekit()],
	server: {
		host: true,
		allowedHosts: ['commander.snakepc.test'],
		hmr: { clientPort: hmrClientPort }
	},
	build: {
		rollupOptions: {
			output: {
				// ECharts pesa lo que pesa: en su propio chunk para que no lastre el arranque.
				manualChunks: (id: string) =>
					/node_modules[\\/](echarts|zrender)[\\/]/.test(id) ? 'charts' : undefined
			}
		}
	},
	test: {
		// Los módulos con runas tienen que pasar por el compilador de Svelte, y eso solo ocurre con
		// la extensión `.svelte.ts`. Sus tests la llevan también.
		include: ['src/**/*.test.ts', 'src/**/*.test.svelte.ts'],
		environment: 'jsdom',
		globals: true,
		setupFiles: ['./src/test/setup.ts']
	}
});
