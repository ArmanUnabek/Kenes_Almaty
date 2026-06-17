# Чеклист выкладки на хостинг

## 1. Файлы и окружение

- [ ] Скопировать все файлы проекта на сервер (кроме `.git`, `vendor/` если ставите на сервере)
- [ ] Создать `.env` из `.env.example` и заполнить:
  - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
  - `PUSHER_*` (опционально, для realtime)
  - `SMTP_*` (опционально, для email-уведомлений)
  - `HEALTH_CHECK_TOKEN` (мониторинг: `/api/health.php?token=...`)
  - `NOTIFY_ALLOWED_DOMAINS` (домены для email модераторов, напр. `gov.kz`)
  - `KAZLLM_*` (опционально, перевод контента через Ollama — см. `docs/KAZLLM.md`)
- [ ] `composer install --no-dev --optimize-autoloader` на сервере
- [ ] Права на запись: `uploads/`, `cache/` (если используется)
- [ ] Убедиться, что в репозитории лежат `uploads/.htaccess`, `uploads/photos/.htaccess`, `uploads/scans/.htaccess` (фото и сканы — только через API)

## 2. База данных

- [ ] Импорт `database.sql` (или `deploy_database.sql`)
- [ ] Выполнить `database_indexes_performance.sql`
- [ ] Создать пользователей через админку или `seed_users.php` (только локально, на проде — вручную)

## 3. Веб-сервер

- [ ] Document root → корень проекта (где `index.php`)
- [ ] Apache: `mod_rewrite`, `mod_headers` включены
- [ ] PHP **8.0+**, расширения: `pdo_mysql`, `json`, `mbstring`, `fileinfo`
- [ ] `upload_max_filesize` / `post_max_size` ≥ 20M (в `.htaccess` уже задано)
- [ ] HTTPS включён (Let's Encrypt)

## 4. Безопасность перед продом

- [ ] Удалить или закрыть: `db_check.php`, `setup_local.php`, `install_db.php`
- [ ] Сменить пароли демо-пользователей
- [ ] Проверить, что `.env` недоступен из браузера
- [ ] Настроить `ALLOWED_ORIGINS` в `.env` при необходимости CORS
- [ ] Ключ Pusher задаётся в `.env` (`PUSHER_KEY`) и отдаётся клиенту через `api/config_public.php` — не хардкодить в HTML
- [ ] Фото членов ОС отдаются только через `/api/member_photo.php` (прямой доступ к `uploads/photos/` закрыт)

## 5. Cron

```bash
# Каждый час — проверка сроков писем
0 * * * * php /path/to/project/cron_deadlines.php >> /var/log/os-cron.log 2>&1
```

## 6. Smoke-тест после выкладки

1. Открыть `/login.html` — форма входа
2. Войти → редирект на `/api/`
3. Дашборд: KPI и графики загружаются
4. Входящие: фильтры, добавление письма, пагинация
5. Мобильный вид (DevTools ≤ 390px): нижняя навигация, карточки таблиц
6. Экспорт CSV/JSON
7. `/api/health.php?token=ВАШ_HEALTH_CHECK_TOKEN` — `{"status":"ok"}` (без токена — только admin)
8. Админка `/admin/` — для супер-админа

## 7. Версии статики

После изменений CSS/JS обновите `?v=` в `api/index.html`, `login.html`, `admin/index.html` для сброса кэша браузера.

Текущая версия статики: **?v=15** (CSS, JS). `csrf-handler.js` — **?v=5** (меняется редко).

## 8. SaaS: юридические страницы и реквизиты

Перед продажей подписки нескольким советам:

1. Отредактируйте `/js/site-config.js` — укажите реальные данные ТОО:
   - `operatorName`, `bin`, `email`, `dpoEmail`, `phone`, `address`, `siteUrl`
2. Проверьте страницы:
   - `/legal/privacy.html` — политика ПДн (рус + қаз)
   - `/legal/terms.html` — пользовательское соглашение SaaS
   - `/help/` — справка и FAQ
3. На `login.html` — чекбокс согласия с политикой при входе
4. Рекомендуется согласовать тексты с юристом под ваше ТОО

## 9. Защита от утечек данных (insider threat)

- Экспорт JSON/CSV/PDF — **только роль `admin`**, через `/api/export.php` с записью в аудит
- Скачивание сканов — проверка региона + аудит + лимит 60/час на пользователя
- Экспорт региона в админке — лимит 5/час + аудит
- В админке → **Аудит** → фильтр «Только экспорт/скачивания» для еженедельной проверки
- Рекомендуемые роли: секретариат — `moderator`, председатель — `viewer`, IT — `admin`

