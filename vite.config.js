import { defineConfig } from 'vite';

/**
 * Build config for the «Журнал ОС» frontend.
 *
 * The app is currently a no-build vanilla-JS SPA: classic <script> tags loaded
 * in order, cache-busted with ?v=N. This config introduces an opt-in bundling
 * step that concatenates + minifies + content-hashes those scripts into a
 * single asset, so a future cutover can drop the manual ?v=N versioning.
 *
 * It does NOT yet rewrite the live HTML — `npm run build` emits the bundle into
 * dist/ as a parallel artifact for verification. See FRONTEND_BUILD.md for the
 * planned cutover.
 */
export default defineConfig({
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    // We bundle classic side-effect scripts (no ESM exports), so a plain
    // hashed IIFE bundle is the right shape — not Vite's HTML-driven pipeline.
    lib: {
      entry: 'frontend/app.entry.js',
      name: 'OsJournalApp',
      formats: ['iife'],
      fileName: () => 'app.[hash].js',
    },
    rollupOptions: {
      output: {
        // Stable, content-hashed asset names (replaces ?v=N busting).
        entryFileNames: 'assets/app.[hash].js',
        assetFileNames: 'assets/[name].[hash][extname]',
      },
    },
    // Avoid inlining; we want a real hashed file on disk.
    assetsInlineLimit: 0,
  },
});
