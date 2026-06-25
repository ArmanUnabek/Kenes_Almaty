/**
 * Vite bundle entry for the admin panel (admin/index.html).
 *
 * Side-effect imports, in the exact load order of admin/index.html:
 *   csrf-handler → api/js/utils.js → admin/admin-i18n.js → admin/admin.js
 * admin.js relies on window.AppUtils (from utils.js) and the admin-i18n dict,
 * so the order is preserved.
 *
 * NOT bundled (stay as their own tags): /js/site-config.js, /js/site-docs.js
 * and the Bootstrap / Chart.js CDN bundles.
 */
import '../csrf-handler.js';
import '../api/js/utils.js';
import '../admin/admin-i18n.js';
import '../admin/admin.js';
