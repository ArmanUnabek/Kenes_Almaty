<?php

/**
 * Публичная JS-конфигурация клиента (без секретов).
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=300');

$key = defined('PUSHER_KEY') ? (string)PUSHER_KEY : '';
$cluster = defined('PUSHER_CLUSTER') ? (string)PUSHER_CLUSTER : 'eu';

echo 'window.PUSHER_KEY = ' . json_encode($key, JSON_UNESCAPED_UNICODE) . ";\n";
echo 'window.PUSHER_CLUSTER = ' . json_encode($cluster, JSON_UNESCAPED_UNICODE) . ";\n";
echo "window.PUSHER_CHANNEL_DOCUMENTS = 'council-documents';\n";
echo "window.PUSHER_CHANNEL_EVENTS = 'council-events';\n";
echo "window.PUSHER_CHANNEL_DEADLINES = 'council-deadlines';\n";
if ($key !== '') {
    echo "if (window.Pusher) { Pusher.logToConsole = false; }\n";
}
