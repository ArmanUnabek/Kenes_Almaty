# Frontend build (Vite) — «Журнал ОС»

The SPA is historically a **no-build** vanilla-JS app: classic `<script>` tags
loaded in a fixed order and cache-busted with `?v=N` (see `scripts/bump-assets.sh`).
This adds an **opt-in** Vite build that bundles those scripts into a single
minified, content-hashed asset, so a later cutover can drop the manual `?v=N`
versioning entirely.

> **Status: infrastructure only.** `npm run build` produces a parallel artifact
> in `dist/`. The live HTML (`api/index.html`, `login.html`, `admin/index.html`)
> still loads the raw `?v=N` files. The cutover to the hashed bundle is a
> separate, verified step (below) so production is never at risk from this PR.

## Build

```bash
npm install        # installs vite (dev dependency only)
npm run build      # emits dist/assets/app.<hash>.js
npm run dev        # optional: vite dev server
```

`dist/` is git-ignored (a build artifact, not source). `package-lock.json` IS
committed for reproducible installs.

## What the bundle contains

`frontend/app.entry.js` imports the existing classic IIFE scripts **for their
side effects, in the exact load order** of `api/index.html`:

```
csrf-handler.js → api/js/utils.js → i18n.js → i18n-dom.js → core.js →
members-ui.js → dashboard.js → letters-ui.js → events-ui.js → app-shell.js →
session.js → api/app.js → search.js → dashboard-enhanced.js →
notifications-ui.js → calendar.js → mobile-ui.js
```

No ESM rewrite is required — the files keep attaching to `window`
(`window.AppUtils`, `window.AppI18n`, `window.apiFetch`, …); the bundler just
concatenates, minifies and hashes them.

**Deliberately NOT bundled** (must remain their own tags):

- `config_public.php` — server-rendered globals (`API_BASE`, CSRF token); must
  load **before** the bundle.
- `/js/site-config.js`, `/js/site-docs.js` — served from the site root.
- Bootstrap / Chart.js — loaded from the CDN.

## Planned cutover (separate PR, after verification on staging)

1. After `npm run build`, replace the 14 raw `api/js/*.js` + `api/app.js` +
   `../csrf-handler.js` script tags in each HTML entry point with a single
   `<script src="/dist/assets/app.<hash>.js"></script>`, keeping
   `config_public.php` (before) and the CDN/site-root tags (as-is).
2. Update `scripts/deploy.sh` to ship `dist/` and stop shipping the raw JS;
   retire `scripts/bump-assets.sh` / `check-asset-versions.sh` (the content hash
   replaces `?v=N`) or repurpose the CI gate to assert a fresh build.
3. Keep a rollback path: the raw files still exist, so reverting the HTML tags
   restores the no-build serving instantly.
