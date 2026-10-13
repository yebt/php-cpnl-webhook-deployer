import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

// The PHP front controller serves dist/ and rewrites <base href> in index.html to the
// request base path ('/' behind a rewrite, '/index.php/' for PATH_INFO URLs), so every
// asset URL must be relative to that base.
export default defineConfig({
  base: './',
  plugins: [vue()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    assetsDir: 'assets',
    sourcemap: false,
    chunkSizeWarningLimit: 600,
  },
  server: {
    // Backend for `npm run dev`: `php -S 127.0.0.1:8080 -t public public/index.php`; override with DEPLOYER_API.
    proxy: {
      '/api': process.env.DEPLOYER_API ?? 'http://127.0.0.1:8080',
      '/hooks': process.env.DEPLOYER_API ?? 'http://127.0.0.1:8080',
    },
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts'],
  },
})
