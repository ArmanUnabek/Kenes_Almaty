/**
 * Entry point для сборки dist/app.js (esbuild, `npm run build`).
 * Порядок импортов = порядок исполнения (все модули — side-effect IIFE).
 *
 * ВАЖНО: api/js/calendar.js НЕ включён в бандл — календарь v2
 * подключается отдельным <script> в api/index.php ПОСЛЕ бандла.
 * Также подключаются отдельно (не бандлятся): appeals-ui.js,
 * sessions-ui.js, global-search.js, inline-edit.js, offline-cache.js,
 * sync-manager.js.
 */
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
// import '../api/js/calendar.js'; // ИСКЛЮЧЁН: заменён календарём v2 (отдельный script tag)
import '../api/js/mobile-ui.js';
import '../api/js/keyboard-shortcuts.js';
import '../api/js/saved-searches.js';
import '../api/js/drag-drop-upload.js';
