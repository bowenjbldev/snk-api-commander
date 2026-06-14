import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// SnakeCloud CMS — standalone operator admin panel (Carbon v11).
// Runs independently from the Laravel app in ../src.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 5174,
    host: true,
  },
  css: {
    preprocessorOptions: {
      scss: {
        // Carbon v11 still emits a few legacy Sass warnings; silence the noise.
        quietDeps: true,
        silenceDeprecations: ['global-builtin', 'import', 'legacy-js-api'],
      },
    },
  },
})
