/**
 * Vite bundle entry for the main SPA (api/index.html).
 *
 * The app's scripts are classic IIFEs that attach to `window` (window.AppUtils,
 * window.AppI18n, window.apiFetch, …) and rely on being loaded in a specific
 * order via separate <script> tags. They are imported here purely for their
 * side effects, in that exact order, so the bundler concatenates + minifies +
 * content-hashes them into a single file without requiring an ESM rewrite.
 *
 * NOT included here (must stay as their own <script> tags):
 *   - config_public.php  — server-rendered globals (API_BASE, csrf token, …),
 *     must load BEFORE this bundle.
 *   - /js/site-config.js, /js/site-docs.js — served from the site root.
 *   - bootstrap / chart.js — loaded from the CDN.
 *
 * This entry is infrastructure only; the live HTML still loads the raw files
 * with ?v=N. Switching index.html to the hashed bundle is a separate, verified
 * follow-up step (see FRONTEND_BUILD.md).
 */

// csrf-handler patches window.fetch and must run first.
import '../csrf-handler.js';

import '../api/js/utils.js';
import '../api/js/i18n.js';
import '../api/js/i18n-dom.js';
import '../api/js/core.js';
import '../api/js/members-ui.js';
import '../api/js/dashboard.js';
import '../api/js/letters-ui.js';
import '../api/js/events-ui.js';
import '../api/js/app-shell.js';
import '../api/js/session.js';
import '../api/app.js';
import '../api/js/search.js';
import '../api/js/dashboard-enhanced.js';
import '../api/js/notifications-ui.js';
import '../api/js/calendar.js';
import '../api/js/mobile-ui.js';
