/**
 * Frontend build driver.
 *
 * The app has three HTML entry points with different, classic (non-ESM) script
 * sets. Each is bundled once into a stable-named, minified IIFE bundle in dist/:
 *
 *   frontend/app.entry.js   -> dist/app.js    (api/index.html)
 *   frontend/login.entry.js -> dist/login.js  (login.html)
 *   frontend/admin.entry.js -> dist/admin.js  (admin/index.html)
 *
 * Stable names (not content-hashed) keep working with the existing ?v=N
 * cache-busting (scripts/bump-assets.sh) and the File-Manager upload flow. The
 * built bundles are committed; CI (scripts/check-frontend-build.sh) rebuilds and
 * fails if any bundle is missing or invalid.
 *
 * We use esbuild (a single, fast dependency). Within CI (fresh `npm ci`) its
 * minified output is deterministic, so an unchanged source tree produces no diff.
 */
import { build } from 'esbuild';
import { rmSync } from 'node:fs';

const entries = [
  { entry: 'frontend/app.entry.js', file: 'dist/app.js' },
  { entry: 'frontend/login.entry.js', file: 'dist/login.js' },
  { entry: 'frontend/admin.entry.js', file: 'dist/admin.js' },
];

// Clean up front so a removed source never leaves a stale bundle behind.
rmSync('dist', { recursive: true, force: true });

for (const e of entries) {
  await build({
    entryPoints: [e.entry],
    outfile: e.file,
    bundle: true,
    minify: true,
    format: 'iife',
    charset: 'utf8',
    logLevel: 'warning',
  });
}

console.log('Frontend bundles built: dist/app.js, dist/login.js, dist/admin.js');
