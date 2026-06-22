# Журнал Общественного Совета — Алматы

Веб-система для ведения журнала входящих и исходящих писем Общественного Совета. PHP 8.0+ REST API, Vanilla JS SPA, Bootstrap 5, MySQL.

## Возможности

- Регистрация входящих и исходящих писем с нумерацией, датами, категориями
- Прикрепление сканов и вложений (PDF, DOCX, изображения, видео)
- Назначение ответственных членов ОС и адресатов
- Контроль дедлайнов (15 рабочих дней) с уведомлениями
- Комментарии к письмам с историей изменений
- Шаблоны писем для быстрого заполнения
- Архив (мягкое удаление) с возможностью восстановления
- Личная вкладка «Мои письма» для каждого члена ОС
- Календарь дедлайнов по месяцам
- KPI и статистика по членам и комиссиям
- Учёт мероприятий с отметкой явки
- Пакетный экспорт (Excel XLSX, CSV, PDF, JSON)
- Пакетный импорт писем из CSV
- Email-уведомления о дедлайнах и ежемесячные отчёты
- Telegram-уведомления для назначенных членов
- Браузерные push-уведомления (Web Notifications API)
- Real-time обновления через Pusher
- Полный аудит-лог всех действий
- Двуязычный интерфейс (русский / казахский)
- Тёмная тема
- PWA (устанавливается на мобильный)
- Панель администратора с графиком сравнения регионов
- 2FA (TOTP) для повышенной безопасности

## Структура проекта

```
.
├── api/                        # SPA-приложение (Bootstrap 5 + Vanilla JS)
│   ├── index.html              # Главная страница журнала
│   ├── app.js                  # Инициализация SPA, Pusher, навигация
│   ├── js/
│   │   ├── core.js             # apiFetch, store, CSRF
│   │   ├── letters-ui.js       # Входящие/исходящие, комментарии, архив, шаблоны
│   │   ├── calendar.js         # Календарь дедлайнов
│   │   ├── dashboard.js        # KPI и графики
│   │   ├── members-ui.js       # Члены ОС и комиссии
│   │   ├── events-ui.js        # Мероприятия
│   │   ├── notifications-ui.js # Уведомления + браузерный push
│   │   └── ...
│   ├── auth.php                # Вход, выход, CSRF, проверка сессии
│   ├── letters.php             # CRUD писем, архив, восстановление, привязка
│   ├── comments.php            # Комментарии к письмам
│   ├── templates.php           # Шаблоны писем
│   ├── members.php             # Члены ОС
│   ├── commissions.php         # Комиссии
│   ├── events.php              # Мероприятия
│   ├── statistics.php          # Статистика и KPI
│   ├── audit_logs.php          # Журнал аудита
│   ├── admin_stats.php         # Сводка для администратора
│   ├── import.php              # Пакетный импорт CSV
│   ├── export.php              # Экспорт XLSX/CSV/JSON
│   ├── export_pdf.php          # Экспорт PDF
│   ├── search.php              # Глобальный поиск
│   ├── users.php               # Управление пользователями
│   ├── regions.php             # Регионы
│   └── notifications.php       # Email-очередь
├── admin/
│   ├── index.html              # Панель администратора
│   └── admin.js                # Логика панели (регионы, пользователи, аудит, график)
├── src/
│   ├── Repositories/           # PDO-репозитории (UserRepository и др.)
│   └── Services/
│       ├── EmailService.php    # SMTP-отправка, очередь email
│       ├── TelegramService.php # Telegram Bot API
│       ├── AuditLogger.php     # Аудит действий
│       ├── LetterPersistenceService.php
│       ├── LetterService.php
│       └── ...
├── cron_deadlines.php          # Проверка дедлайнов, email + Telegram
├── cron_send_emails.php        # Обработчик очереди email
├── cron_monthly_report.php     # Ежемесячный отчёт по регионам
├── setup.php                   # Первоначальная настройка и создание admin
├── deploy_database.sql         # Полная схема БД
├── config.php                  # Константы из .env
├── db.php                      # Подключение PDO
├── auth_middleware.php         # Middleware: checkAuth, requireRole
├── csrf-handler.js             # Авто-инъекция X-CSRF-Token
├── styles.css                  # Глобальные стили + тёмная тема
├── login.html                  # Страница входа
└── .env.example                # Шаблон переменных окружения
```

## Требования

- PHP **8.0+** (расширения: `pdo_mysql`, `mbstring`, `curl`, `gd`)
- MySQL **5.7+** / MariaDB **10.3+**
- Веб-сервер Apache или Nginx

## Быстрый старт

### 1. Клонирование и конфигурация

```bash
git clone <repository-url>
cd Kenes_Almaty
cp .env.example .env.local
```

Заполните `.env.local`:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=os_journal
DB_USER=os_user
DB_PASS=ваш_пароль
APP_ENV=production
APP_DEBUG=false
```

### 2. База данных

```bash
mysql -u root -p -e "CREATE DATABASE os_journal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p os_journal < deploy_database.sql
```

### 3. Права на директории

```bash
mkdir -p uploads/photos uploads/scans cache/data logs
chmod 755 uploads uploads/photos uploads/scans cache cache/data logs
```

### 4. Первоначальная настройка

Откройте в браузере `/setup.php` — мастер создаст первого администратора и проверит подключение к БД. После завершения файл `.setup_complete` заблокирует повторный вход.

### 5. Запуск (для разработки)

```bash
PHP_CLI_SERVER_WORKERS=6 php -S 0.0.0.0:8000
```

Откройте `http://localhost:8000/api/`

## Переменные окружения

| Переменная | Описание | По умолчанию |
|---|---|---|
| `DB_HOST` | Хост MySQL | `127.0.0.1` |
| `DB_NAME` | Имя базы данных | `os_journal` |
| `DB_USER` / `DB_PASS` | Учётные данные БД | — |
| `APP_ENV` | `development` или `production` | `development` |
| `SESSION_LIFETIME` | Время сессии в секундах | `7200` |
| `SMTP_HOST` | SMTP-сервер для email | — |
| `SMTP_PORT` | SMTP-порт (587 / 465 / 25) | `587` |
| `SMTP_USER` / `SMTP_PASS` | SMTP-учётные данные | — |
| `SMTP_FROM` | Адрес отправителя | `noreply@example.com` |
| `PUSHER_APP_ID` / `PUSHER_KEY` / `PUSHER_SECRET` | Real-time уведомления | — |
| `PUSHER_CLUSTER` | Кластер Pusher | `eu` |
| `TELEGRAM_BOT_TOKEN` | Токен Telegram-бота | — |
| `CRON_TOKEN` | Токен для HTTP-вызова cron | — |
| `UPLOAD_MAX_MB` | Максимальный размер файла | `10` |

## API

### Аутентификация

```
POST /api/auth.php?action=login     # Вход {username, password}
POST /api/auth.php?action=logout    # Выход
GET  /api/auth.php?action=csrf      # Получить CSRF-токен
GET  /api/auth.php                  # Проверить сессию
```

### Письма

```
GET    /api/letters.php?type=incoming          # Список входящих
GET    /api/letters.php?type=incoming&archived=1  # Архив
POST   /api/letters.php?type=incoming          # Создать
PUT    /api/letters.php?type=incoming          # Обновить
DELETE /api/letters.php?type=incoming&id=N    # Переместить в архив
POST   /api/letters.php?type=incoming&action=restore  # Восстановить
```

### Прочие endpoints

```
GET/POST/DELETE /api/comments.php              # Комментарии к письмам
GET/POST/PUT/DELETE /api/templates.php         # Шаблоны писем
GET  /api/audit_logs.php                       # Аудит-лог (admin)
GET  /api/admin_stats.php                      # Сводка администратора
POST /api/import.php                           # Импорт CSV
GET  /api/export.php                           # Экспорт XLSX/CSV/JSON
GET  /api/statistics.php                       # KPI и статистика
GET  /api/search.php?q=                        # Глобальный поиск
GET  /api/members.php                          # Члены ОС
GET  /api/commissions.php                      # Комиссии
GET  /api/events.php                           # Мероприятия
GET/PUT/DELETE /api/users.php                  # Пользователи (admin)
GET  /api/health.php                           # Проверка состояния системы
```

Все мутирующие запросы (POST/PUT/DELETE) требуют заголовок `X-CSRF-Token` (автоматически добавляется `csrf-handler.js`).

## Роли пользователей

| Роль | Возможности |
|---|---|
| `admin` | Полный доступ, управление пользователями и регионами, аудит |
| `manager` | Создание/редактирование писем, комментарии, шаблоны, архив |
| `moderator` | Просмотр и комментирование |
| `viewer` | Только просмотр |

## Cron-задания

Добавьте в `crontab -e`:

```cron
# Проверка дедлайнов каждые 6 часов (email + Telegram)
0 */6 * * * php /var/www/cron_deadlines.php >> /var/log/os_deadlines.log 2>&1

# Отправка очереди email каждые 5 минут
*/5 * * * * php /var/www/cron_send_emails.php >> /var/log/os_emails.log 2>&1

# Ежемесячный отчёт 1-го числа в 08:00
0 8 1 * * php /var/www/cron_monthly_report.php >> /var/log/os_monthly.log 2>&1
```

Или вызов по HTTP (с токеном из `CRON_TOKEN`):

```
GET /cron_deadlines.php?token=<CRON_TOKEN>
GET /cron_send_emails.php?token=<CRON_TOKEN>
GET /cron_monthly_report.php?token=<CRON_TOKEN>
```

## Telegram-уведомления

1. Создайте бота через [@BotFather](https://t.me/BotFather), получите токен
2. Укажите `TELEGRAM_BOT_TOKEN` в `.env.local`
3. Пользователи находят бота, отправляют `/start`, бот возвращает Chat ID
4. Администратор вводит Chat ID в профиле пользователя в панели admin

## Безопасность

- CSRF-защита через `X-CSRF-Token` (автоматически через `csrf-handler.js`)
- Rate limiting: 1000 запросов/час для авторизованных, 100/час для анонимных
- Мягкое удаление писем — данные не теряются, только admin может восстановить
- Полный аудит-лог (таблица `audit_logs`) всех CRUD-операций
- Пароли хранятся в виде `password_hash()` (bcrypt)
- Все входные данные валидируются на сервере
- Секреты только в `.env.local` (не в git, не в коде)
- 2FA через TOTP (опционально)

## Лицензия

MIT
