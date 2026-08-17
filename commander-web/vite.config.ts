import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

const src = (path: string) => fileURLToPath(new URL(path, import.meta.url));

export default defineConfig({
  plugins: [react()],
  // Mismos alias que `paths` en tsconfig: si cambia uno, cambia el otro.
  resolve: {
    alias: {
      '@app': src('./src/app'),
      '@features': src('./src/features'),
      '@shared': src('./src/shared'),
      '@services': src('./src/services'),
    },
  },
  server: {
    host: true,
    port: 5173,
    // El proxy de nginx conserva el Host `commander.<BASE_DOMAIN>`; sin esto Vite lo rechaza.
    allowedHosts: true,
  },
  css: {
    preprocessorOptions: {
      scss: {
        // Carbon todavía emite avisos de Sass heredados; no son accionables desde aquí.
        quietDeps: true,
        silenceDeprecations: ['global-builtin', 'import', 'color-functions', 'legacy-js-api'],
      },
    },
  },
});
