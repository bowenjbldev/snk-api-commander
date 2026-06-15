/// <reference types="vitest/config" />
import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import { fileURLToPath, URL } from 'node:url'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5174,
    // Same-origin /api → backend HTTPS (self-signed). Avoids CORS + cert prompts in dev.
    proxy: {
      '/api': { target: 'https://localhost:8443', changeOrigin: true, secure: false },
    },
  },
  css: {
    preprocessorOptions: {
      scss: {
        // Modern Dart Sass compiler. loadPaths replaces the deprecated includePaths,
        // letting Carbon's bare `@use '@carbon/styles'` resolve from node_modules.
        loadPaths: ['node_modules'],
        // Carbon's SCSS still trips Dart Sass deprecations; silence node_modules noise.
        quietDeps: true,
        silenceDeprecations: ['global-builtin', 'import', 'legacy-js-api'],
      },
    },
  },
  build: {
    rollupOptions: {
      output: {
        // Split heavy vendors into cacheable chunks instead of one ~1MB file.
        manualChunks(id) {
          if (!id.includes('node_modules')) return
          if (id.includes('@carbon/charts')) return 'charts'
          if (id.includes('@carbon')) return 'carbon'
          if (/[\\/]node_modules[\\/](react|react-dom|react-router|scheduler)[\\/]/.test(id)) return 'react'
        },
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/test/setup.ts'],
    css: false,
  },
})
