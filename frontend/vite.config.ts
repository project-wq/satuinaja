import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 5173,
    proxy: {
      // Dev: /api → Laravel. Di produksi, reverse proxy (nginx) arahkan sama.
      '/api': { target: 'http://127.0.0.1:8123', changeOrigin: false },
      '/storage': { target: 'http://127.0.0.1:8123', changeOrigin: false },
    },
  },
})
