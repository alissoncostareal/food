import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'path' // Opcional: ajuda a organizar imports com '@'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      // Isso permite que você use '@' para referenciar a pasta 'src'
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    // Configurações do Servidor de Desenvolvimento
    host: '0.0.0.0', // Expõe o servidor para a sua rede local
    port: 5173,      // Porta padrão do Vite
    watch: {
      usePolling: true,   // Ativa a verificação contínua (Resolve o problema do WSL)
      interval: 100,      // Verifica mudanças a cada 100ms (ajuste se ficar pesado)
    },
  },
})