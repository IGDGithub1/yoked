import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import { rmSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

// package.json is type: module, so __dirname does not exist here.
const here = dirname(fileURLToPath(import.meta.url))

/**
 * Clears ../public_html/assets before a build.
 *
 * emptyOutDir has to stay false (see below), so without this every build leaves
 * the previous build's hashed bundles behind and they get committed forever.
 * Scoped to assets/ — the only directory Vite owns.
 */
function cleanAssets() {
  return {
    name: 'yoked-clean-assets',
    apply: 'build',
    buildStart() {
      rmSync(resolve(here, '../public_html/assets'), { recursive: true, force: true })
    },
  }
}

/**
 * Builds straight into ../public_html, which is committed and deployed as
 * static files — there is no Node on the SiteGround host.
 *
 * index.html is emitted alongside hashed assets. The .htaccess caches hashed
 * files forever and index.html not at all, which is what makes a deploy
 * actually reach users; see docs/design/DESIGN.md and the .htaccess comments.
 */
export default defineConfig({
  plugins: [cleanAssets(), react()],

  root: '.',

  build: {
    outDir: '../public_html',
    // public_html also holds .htaccess, api/, icons/ and the service worker,
    // none of which Vite knows about. Emptying it would delete all of them.
    emptyOutDir: false,
    assetsDir: 'assets',
    sourcemap: false,
    rollupOptions: {
      output: {
        // Content hashes let the aggressive cache rule be safe.
        entryFileNames: 'assets/[name]-[hash].js',
        chunkFileNames: 'assets/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
      },
    },
  },

  server: {
    // `npm run dev` talks to the live API rather than a mock, so the client is
    // always developed against the real contract. Cookies are the session, so
    // credentials have to be forwarded.
    proxy: {
      '/api': {
        target: 'https://yoked.lil-boxes.com',
        changeOrigin: true,
        secure: true,
      },
    },
  },
})
