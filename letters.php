<?php
/**
 * УСТАРЕЛО: весь CRUD писем перенесён в api/letters.php (единый эндпоинт).
 * Этот файл оставлен тонким прокси для обратной совместимости со старыми ссылками.
 */
define('FORCE_API_CONTEXT', true);
require __DIR__ . '/api/letters.php';
