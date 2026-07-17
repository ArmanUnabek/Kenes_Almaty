<?php

/**
 * Публичная JS-конфигурация клиента (без секретов).
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=300');

$tgUsername = defined('TELEGRAM_BOT_USERNAME') ? (string)TELEGRAM_BOT_USERNAME : '';

// Sanitize: only allow alphanumeric, dots, hyphens, underscores
$sanitize = fn(string $v) => preg_replace('/[^a-zA-Z0-9._-]/', '', $v);
$tgUsername = $sanitize($tgUsername);

echo 'window.TELEGRAM_BOT_USERNAME = ' . json_encode($tgUsername, JSON_UNESCAPED_UNICODE) . ";\n";
