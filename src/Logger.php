<?php

namespace App;

class Logger
{
    private static $logDir = __DIR__ . '/../logs';
    private static $initialized = false;

    public static function init(): void
    {
        if (!self::$initialized) {
            if (!is_dir(self::$logDir)) {
                mkdir(self::$logDir, 0755, true);
            }
            self::$initialized = true;
        }
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('WARNING', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::log('INFO', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        if (getenv('DEBUG') === 'true') {
            self::log('DEBUG', $message, $context);
        }
    }

    private static function log(string $level, string $message, array $context = []): void
    {
        self::init();
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' | ' . json_encode($context, JSON_ENCODE_FLAGS) : '';
        $logMessage = "[$timestamp] [$level] $message$contextStr\n";
        
        $logFile = self::$logDir . '/' . date('Y-m-d') . '.log';
        error_log($logMessage, 3, $logFile);
    }
}
