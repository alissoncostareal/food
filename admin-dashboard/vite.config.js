import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { VitePWA } from 'vite-plugin-pwa'
import path from 'path'

export default defineConfig({
  appType: 'spa',
  plugins: [
    vue(),
    VitePWA({
      registerType: 'autoUpdate',
      injectRegister: 'auto',
      includeAssets: ['favicon.png', 'logo-color.png', 'logo-white.png'],
      manifest: {
        id: '/',
        name: 'PartiuMenu · Painel do lojista',
        short_name: 'PartiuMenu',
        description: 'Gerencie pedidos, cardápio, integrações e sua loja online.',
        lang: 'pt-BR',
        theme_color: '#020617',
        background_color: '#020617',
        display: 'standalone',
        orientation: 'any',
        scope: '/',
        start_url: '/',
        categories: ['business', 'food'],
        icons: [
          {
            src: '/favicon.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'any',
          },
          {
            src: '/logo-color.png',
            sizes: '512x512',
            type: 'image/png',
            purpose: 'maskable',
          },
        ],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
        navigateFallback: '/index.html',
        navigateFallbackDenylist: [/^\/api/],
        cleanupOutdatedCaches: true,
      },
      devOptions: {
        enabled: false,
      },
    }),
  ],
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