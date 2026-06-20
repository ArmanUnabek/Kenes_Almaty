<?php
/**
 * Скрипт инициализации Журнала Общественного Совета.
 * Создаёт рабочие директории, проверяет зависимости, создаёт первого admin-пользователя.
 *
 * ВАЖНО: Удалите или переименуйте этот файл после первоначальной настройки!
 *
 * Запуск:
 *   - Через браузер: GET /setup.php
 *   - Через CLI: php setup.php
 */

define('SETUP_VERSION', '1.0');

// Prevent running in production if setup is locked
$lockFile = __DIR__ . '/.setup_complete';
if (file_exists($lockFile) && !isset($_GET['force'])) {
    http_response_code(403);
    die('<h2>Инициализация уже выполнена.</h2><p>Удалите файл <code>.setup_complete</code> и добавьте <code>?force=1</code> для повторного запуска.</p>');
}

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
}

// ───────────────────────────────────────────────
// Step 1: PHP requirements
// ───────────────────────────────────────────────
$requiredExtensions = ['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl', 'fileinfo', 'curl'];
$missingExt = [];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExt[] = $ext;
    }
}

$phpVersion = PHP_VERSION;
$phpOk = version_compare($phpVersion, '8.0.0', '>=');

// ───────────────────────────────────────────────
// Step 2: Create required directories
// ───────────────────────────────────────────────
$dirs = [
    __DIR__ . '/cache',
    __DIR__ . '/logs',
    __DIR__ . '/uploads',
    __DIR__ . '/uploads/photos',
    __DIR__ . '/uploads/scans',
    __DIR__ . '/.rate_limit',
];

$dirResults = [];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        $ok = @mkdir($dir, 0750, true);
        $dirResults[$dir] = $ok ? 'создана' : 'ОШИБКА создания';
    } else {
        $dirResults[$dir] = 'уже существует';
    }
    // Ensure writable
    if (is_dir($dir) && !is_writable($dir)) {
        @chmod($dir, 0750);
    }
}

// ───────────────────────────────────────────────
// Step 3: Check .env file
// ───────────────────────────────────────────────
$envFile    = __DIR__ . '/.env';
$envExample = __DIR__ . '/.env.example';
$envStatus  = 'не найден';
if (file_exists($envFile)) {
    $envStatus = 'найден ✓';
} elseif (file_exists($envExample)) {
    if (@copy($envExample, $envFile)) {
        $envStatus = 'создан из .env.example (измените параметры!)';
    } else {
        $envStatus = 'не удалось создать — скопируйте вручную из .env.example';
    }
}

// ───────────────────────────────────────────────
// Step 4: Test DB connection
// ───────────────────────────────────────────────
$dbStatus  = 'не проверялось';
$dbVersion = '';
$dbPdo     = null;
if (file_exists($envFile)) {
    // Load .env manually (simple key=value parser)
    foreach (file($envFile) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (!getenv($k)) {
            putenv("{$k}={$v}");
        }
    }

    if (file_exists(__DIR__ . '/config.php')) {
        try {
            require_once __DIR__ . '/config.php';
            $dbPdo    = getDBConnection();
            $dbVersion = $dbPdo->query('SELECT VERSION()')->fetchColumn();
            $dbStatus = 'подключение успешно ✓ (версия: ' . $dbVersion . ')';
        } catch (\Throwable $e) {
            $dbStatus = 'ОШИБКА: ' . $e->getMessage();
        }
    }
}

// ───────────────────────────────────────────────
// Step 5: Create first admin user (POST only)
// ───────────────────────────────────────────────
$userResult = null;
if (!$isCli && $_SERVER['REQUEST_METHOD'] === 'POST' && $dbPdo) {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $fullName = trim($_POST['full_name'] ?? '');

    if ($username !== '' && $email !== '' && strlen($password) >= 8 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            // Check if any user exists
            $count = (int)$dbPdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            if ($count > 0 && !isset($_GET['force'])) {
                $userResult = ['error' => 'Пользователи уже существуют. Используйте ?force=1 для принудительного создания.'];
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $dbPdo->prepare("
                    INSERT INTO users (username, email, password_hash, full_name, role, is_active)
                    VALUES (?, ?, ?, ?, 'admin', 1)
                ");
                $stmt->execute([$username, $email, $hash, $fullName ?: $username]);
                $userResult = ['success' => "Admin-пользователь '{$username}' создан."];
                // Write lock file
                file_put_contents($lockFile, date('Y-m-d H:i:s'));
            }
        } catch (\Throwable $e) {
            $userResult = ['error' => 'Ошибка создания пользователя: ' . $e->getMessage()];
        }
    } else {
        $userResult = ['error' => 'Заполните все поля. Пароль минимум 8 символов, email должен быть корректным.'];
    }
}

if ($isCli) {
    echo '=== Kenes Almaty — Инициализация ===' . PHP_EOL;
    echo "PHP {$phpVersion}: " . ($phpOk ? 'OK' : 'ОШИБКА (нужен PHP 8.0+)') . PHP_EOL;
    if ($missingExt) {
        echo 'Отсутствующие расширения: ' . implode(', ', $missingExt) . PHP_EOL;
    }
    foreach ($dirResults as $d => $s) {
        echo "  {$d}: {$s}" . PHP_EOL;
    }
    echo ".env: {$envStatus}" . PHP_EOL;
    echo "DB: {$dbStatus}" . PHP_EOL;
    echo PHP_EOL . 'Для создания admin-пользователя откройте /setup.php в браузере.' . PHP_EOL;
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Инициализация — Журнал ОС</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    body { background: #f4f6fb; }
    .setup-card { max-width: 720px; margin: 40px auto; }
    .check-ok { color: #198754; }
    .check-fail { color: #dc3545; }
  </style>
</head>
<body>
<div class="setup-card">
  <div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
      <h4 class="mb-0">Инициализация Журнала Общественного Совета</h4>
      <small>Версия скрипта: <?= SETUP_VERSION ?></small>
    </div>
    <div class="card-body">

      <?php if ($userResult): ?>
        <?php if (isset($userResult['success'])): ?>
          <div class="alert alert-success"><?= htmlspecialchars($userResult['success'], ENT_QUOTES, 'UTF-8') ?></div>
          <p><a href="/login.html" class="btn btn-primary">Войти в систему</a></p>
        <?php else: ?>
          <div class="alert alert-danger"><?= htmlspecialchars($userResult['error'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
      <?php endif; ?>

      <!-- PHP Environment -->
      <h5>1. Среда PHP</h5>
      <ul class="list-group mb-3">
        <li class="list-group-item d-flex justify-content-between">
          <span>PHP версия: <?= htmlspecialchars($phpVersion, ENT_QUOTES) ?></span>
          <span class="<?= $phpOk ? 'check-ok' : 'check-fail' ?>"><?= $phpOk ? '✓ OK' : '✗ Нужен PHP 8.0+' ?></span>
        </li>
        <?php foreach ($requiredExtensions as $ext): ?>
        <li class="list-group-item d-flex justify-content-between">
          <span>ext-<?= htmlspecialchars($ext, ENT_QUOTES) ?></span>
          <span class="<?= extension_loaded($ext) ? 'check-ok' : 'check-fail' ?>"><?= extension_loaded($ext) ? '✓' : '✗ отсутствует' ?></span>
        </li>
        <?php endforeach; ?>
      </ul>

      <!-- Directories -->
      <h5>2. Рабочие директории</h5>
      <ul class="list-group mb-3">
        <?php foreach ($dirResults as $d => $s): ?>
        <li class="list-group-item d-flex justify-content-between">
          <code><?= htmlspecialchars(str_replace(__DIR__, '.', $d), ENT_QUOTES) ?></code>
          <span class="<?= str_contains($s, 'ОШИБКА') ? 'check-fail' : 'check-ok' ?>"><?= htmlspecialchars($s, ENT_QUOTES) ?></span>
        </li>
        <?php endforeach; ?>
      </ul>

      <!-- .env -->
      <h5>3. Конфигурация (.env)</h5>
      <p class="<?= str_contains($envStatus, 'ОШИБКА') ? 'text-danger' : 'text-success' ?>">
        <?= htmlspecialchars($envStatus, ENT_QUOTES) ?>
      </p>

      <!-- DB -->
      <h5>4. База данных</h5>
      <p class="<?= str_contains($dbStatus, 'ОШИБКА') ? 'text-danger' : 'text-success' ?>">
        <?= htmlspecialchars($dbStatus, ENT_QUOTES) ?>
      </p>
      <?php if (str_contains($dbStatus, 'ОШИБКА')): ?>
        <p class="text-muted small">Проверьте параметры DB_* в файле <code>.env</code> и перезагрузите эту страницу.</p>
      <?php endif; ?>

      <!-- Create admin user -->
      <?php if ($dbPdo && !isset($userResult['success'])): ?>
      <h5 class="mt-4">5. Создать первого администратора</h5>
      <form method="POST">
        <div class="mb-3">
          <label class="form-label">Логин *</label>
          <input type="text" name="username" class="form-control" required minlength="3" maxlength="50" placeholder="admin">
        </div>
        <div class="mb-3">
          <label class="form-label">Email *</label>
          <input type="email" name="email" class="form-control" required placeholder="admin@example.com">
        </div>
        <div class="mb-3">
          <label class="form-label">Полное имя</label>
          <input type="text" name="full_name" class="form-control" placeholder="Имя Фамилия">
        </div>
        <div class="mb-3">
          <label class="form-label">Пароль * (минимум 8 символов)</label>
          <input type="password" name="password" class="form-control" required minlength="8">
        </div>
        <button type="submit" class="btn btn-success">Создать администратора</button>
      </form>
      <?php elseif (!$dbPdo): ?>
        <div class="alert alert-warning">Подключение к БД не установлено. Настройте .env и обновите страницу.</div>
      <?php endif; ?>

      <hr>
      <p class="text-muted small">
        <strong>Важно:</strong> после настройки удалите файл <code>setup.php</code> или
        убедитесь, что он защищён от публичного доступа в <code>.htaccess</code>.
      </p>
    </div>
  </div>
</div>
</body>
</html>
