# Деплой на hoster.kz (shared-хостинг)

Пошаговый чек-лист, чтобы «залить файлы и всё работало».

> **Если есть SSH/rsync** — весь чек-лист по файлам автоматизирован:
> `DEPLOY_TARGET="user@host:~/www/<домен>" bash scripts/deploy.sh --dry-run`
> (затем без `--dry-run`). Скрипт сам исключает `tests/.github/scripts/*.md`
> и проверяет единство версии ассетов. Версию статики меняйте одним бампом:
> `bash scripts/bump-assets.sh <N>`. Ниже — ручной вариант для панели без SSH.

## 1. Файлы
Залей в корень сайта (`~/www/<домен>` или `public_html`) **всё, кроме**:
`.git/`, `vendor/`, `tests/`, `.github/`, `scripts/`, `phpunit.xml`, `*.md`, `.env.local`.

> `vendor/` нужен только для PHPMailer (корректный TLS у почты). Если есть SSH —
> `composer install --no-dev`. Если нет — не заливай; почта пойдёт через встроенный
> SMTP-сокет (порт 465 поддерживается).

## 2. PHP
В панели → «Настройки PHP» для домена: версия **8.0+**, расширения
`pdo_mysql`, `mbstring`, `curl`, `gd`/`fileinfo`, `zip`.

## 3. База данных
1. Создай БД и пользователя (пользователь — владелец БД, с правом ALTER).
2. phpMyAdmin → выбери БД → **Импорт** → `deploy_database.sql` → Вперёд.
3. Проверь: появились таблицы (`users`, `regions`, …, ~24 шт.) и строка админа.

## 4. Файл `.env.local` (создать в корне; он не входит в git)
```ini
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_NAME=<имя_бд>
DB_USER=<пользователь_бд>
DB_PASS=<пароль_бд>

SESSION_SECURE=true
ALLOWED_ORIGINS=https://<домен>
ENFORCE_ADMIN_2FA=false        # включить true после настройки 2FA админом

TELEGRAM_BOT_TOKEN=<токен_от_BotFather>

SMTP_HOST=<почтовый_сервер>     # напр. zhurnal.zhasylumit.kz
SMTP_PORT=465
SMTP_USER=<ящик>
SMTP_PASS=<пароль_ящика>
SMTP_FROM=<тот_же_ящик>

CRON_TOKEN=<случайная_строка>
HEALTH_CHECK_TOKEN=<случайная_строка>
NOTIFY_ALLOWED_DOMAINS=gov.kz
```

## 5. Папки с правами на запись (File Manager → права 775)
`uploads/photos`, `uploads/scans`, `cache/data`, `.rate_limit`, `logs`
(создай, если их нет).

## 6. HTTPS
Включи Let's Encrypt для домена. `SESSION_SECURE=true` уже в `.env.local`.

## 7. Первый вход
`https://<домен>/login.html` → **admin / admin123** →
сразу смени пароль и (рекомендуется) включи 2FA в админ-панели.

## 8. Cron (панель → Планировщик)
```
0 9 * * 1-5  php ~/www/<домен>/cron_deadlines.php
*/5 * * * *  php ~/www/<домен>/cron_send_emails.php
0 8 1 * *    php ~/www/<домен>/cron_monthly_report.php
```
Если планировщик умеет только URL — добавляй `?token=<CRON_TOKEN>`:
`wget -q -O /dev/null "https://<домен>/cron_deadlines.php?token=<CRON_TOKEN>"`

## Если после заливки 500
- **Все страницы 500 (вкл. login.html)** → почти всегда `.htaccess`. Этот репозиторий
  уже использует FPM-совместимый `.htaccess` + `.user.ini`. Если хостинг всё равно
  ругается — временно переименуй `.htaccess` и сверься с error log.
- **Открывается, но вход даёт 500** → не импортирована БД (нет таблицы `users`)
  или залита старая схема. Перезалей `deploy_database.sql`.
- **csrf отвечает, а login — 500** → то же: схема БД. (`/api/auth.php?action=csrf`
  не трогает таблицы, поэтому работает даже без импорта.)
- Точную причину всегда смотри в **PHP error log** хостинга.
