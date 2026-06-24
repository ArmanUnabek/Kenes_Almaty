/**
 * Vite bundle entry for the login page (login.html).
 *
 * Side-effect imports, in load order, of the classic scripts login.html needs.
 * csrf-handler patches window.fetch and must run before the inline login call.
 *
 * NOT bundled (stay as their own tags): /js/site-config.js, /js/site-docs.js
 * (served from the site root) and the Bootstrap CDN bundle.
 */
import '../csrf-handler.js';
import '../js/login-i18n.js';
