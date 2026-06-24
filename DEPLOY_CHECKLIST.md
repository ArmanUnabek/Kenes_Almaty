# DEPLOY_CHECKLIST.md — заливка «Журнала ОС» на хостинг

Чек-лист для деплоя ветки `main` на `https://zhurnal.zhasylumit.kz/`.
Источник — `main` (все исправления влиты, статика на `?v=26`, без Verity).

---

## Что уже в `main` (контекст)

- **Фронтенд:** единый слой `AppUtils.fetchJson/asList`, починен латентный
  `apiFetch is not defined` (панели Комментарии/Аудит/Архив/Шаблоны), нормализация
  ответов API, фикс `i18n-dom`, иконки PWA, autocomplete.
- **Тесты:** 116 PHPUnit-тестов (репозитории на in-memory SQLite + регион-скоупинг,
  health/search, правила Validator). CI зелёный.
- **Деплой/кеш:** единая версия статики `?v=26` во всех HTML, `.htaccess` не кеширует
  HTML, `scripts/deploy.sh` (allowlist по `git ls-files`), CI-гейт единства версий.

---

## Предусловия (один раз)

- [ ] Боевая БД создана, импортирован `deploy_database.sql` (если уже работает — пропустить).
- [ ] В корне сайта лежит `.env.local` с боевыми значениями:
  - `DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS`
  - `APP_URL=https://zhurnal.zhasylumit.kz`
  - `SESSION_SECURE=true`
  - `ALLOWED_ORIGINS=https://zhurnal.zhasylumit.kz`
- [ ] PHP 8.0+ с расширениями: `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `openssl`.

## Шаг 1. Зависимости без dev
```bash
composer install --no-dev
```
Оставляет PHPMailer (нужен для почты), убирает phpunit и dev-пакеты.

## Шаг 2. Залить файлы (один из способов)

### A. SSH / rsync (рекомендуется)
```bash
DEPLOY_TARGET="ЛОГИН@ХОСТ:~/www/zhurnal.zhasylumit.kz" bash scripts/deploy.sh --dry-run
DEPLOY_TARGET="ЛОГИН@ХОСТ:~/www/zhurnal.zhasylumit.kz" bash scripts/deploy.sh
```
Скрипт сам: шлёт только tracked-файлы (+ `vendor/`), исключает `tests/`, `.github/`,
`scripts/`, `*.md`, локальные БД/логи/кеш/секреты, и проверяет единство версии ассетов.

### B. File Manager (без SSH) — перезалить изменённое
> Фронт собран в бандлы. Локально один раз: `npm install && npm run build` (бандлы
> уже закоммичены в `dist/`, так что обычно просто заливаете готовые файлы).
- HTML: `api/index.html`, `login.html`, `admin/index.html`, `help/*`, `legal/*` (все `?v=N`)
- **Бандлы:** `dist/app.js`, `dist/login.js`, `dist/admin.js` (в корень сайта)
- PWA: `api/sw.js`, `api/icons/icon-192.png`, `api/icons/icon-512.png`
- Прочее: `.htaccess`, `src/`, `config.php`
- **Не заливать:** `tests/`, `.github/`, `scripts/`, `frontend/`, `node_modules/`,
  `package*.json`, `*.md`, `database.sqlite`, `logs/`, `cache/`, `.env.local` (если уже на сервере).

## Шаг 3. После заливки
- [ ] DevTools → **Application → Clear site data** → перезагрузка (сбрасывает старый Service Worker/кеш). Делается один раз.

## Шаг 4. Приёмка
Открыть `https://zhurnal.zhasylumit.kz/api/`:
- [ ] Консоль чистая; скрипты грузятся как `?v=26`.
- [ ] Нет ошибок `AppI18n` / `forEach is not a function`.
- [ ] Иконка PWA без 404.
- [ ] Списки Письма / Члены / Комиссии / События грузятся.
- [ ] Панели Комментарии / Аудит / Архив / Шаблоны открываются (раньше падали `apiFetch is not defined`).
- [ ] Подписи интерфейса на нормальном русском/казахском (не «кракозябры»).

## Шаг 5. Откат (если нужно)
Вернуть прежние файлы на сервере — браузеры подтянут то, что лежит. HTML больше не
кешируется (`.htaccess`), поэтому правки видны сразу; для статики при необходимости —
поднять версию `bash scripts/bump-assets.sh <N>` и перезалить.

---

## Будущие правки (как обновлять статику)
При любом изменении CSS/JS — **один** бамп версии вместо ручной правки по файлам:
```bash
bash scripts/bump-assets.sh 27   # подставит ?v=27 во все HTML
```
CI-гейт `scripts/check-asset-versions.sh` не пропустит рассинхрон версий в `main`.

## Cron (панель → Планировщик), если ещё не настроен
```
0 9 * * 1-5  php ~/www/zhurnal.zhasylumit.kz/cron_deadlines.php
*/5 * * * *  php ~/www/zhurnal.zhasylumit.kz/cron_send_emails.php
0 8 1 * *    php ~/www/zhurnal.zhasylumit.kz/cron_monthly_report.php
```
