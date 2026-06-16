# Рекомендации по улучшению проекта

## 1. АРХИТЕКТУРА И СТРУКТУРА

### 1.1 Создать базовый класс ApiController
**Приоритет:** ВЫСОКИЙ

Сейчас каждый API файл повторяет одинаковый код. Нужен базовый класс:

```php
// src/ApiController.php
abstract class ApiController {
    protected PDO $db;
    protected array $JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    
    public function __construct() {
        header('Content-Type: application/json; charset=utf-8');
        $this->db = getDBConnection();
        CsrfMiddleware::init();
    }
    
    protected function json($data, int $code = 200): void {
        http_response_code($code);
        echo json_encode($data, $this->JSON_FLAGS);
        exit;
    }
    
    protected function error(string $message, int $code = 400): void {
        $this->json(['error' => $message], $code);
    }
}
```

**Преимущества:**
- Меньше дублирования кода
- Единообразная обработка ошибок
- Легче поддерживать

---

### 1.2 Создать Repository классы
**Приоритет:** ВЫСОКИЙ

Вынести логику работы с БД в отдельные классы:

```php
// src/Repositories/MemberRepository.php
class MemberRepository {
    public function __construct(private PDO $db) {}
    
    public function getById(int $id): ?array { ... }
    public function getAll(int $regionId, int $page, int $limit): array { ... }
    public function create(array $data): int { ... }
    public function update(int $id, array $data): bool { ... }
    public function delete(int $id): bool { ... }
}
```

**Преимущества:**
- Логика БД отделена от API
- Легче тестировать
- Переиспользуемый код

---

### 1.3 Создать Service классы для бизнес-логики
**Приоритет:** СРЕДНИЙ

```php
// src/Services/LetterService.php
class LetterService {
    public function createIncomingLetter(array $data): int { ... }
    public function linkLetters(int $incomingId, int $outgoingId): void { ... }
    public function generateLetterNumber(int $seq, int $regionId): string { ... }
}
```

---

## 2. БЕЗОПАСНОСТЬ

### 2.1 Добавить валидацию входных данных везде
**Приоритет:** КРИТИЧЕСКИЙ

Сейчас валидация используется не везде. Нужно валидировать ВСЕ входные данные:

```php
// В каждом POST/PUT запросе
$validator = new Validator();
if (!$validator->validate($data, [
    'field_name' => 'required|string|min:2|max:255',
    'email' => 'email',
    'date' => 'date',
])) {
    $this->error($validator->getFirstError(), 400);
}
```

### 2.2 Добавить логирование всех действий
**Приоритет:** ВЫСОКИЙ

Сейчас логируются только некоторые действия. Нужно логировать:
- Все изменения данных
- Попытки несанкционированного доступа
- Ошибки БД
- Подозрительную активность

```php
// src/Services/AuditLogger.php - уже есть, но нужно использовать везде
AuditLogger::log($db, 'table_name', $id, 'ACTION', $oldData, $newData, $userId);
```

### 2.3 Добавить защиту от XSS
**Приоритет:** ВЫСОКИЙ

При выводе данных в HTML нужно экранировать:

```php
// Вместо:
echo $data['name'];

// Использовать:
echo htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8');
```

### 2.4 Добавить проверку прав доступа на уровне Repository
**Приоритет:** СРЕДНИЙ

```php
public function getById(int $id, ?int $regionId = null): ?array {
    $query = "SELECT * FROM members WHERE id = ?";
    $params = [$id];
    
    if ($regionId) {
        $query .= " AND region_id = ?";
        $params[] = $regionId;
    }
    
    $stmt = $this->db->prepare($query);
    $stmt->execute($params);
    return $stmt->fetch();
}
```

---

## 3. ПРОИЗВОДИТЕЛЬНОСТЬ

### 3.1 Добавить индексы в БД
**Приоритет:** ВЫСОКИЙ

```sql
-- Добавить индексы для часто используемых полей
ALTER TABLE os_members ADD INDEX idx_region_id (region_id);
ALTER TABLE os_members ADD INDEX idx_commission_id (commission_id);
ALTER TABLE incoming_letters ADD INDEX idx_region_id (region_id);
ALTER TABLE incoming_letters ADD INDEX idx_date (date);
ALTER TABLE outgoing_letters ADD INDEX idx_region_id (region_id);
ALTER TABLE letter_members ADD INDEX idx_letter (letter_type, letter_id);
ALTER TABLE letter_recipients ADD INDEX idx_letter (letter_type, letter_id);
```

### 3.2 Оптимизировать кэширование
**Приоритет:** СРЕДНИЙ

Сейчас `forgetPrefix()` очищает весь кэш. Нужно улучшить:

```php
// Вместо полной очистки, удалять только нужные ключи
public function forgetPrefix(string $prefix): void {
    foreach (glob($this->basePath . '/*.json') as $file) {
        $content = json_decode(file_get_contents($file), true);
        if (strpos($content['key'] ?? '', $prefix) === 0) {
            unlink($file);
        }
    }
}
```

### 3.3 Добавить пагинацию везде
**Приоритет:** СРЕДНИЙ

Сейчас не все запросы используют пагинацию. Нужно добавить везде для больших наборов данных.

### 3.4 Использовать Redis вместо файлового кэша
**Приоритет:** НИЗКИЙ (для будущего)

Файловый кэш медленнее Redis. Для масштабирования нужен Redis.

---

## 4. ОБРАБОТКА ОШИБОК

### 4.1 Создать единый обработчик исключений
**Приоритет:** ВЫСОКИЙ

```php
// src/ErrorHandler.php - уже есть, но нужно улучшить
class ErrorHandler {
    public static function handle(Throwable $e): void {
        error_log($e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Внутренняя ошибка сервера',
            'code' => $e->getCode()
        ]);
    }
}
```

### 4.2 Добавить try-catch блоки везде
**Приоритет:** ВЫСОКИЙ

Сейчас многие операции с БД не обработаны:

```php
try {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
} catch (PDOException $e) {
    error_log('DB Error: ' . $e->getMessage());
    $this->error('Ошибка базы данных', 500);
}
```

---

## 5. ТЕСТИРОВАНИЕ

### 5.1 Добавить unit тесты
**Приоритет:** СРЕДНИЙ

```php
// tests/ValidatorTest.php
class ValidatorTest extends TestCase {
    public function testEmailValidation() {
        $validator = new Validator();
        $this->assertTrue($validator->validate(
            ['email' => 'test@example.com'],
            ['email' => 'email']
        ));
    }
}
```

### 5.2 Добавить API тесты
**Приоритет:** СРЕДНИЙ

Тестировать все endpoints с разными сценариями.

---

## 6. ДОКУМЕНТАЦИЯ

### 6.1 Добавить PHPDoc комментарии
**Приоритет:** СРЕДНИЙ

```php
/**
 * Получить члена ОС по ID
 * 
 * @param int $id ID члена
 * @param int|null $regionId ID региона для проверки доступа
 * @return array|null Данные члена или null
 * @throws PDOException
 */
public function getById(int $id, ?int $regionId = null): ?array { ... }
```

### 6.2 Обновить README
**Приоритет:** НИЗКИЙ

Добавить примеры использования API, описание структуры БД.

---

## 7. КОНФИГУРАЦИЯ

### 7.1 Добавить .env файл
**Приоритет:** ВЫСОКИЙ

```bash
# .env
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_USER=root
DB_PASS=password
DB_NAME=os_journal
LOG_LEVEL=info
CACHE_TTL=3600
```

### 7.2 Добавить конфиг для разных окружений
**Приоритет:** СРЕДНИЙ

```php
// config/development.php
// config/production.php
// config/testing.php
```

---

## 8. МОНИТОРИНГ И ЛОГИРОВАНИЕ

### 8.1 Добавить структурированное логирование
**Приоритет:** СРЕДНИЙ

```php
// Вместо error_log(), использовать структурированный логгер
Logger::info('Letter created', [
    'letter_id' => $id,
    'type' => 'incoming',
    'user_id' => $userId
]);
```

### 8.2 Добавить метрики производительности
**Приоритет:** НИЗКИЙ

Отслеживать время выполнения запросов, использование памяти.

---

## Приоритет реализации:

1. **КРИТИЧЕСКИЙ:** Валидация входных данных везде
2. **ВЫСОКИЙ:** 
   - ApiController базовый класс
   - Repository классы
   - Индексы в БД
   - .env файл
   - Обработка исключений
3. **СРЕДНИЙ:**
   - Service классы
   - Логирование
   - Кэширование
   - Тесты
4. **НИЗКИЙ:**
   - Redis
   - Документация
   - Мониторинг

---

## Примерный план реализации:

**Неделя 1:** Валидация, ApiController, Repository
**Неделя 2:** Service классы, обработка ошибок
**Неделя 3:** Индексы БД, кэширование, логирование
**Неделя 4:** Тесты, документация
