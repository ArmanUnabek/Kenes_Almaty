<?php
/**
 * Настройки и подключение к базе данных.
 * При переносе на другой хостинг обычно достаточно изменить переменные окружения
 * или значения по умолчанию ниже.
 */

/**
 * Простейший парсер .env-файла (KEY=VALUE), игнорирующий пустые строки и комментарии.
 * Значения, уже присутствующие в окружении, не перезаписываются.
 */
function loadEnvFile(string $path): void
{
    static $loaded = [];
    if (isset($loaded[$path]) || !is_readable($path)) {
        return;
    }
    $loaded[$path] = true;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($key === '') {
            continue;
        }
        // Снять окружающие кавычки, если есть.
        $len = strlen($value);
        if ($len >= 2) {
            $first = $value[0];
            $last = $value[$len - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

/**
 * Возвращает значение переменной окружения либо значение по умолчанию.
 */
function envValue(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

/**
 * Централизованная настройка cookie-параметров сессии до её старта.
 * secure берётся из SESSION_SECURE либо определяется по факту HTTPS.
 */
function configureSessionCookie(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $secureEnv = getenv('SESSION_SECURE');
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $secure = ($secureEnv !== false && $secureEnv !== '')
        ? filter_var($secureEnv, FILTER_VALIDATE_BOOLEAN)
        : $isHttps;

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $secure,
    ]);
}

// Загрузка переменных окружения из .env.local (фолбэк для значений ниже).
loadEnvFile(__DIR__ . '/.env.local');

// Параметры подключения берутся из окружения; .env.local исключён из git.
define('DB_DRIVER', envValue('DB_DRIVER', 'mysql'));
define('DB_HOST', envValue('DB_HOST', 'localhost'));
define('DB_PORT', envValue('DB_PORT', '3306'));
define('DB_USER', envValue('DB_USER', ''));
define('DB_PASS', envValue('DB_PASS', ''));
define('DB_NAME', envValue('DB_NAME', ''));
define('DB_CHARSET', envValue('DB_CHARSET', 'utf8mb4'));

// Глобальная переменная для БД
$db = null;

/**
 * Определяет, выполняется ли текущий скрипт в API-контексте.
 */
function isApiContext(): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (defined('FORCE_API_CONTEXT') && FORCE_API_CONTEXT) {
        return $cache = true;
    }
    // Нормализуем разделители: на Windows путь может содержать и '/', и '\'.
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $cache = (strpos($script, '/api/') !== false);
    return $cache;
}

/**
 * Добавляет недостающие таблицы в существующую SQLite-БД (миграция без пересоздания файла).
 */
function ensureSqliteSchema(PDO $db): void
{
    static $checked = false;
    if ($checked || DB_DRIVER !== 'sqlite') {
        return;
    }
    $checked = true;

    $db->exec("
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            region_id INT,
            title VARCHAR(255) NOT NULL,
            event_date DATE NOT NULL,
            location VARCHAR(255),
            participants_total INT DEFAULT 0,
            attendance_percent DECIMAL(5,2) DEFAULT 0,
            notes TEXT,
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (region_id) REFERENCES regions(id),
            FOREIGN KEY (created_by) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS event_kpi (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INT NOT NULL,
            metric VARCHAR(255) NOT NULL,
            value_numeric DECIMAL(15,2),
            value_text VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS event_attendees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event_id INT NOT NULL,
            full_name VARCHAR(255) NOT NULL,
            attended BOOLEAN DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
        );
    ");
}

/**
 * Приводит audit_logs к актуальной схеме (старый database.sql → deploy_database.sql).
 */
function ensureAuditLogsSchema(PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        if (DB_DRIVER === 'sqlite') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS audit_logs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER,
                    region_id INTEGER,
                    table_name VARCHAR(100),
                    operation VARCHAR(50),
                    record_id INTEGER,
                    old_values TEXT,
                    new_values TEXT,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            return;
        }

        if (DB_DRIVER === 'mysql') {
            $cols = [];
            foreach ($db->query('SHOW COLUMNS FROM audit_logs') as $row) {
                $cols[$row['Field']] = true;
            }
            if (isset($cols['entity_id']) && !isset($cols['record_id'])) {
                $db->exec('ALTER TABLE audit_logs CHANGE entity_id record_id INT');
            }
            if (isset($cols['action']) && !isset($cols['operation'])) {
                $db->exec("ALTER TABLE audit_logs CHANGE action operation VARCHAR(50) NOT NULL DEFAULT 'UPDATE'");
            }
            if (isset($cols['old_data']) && !isset($cols['old_values'])) {
                $db->exec('ALTER TABLE audit_logs CHANGE old_data old_values JSON NULL');
            }
            if (isset($cols['new_data']) && !isset($cols['new_values'])) {
                $db->exec('ALTER TABLE audit_logs CHANGE new_data new_values JSON NULL');
            }
            if (!isset($cols['region_id'])) {
                $db->exec('ALTER TABLE audit_logs ADD COLUMN region_id INT NULL AFTER user_id');
            }
        }
    } catch (Throwable $e) {
        error_log('ensureAuditLogsSchema: ' . $e->getMessage());
    }
}

function ensureTranslationSchema(PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        if (DB_DRIVER === 'sqlite') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS translation_cache (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    source_hash VARCHAR(64) NOT NULL UNIQUE,
                    source_lang VARCHAR(5) NOT NULL,
                    target_lang VARCHAR(5) NOT NULL,
                    source_text TEXT NOT NULL,
                    translated_text TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS translation_cache (
                id INT AUTO_INCREMENT PRIMARY KEY,
                source_hash CHAR(64) NOT NULL,
                source_lang VARCHAR(5) NOT NULL,
                target_lang VARCHAR(5) NOT NULL,
                source_text TEXT NOT NULL,
                translated_text TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_translation_hash (source_hash, source_lang, target_lang)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Throwable $e) {
        error_log('ensureTranslationSchema: ' . $e->getMessage());
    }
}

function ensureMemberI18nColumns(PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        if (DB_DRIVER === 'sqlite') {
            foreach (['position_kz', 'organization_kz'] as $col) {
                $exists = $db->query("PRAGMA table_info(os_members)")->fetchAll();
                $names = array_column($exists, 'name');
                if (!in_array($col, $names, true)) {
                    $db->exec("ALTER TABLE os_members ADD COLUMN {$col} VARCHAR(255)");
                }
            }
            return;
        }

        if (DB_DRIVER === 'mysql') {
            $cols = [];
            foreach ($db->query('SHOW COLUMNS FROM os_members') as $row) {
                $cols[$row['Field']] = true;
            }
            if (!isset($cols['position_kz'])) {
                $db->exec('ALTER TABLE os_members ADD COLUMN position_kz VARCHAR(255) NULL COMMENT "Должность (KZ)" AFTER position');
            }
            if (!isset($cols['organization_kz'])) {
                $db->exec('ALTER TABLE os_members ADD COLUMN organization_kz VARCHAR(255) NULL COMMENT "Организация (KZ)" AFTER organization');
            }
        }
    } catch (Throwable $e) {
        error_log('ensureMemberI18nColumns: ' . $e->getMessage());
    }
}

function ensurePasswordResetTokens(\PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        if (DB_DRIVER === 'sqlite') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS password_reset_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INT NOT NULL,
                    token CHAR(64) NOT NULL UNIQUE,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ");
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS password_reset_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_prt_token (token),
                INDEX idx_prt_expires (expires_at),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (\Throwable $e) {
        error_log('ensurePasswordResetTokens: ' . $e->getMessage());
    }
}

function ensureTelegramTables(\PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        if (DB_DRIVER === 'sqlite') {
            $db->exec("
                CREATE TABLE IF NOT EXISTS telegram_login_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INT NOT NULL,
                    token VARCHAR(64) NOT NULL UNIQUE,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
                CREATE TABLE IF NOT EXISTS telegram_link_codes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INT NOT NULL,
                    code VARCHAR(6) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
            ");
            return;
        }

        $db->exec("
            CREATE TABLE IF NOT EXISTS telegram_login_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                token VARCHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tlt_token (token),
                INDEX idx_tlt_expires (expires_at),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $db->exec("
            CREATE TABLE IF NOT EXISTS telegram_link_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                code VARCHAR(6) NOT NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_tlc_code (code),
                INDEX idx_tlc_expires (expires_at),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (\Throwable $e) {
        error_log('ensureTelegramTables: ' . $e->getMessage());
    }
}

/**
 * Добавляет колонку для хранения хэшей резервных кодов 2FA (если отсутствует).
 */
function ensureTotpBackupColumn(PDO $db): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        if (DB_DRIVER === 'sqlite') {
            $names = array_column($db->query('PRAGMA table_info(users)')->fetchAll(), 'name');
            if (!in_array('totp_backup_codes', $names, true)) {
                $db->exec('ALTER TABLE users ADD COLUMN totp_backup_codes TEXT NULL');
            }
            return;
        }
        if (DB_DRIVER === 'pgsql') {
            $stmt = $db->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = 'users' AND column_name = 'totp_backup_codes'");
            $stmt->execute();
            if (!$stmt->fetchColumn()) {
                $db->exec('ALTER TABLE users ADD COLUMN totp_backup_codes TEXT NULL');
            }
            return;
        }
        // mysql
        $cols = [];
        foreach ($db->query('SHOW COLUMNS FROM users') as $row) {
            $cols[$row['Field']] = true;
        }
        if (!isset($cols['totp_backup_codes'])) {
            $db->exec("ALTER TABLE users ADD COLUMN totp_backup_codes TEXT NULL COMMENT 'JSON: хэши резервных кодов 2FA' AFTER totp_enabled");
        }
    } catch (Throwable $e) {
        error_log('ensureTotpBackupColumn: ' . $e->getMessage());
    }
}

/**
 * Создание соединения с БД (PDO singleton).
 */
function getDBConnection(): PDO
{
    global $db;
    if ($db instanceof PDO) {
        return $db;
    }
    try {
        $needsInit = false;
        if (DB_DRIVER === 'sqlite') {
            $dbPath = __DIR__ . "/" . DB_NAME;
            $needsInit = !file_exists($dbPath);
            $dsn = "sqlite:" . $dbPath;
        } elseif (DB_DRIVER === 'pgsql') {
            $dsn = "pgsql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME;
        } else {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $db = new PDO($dsn, DB_USER, DB_PASS, $options);
        
        if ($needsInit && DB_DRIVER === 'sqlite') {
            $schema = @file_get_contents(__DIR__ . '/database_sqlite.sql');
            if ($schema) {
                $db->exec($schema);
            }
        }
        if (DB_DRIVER === 'sqlite') {
            // Гарантируем наличие базовых таблиц даже если файл схемы отсутствует
            // или БД создаётся впервые (CREATE TABLE IF NOT EXISTS — идемпотентно).
            ensureSqliteSchema($db);
        }
        
        // Force UTF-8 encoding for all connections
        if (DB_DRIVER === 'mysql') {
            $db->exec("SET NAMES utf8mb4");
            $db->exec("SET CHARACTER SET utf8mb4");
            $db->exec("SET character_set_connection=utf8mb4");
        }

        ensureAuditLogsSchema($db);
        ensureTranslationSchema($db);
        ensureMemberI18nColumns($db);
        ensurePasswordResetTokens($db);
        ensureTelegramTables($db);
        ensureTotpBackupColumn($db);
        
        return $db;
    } catch (PDOException $e) {
        if (isApiContext()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            http_response_code(500);
            error_log('DB connection failed: ' . $e->getMessage());
            echo json_encode(['error' => 'Внутренняя ошибка сервера'], JSON_ENCODE_FLAGS);
            exit;
        }
        throw $e;
    }
}
