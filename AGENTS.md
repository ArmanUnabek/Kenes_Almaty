# AGENTS.md

## Cursor Cloud specific instructions

Это приложение «Журнал Общественного Совета» — PHP SPA (Bootstrap 5 + ванильный JS)
с REST-подобным API в `api/*.php` и хранилищем в MySQL/MariaDB. Composer/npm не используются;
PHP-зависимостей через пакетный менеджер нет.

### Сервисы и как их запускать

- **База данных (MariaDB).** systemd в этой среде недоступен, поэтому демон нужно запускать
  вручную (он не стартует сам после перезагрузки VM):

  ```bash
  sudo mkdir -p /var/run/mysqld && sudo chown mysql:mysql /var/run/mysqld
  sudo mariadbd-safe >/tmp/mariadb.log 2>&1 &
  sudo mysqladmin ping   # ожидается "mysqld is alive"
  ```

  БД `os_journal`, пользователь `os_user`/`os_pass` и схема (`deploy_database.sql` — каноничная
  схема, остальные `database*.sql` устаревшие) уже загружены и сохраняются в снапшоте VM.
  Если БД пустая, перезалейте: `sudo mysql os_journal < deploy_database.sql`.

- **Веб-приложение (PHP dev server).** Запускать ОБЯЗАТЕЛЬНО с несколькими воркерами, иначе
  встроенный сервер однопоточный и намертво зависает (чёрный экран с крутящимся кубом),
  когда SPA шлёт несколько параллельных запросов, в т.ч. при создании письма:

  ```bash
  cd /workspace && PHP_CLI_SERVER_WORKERS=6 php -S 0.0.0.0:8000
  ```

  Приложение: http://localhost:8000/ (корень редиректит на `/api/`, неавторизованных — на
  `/login.html`). `isApiContext()` в `db.php` определяет API по наличию `/api/` в пути скрипта,
  что естественно выполняется при `php -S` из корня репозитория.

### Конфигурация

- Настройки берутся из переменных окружения; для локальной разработки используется
  `.env.local` (в git не хранится, парсится `db.php`). Файл уже создан в снапшоте с указанными
  выше DB-кредами и `APP_ENV=development`.
- Каталоги `uploads/photos`, `cache/data`, `logs` должны существовать и быть записываемыми
  (они в `.gitignore`).

### Учётные данные для входа (из схемы, пароль одинаковый)

- `admin` / `admin123` — администратор (полный доступ, удаление)
- `moderator` / `admin123` — модератор (запись)
- `viewer` / `admin123` — только просмотр

### Lint / тесты / сборка

- **Сборка не требуется** — это статика + интерпретируемый PHP.
- **Lint:** синтаксическая проверка PHP — `php -l <file>`; быстро по всем файлам:
  `find . -name '*.php' -exec php -l {} \;` (ожидается «No syntax errors»).
- **Автотестов нет** (нет PHPUnit/composer). `TESTING.md` описывает ручное тестирование API
  через `curl` (получить CSRF из `api/auth.php?action=csrf`, мутирующие запросы требуют заголовок
  `X-CSRF-Token`). Эндпоинт `api/members.php` — только GET; запись писем — в `api/letters.php`
  (`?type=incoming|outgoing`, метод POST/PUT/DELETE).
