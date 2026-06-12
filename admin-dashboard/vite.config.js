import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path' // Opcional: ajuda a organizar imports com '@'

export default defineConfig({
  appType: 'spa',
  plugins: [vue()],
  resolve: {
    alias: {
      // Isso permite que você use '@' para referenciar a pasta 'src'
      '@': path.resolve(__dirname, './src'),
    },
  },
  preview: {
    host: '0.0.0.0',
    port: 4173,
  },
  server: {
    host: '0.0.0.0',
    port: 5175,
    strictPort: false,
    hmr: {
      // Docker expõe o admin em localhost:5175 (container roda na porta 80)
      clientPort: 5175,
    },
    watch: {
      usePolling: true,
      interval: 100,
    },
  },
})