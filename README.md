# Журнал Общественного Совета

Система для ведения журнала входящих и исходящих писем Общественного Совета с поддержкой MySQL и PostgreSQL.

## Структура проекта

```
public_html/
├── api/                    # Рабочее SPA-приложение (Bootstrap 5 + ванильный JS)
│   ├── index.html          # Главная страница приложения
│   ├── app.js              # Логика SPA
│   ├── auth.php            # Аутентификация, CSRF-токены
│   ├── letters.php         # API для писем
│   ├── members.php         # API для членов ОС
│   ├── commissions.php     # API для комиссий
│   ├── statistics.php      # Статистика и KPI
│   └── ...                 # Прочие API-endpoints
├── src/                    # PHP-классы (репозитории, сервисы, middleware)
├── uploads/                # Загруженные файлы (не в git)
├── cache/                  # Файловый кэш (не в git)
├── logs/                   # Логи приложения (не в git)
├── index.html              # Редирект на /api/
├── login.html              # Страница входа
├── csrf-handler.js         # CSRF-токен для fetch-запросов
├── styles.css              # Глобальные стили
├── config.php              # Конфигурация приложения
├── db.php                  # Подключение к БД
├── auth_middleware.php     # Middleware аутентификации
├── database.sql            # Схема БД (MySQL)
├── database_pg.sql         # Схема БД (PostgreSQL)
├── database_indexes.sql    # Индексы для оптимизации
└── .env.example            # Пример конфигурации
```

> Корневой `index.html` автоматически перенаправляет браузер на `/api/`.

## Требования

- PHP 7.4+
- MySQL 5.7+ или PostgreSQL 10+
- Веб-сервер Apache/Nginx

## Установка

### 1. Клонирование

```bash
git clone <repository-url>
cd public_html
```

### 2. Конфигурация

Создайте `.env.local` на основе примера (файл **не хранится в git**):

```bash
cp .env.example .env.local
```

Заполните `.env.local`:

```env
DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_USER=<db_user>
DB_PASS=<db_password>
DB_NAME=<db_name>
APP_ENV=production
APP_DEBUG=false
```

### 3. База данных

MySQL:
```bash
mysql -u <user> -p <dbname> < database.sql
mysql -u <user> -p <dbname> < database_indexes.sql
```

PostgreSQL:
```bash
psql -U <user> -f database_pg.sql
```

### 4. Права доступа

```bash
chmod 777 uploads/photos
chmod 777 cache/data
chmod 777 logs
```

## Деплой на хостинг

1. Загрузите все файлы проекта по FTP в корневую директорию хостинга.
2. Создайте базу данных через phpMyAdmin и импортируйте `database.sql`.
3. Создайте `.env.local` с реальными параметрами подключения (хост, имя БД, пользователь, пароль).
4. Установите права 777 на папки `uploads/photos`, `cache/data`, `logs`.
5. Откройте приложение: `https://ваш-домен.ru/api/`
6. После первого входа смените пароли всех пользователей.

> **Важно:** Файл `.env.local` содержит секреты (пароль БД и др.) и **не должен попадать в git**.

## API Endpoints

```
POST /api/auth.php?action=login     # Вход
GET  /api/auth.php?action=csrf      # CSRF-токен
POST /api/auth.php?action=logout    # Выход
GET  /api/auth.php                  # Проверка сессии

GET  /api/members.php               # Члены ОС
GET  /api/commissions.php           # Комиссии
GET  /api/letters.php?type=incoming # Входящие письма
GET  /api/letters.php?type=outgoing # Исходящие письма
GET  /api/statistics.php            # Статистика и KPI
POST /api/upload_photo.php          # Загрузка фото члена ОС
```

Все изменяющие запросы (POST/PUT/DELETE) требуют заголовок `X-CSRF-Token`.

## Безопасность

- CSRF-защита через `csrf-handler.js` (автоматически добавляет токен ко всем мутирующим запросам)
- Rate limiting: 1000 запросов/час для авторизованных, 100/час для анонимных
- Все входные данные валидируются на сервере
- Секреты хранятся в `.env.local` (не в git, не в коде)

## Лицензия

MIT
