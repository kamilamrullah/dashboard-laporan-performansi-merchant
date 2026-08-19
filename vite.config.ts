import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

// Mengatur Vite, React, dan pemrosesan utility class Tailwind untuk frontend.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    port: 3002,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8080',
        changeOrigin: true,
        rewrite: (path) => `/dashboard-laporan-performansi-merchant${path}`,
      },
    },
  },
});
