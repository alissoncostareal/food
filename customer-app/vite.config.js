import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/postcss'

import { cloudflare } from "@cloudflare/vite-plugin";

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss(), cloudflare()],
  server: {
    host: '0.0.0.0', // Garante que o docker consiga expor a porta
    port: 5173,
    strictPort: true, // Garante que ele use sempre a 5173 interna
    hmr: {
      clientPort: 5174, // 👈 DIZ AO VITE QUE NO SEU MAC A PORTA É A 5174
    },
    watch: {
      usePolling: true, // 👈 ISSO AQUI FAZ O MILAGRE NO MAC/DOCKER
      interval: 100,    // Checa mudanças a cada 100ms
    }
  }
})