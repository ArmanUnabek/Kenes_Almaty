# Frontend build (Vite) — «Журнал ОС»

The SPA was historically a **no-build** vanilla-JS app: classic `<script>` tags
loaded in a fixed order and cache-busted with `?v=N`. It now ships **bundled**:
each HTML entry point loads a single minified bundle built from those scripts.
`?v=N` busting (`scripts/bump-assets.sh`) is retained on the bundle tag.

## Pages → bundles

| Page | Bundle | Sources (in load order) |
|---|---|---|
| `api/index.html` | `/dist/app.js` | `csrf-handler` → `api/js/utils,i18n,i18n-dom,core,members-ui,dashboard,letters-ui,events-ui,app-shell,session` → `api/app.js` → `api/js/search,dashboard-enhanced,notifications-ui,calendar,mobile-ui` |
| `admin/index.html` | `/dist/admin.js` | `csrf-handler` → `api/js/utils` → `admin/admin-i18n` → `admin/admin.js` |
| `login.html` | `/dist/login.js` | `csrf-handler` → `js/login-i18n` |

The import order for each lives in `frontend/{app,login,admin}.entry.js` (side-effect
imports — no ESM rewrite; the scripts keep attaching to `window`).

**Still loaded as their own tags** (not bundled): `config_public.php`
(server-rendered globals, must precede the bundle), `/js/site-config.js`,
`/js/site-docs.js`, and the Bootstrap / Chart.js CDN bundles.

## Build

```bash
npm install                  # installs vite (dev dependency only)
npm run build                # rebuilds dist/{app,login,admin}.js
```

The built bundles in `dist/` **are committed** so deploys work via File Manager
(no server-side build needed); `deploy.sh` ships them through its `git ls-files`
allowlist. `package-lock.json` is committed for reproducible installs.

### CI is the source of truth for `dist/`

You can run `npm run build` and commit `dist/` yourself, but you don't have to:
**on every push to `main` that touches frontend sources, the
`Build frontend bundles` workflow (`.github/workflows/build-frontend.yml`)
rebuilds `dist/` in CI's environment and commits the canonical bundles back.**
Because CI always builds fresh (`npm ci`, cleared Vite cache) the result is
deterministic there, so it never produces churn on an unchanged source tree.

On pull requests, `scripts/check-frontend-build.sh` only verifies the bundles
compile to valid JS (it does **not** require byte-for-byte equality, since a
contributor's minified output may differ from CI's) — the canonical rebuild
happens once the PR lands on `main`.

## Bumping the cache version

`scripts/bump-assets.sh <N>` rewrites `?v=N` across all HTML, including the
bundle tags — unchanged from before. The CI gate
`scripts/check-asset-versions.sh` still enforces a single version everywhere.

## Rollback

The raw `api/js/*.js` (and `admin/*`, `js/login-i18n.js`) sources remain in the
repo. Reverting the `<script>` tags in the three HTML files back to the per-file
list restores no-build serving instantly.
