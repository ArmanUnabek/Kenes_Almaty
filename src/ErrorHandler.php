<?php

namespace App;

class ErrorHandler
{
    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);

        self::$registered = true;
    }

    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        $errorTypes = [
            E_ERROR => 'Fatal Error',
            E_WARNING => 'Warning',
            E_PARSE => 'Parse Error',
            E_NOTICE => 'Notice',
            E_CORE_ERROR => 'Core Error',
            E_CORE_WARNING => 'Core Warning',
            E_COMPILE_ERROR => 'Compile Error',
            E_COMPILE_WARNING => 'Compile Warning',
            E_USER_ERROR => 'User Error',
            E_USER_WARNING => 'User Warning',
            E_USER_NOTICE => 'User Notice',
            E_STRICT => 'Strict',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED => 'Deprecated',
            E_USER_DEPRECATED => 'User Deprecated',
        ];

        $type = $errorTypes[$errno] ?? 'Unknown Error';
        $message = "$type: $errstr in $errfile on line $errline";

        error_log($message);

        if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            self::sendErrorResponse('Внутренняя ошибка сервера', 500);
            exit(1);
        }

        return false;
    }

    public static function handleException(\Throwable $e): void
    {
        $message = sprintf(
            "%s: %s in %s on line %d\nStack trace:\n%s",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );

        error_log($message);

        // PDOException::getCode() может вернуть строковый SQLSTATE ('42S22') — приводим к int.
        $code = is_numeric($e->getCode()) ? (int)$e->getCode() : 0;
        $code = ($code >= 400 && $code < 600) ? $code : 500;

        self::sendErrorResponse('Внутренняя ошибка сервера', $code);
        exit(1);
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $message = sprintf(
                "%s: %s in %s on line %d",
                $error['type'],
                $error['message'],
                $error['file'],
                $error['line']
            );
            error_log($message);
            self::sendErrorResponse('Внутренняя ошибка сервера', 500);
        }
    }

    private static function sendErrorResponse(string $message, int $code = 500): void
    {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode(
            ['error' => $message, 'code' => $code],
            JSON_ENCODE_FLAGS
        );
    }
}
