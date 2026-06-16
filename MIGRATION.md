# Инструкции по миграции на новую архитектуру

## Что изменилось

Проект был рефакторен для улучшения архитектуры, безопасности и производительности:

### 1. Новая структура классов

- **ApiController** - базовый класс для всех API контроллеров
- **Repositories** - классы для работы с БД (MemberRepository, CommissionRepository, LetterRepository)
- **Services** - классы для бизнес-логики (LetterService)
- **ErrorHandler** - улучшенная обработка ошибок

### 2. Конфигурация

- Добавлен `.env.example` и `.env.local` для управления конфигурацией
- Все параметры теперь централизованы

### 3. Производительность

- Добавлены индексы в БД (database_indexes.sql)
- Улучшено кэширование
- Оптимизированы SQL запросы

### 4. Безопасность

- Улучшена валидация входных данных
- Добавлена обработка исключений
- Логирование всех действий

## Шаги миграции

### Шаг 1: Резервная копия

```bash
# MySQL
mysqldump -u root -p os_journal > backup_$(date +%Y%m%d).sql

# PostgreSQL
pg_dump -U postgres os_journal > backup_$(date +%Y%m%d).sql
```

### Шаг 2: Обновление конфигурации

```bash
# Скопируйте .env.example в .env.local
cp .env.example .env.local

# Отредактируйте .env.local с вашими параметрами
nano .env.local
```

### Шаг 3: Добавление индексов

```bash
# MySQL
mysql -u root -p os_journal < database_indexes.sql

# PostgreSQL
psql -U postgres os_journal < database_indexes.sql
```

### Шаг 4: Создание директорий

```bash
mkdir -p cache/data
mkdir -p logs
chmod 777 cache/data logs
```

### Шаг 5: Тестирование

```bash
# Проверьте, что все API endpoints работают
curl http://localhost/api/members.php

# Проверьте логи
tail -f logs/app.log
```

## Обратная совместимость

Все старые API endpoints остаются совместимыми. Новые файлы используют новую архитектуру, но возвращают те же ответы.

## Миграция старых API файлов

Если у вас есть кастомные API файлы, вот как их обновить:

### Старый способ:

```php
<?php
require_once '../config.php';
require_once '../auth_middleware.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = getDBConnection();

if ($method === 'GET') {
    // ... код
}
```

### Новый способ:

```php
<?php
require_once '../config.php';
require_once '../auth_middleware.php';

use App\ApiController;
use App\Repositories\YourRepository;

class YourController extends ApiController
{
    private YourRepository $repo;

    public function __construct()
    {
        parent::__construct();
        $this->repo = new YourRepository($this->db);
    }

    public function handle(): void
    {
        try {
            match ($_SERVER['REQUEST_METHOD']) {
                'GET' => $this->handleGet(),
                'POST' => $this->handlePost(),
                default => $this->error('Method not allowed', 405)
            };
        } catch (\Exception $e) {
            $this->handleException($e, 'YourController');
        }
    }

    private function handleGet(): void
    {
        // ... код
    }
}

$controller = new YourController();
$controller->handle();
```

## Откат на старую версию

Если что-то пошло не так:

```bash
# Восстановите БД из резервной копии
mysql -u root -p os_journal < backup_YYYYMMDD.sql

# Откатите файлы из git
git checkout HEAD -- api/
```

## Проверка после миграции

### 1. Проверьте подключение к БД

```bash
php -r "require 'config.php'; var_dump(getDBConnection());"
```

### 2. Проверьте API endpoints

```bash
# Получить членов ОС
curl http://localhost/api/members.php

# Получить комиссии
curl http://localhost/api/commissions.php

# Получить письма
curl http://localhost/api/letters.php?type=incoming
```

### 3. Проверьте логирование

```bash
tail -f logs/app.log
```

### 4. Проверьте кэширование

```bash
ls -la cache/data/
```

## Проблемы и решения

### Ошибка: "Class not found"

Убедитесь, что все файлы в `src/` созданы и имеют правильные namespace.

### Ошибка: "Cannot write to cache"

Проверьте права доступа:

```bash
chmod 777 cache/data logs
```

### Ошибка: "Database connection failed"

Проверьте параметры в `.env.local`:

```bash
cat .env.local | grep DB_
```

### Медленные запросы

Добавьте индексы:

```bash
mysql -u root -p os_journal < database_indexes.sql
```

## Поддержка

Если у вас возникли проблемы, создайте issue в репозитории с описанием ошибки и логами.
