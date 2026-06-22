<?php

/**
 * Публичная JS-конфигурация клиента (без секретов).
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=300');

$key         = defined('PUSHER_KEY')            ? (string)PUSHER_KEY            : '';
$cluster     = defined('PUSHER_CLUSTER')        ? (string)PUSHER_CLUSTER        : 'eu';
$tgUsername  = defined('TELEGRAM_BOT_USERNAME') ? (string)TELEGRAM_BOT_USERNAME : '';

echo 'window.PUSHER_KEY = '            . json_encode($key,        JSON_UNESCAPED_UNICODE) . ";\n";
echo 'window.PUSHER_CLUSTER = '        . json_encode($cluster,    JSON_UNESCAPED_UNICODE) . ";\n";
echo "window.PUSHER_CHANNEL_DOCUMENTS = 'council-documents';\n";
echo "window.PUSHER_CHANNEL_EVENTS = 'council-events';\n";
echo "window.PUSHER_CHANNEL_DEADLINES = 'council-deadlines';\n";
echo 'window.TELEGRAM_BOT_USERNAME = ' . json_encode($tgUsername, JSON_UNESCAPED_UNICODE) . ";\n";
if ($key !== '') {
    echo "if (window.Pusher) { Pusher.logToConsole = false; }\n";
}
