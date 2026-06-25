/**
 * Frontend build driver.
 *
 * The app has three HTML entry points with different, classic (non-ESM) script
 * sets. IIFE library builds can only take a single entry each, so we drive
 * Vite's build API once per page, emitting stable-named bundles into dist/:
 *
 *   frontend/app.entry.js   -> dist/app.js    (api/index.html)
 *   frontend/login.entry.js -> dist/login.js  (login.html)
 *   frontend/admin.entry.js -> dist/admin.js  (admin/index.html)
 *
 * Stable names (not content-hashed) keep working with the existing ?v=N
 * cache-busting (scripts/bump-assets.sh) and the File-Manager upload flow. The
 * built bundles are committed; CI (scripts/check-frontend-build.sh) rebuilds and
 * fails if dist/ is stale.
 */
import { build } from 'vite';
import { rmSync } from 'node:fs';

const entries = [
  { entry: 'frontend/app.entry.js', name: 'OsJournalApp', file: 'app.js' },
  { entry: 'frontend/login.entry.js', name: 'OsJournalLogin', file: 'login.js' },
  { entry: 'frontend/admin.entry.js', name: 'OsJournalAdmin', file: 'admin.js' },
];

// Clean once up front; per-entry builds then append (emptyOutDir: false).
// Also drop Vite's optimize cache so output doesn't depend on prior cache state.
rmSync('dist', { recursive: true, force: true });
rmSync('node_modules/.vite', { recursive: true, force: true });

for (const e of entries) {
  await build({
    configFile: false,
    logLevel: 'warn',
    build: {
      outDir: 'dist',
      emptyOutDir: false,
      assetsInlineLimit: 0,
      minify: true,
      lib: {
        entry: e.entry,
        name: e.name,
        formats: ['iife'],
        fileName: () => e.file,
      },
    },
  });
}

console.log('Frontend bundles built: dist/app.js, dist/login.js, dist/admin.js');
