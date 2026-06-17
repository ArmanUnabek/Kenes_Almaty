# Чеклист выкладки на хостинг

## 1. Файлы и окружение

- [ ] Скопировать все файлы проекта на сервер (кроме `.git`, `vendor/` если ставите на сервере)
- [ ] Создать `.env` из `.env.example` и заполнить:
  - `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`
  - `PUSHER_*` (опционально, для realtime)
  - `SMTP_*` (опционально, для email-уведомлений)
- [ ] `composer install --no-dev --optimize-autoloader` на сервере
- [ ] Права на запись: `uploads/`, `cache/` (если используется)

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
7. `/api/health.php` — `{"status":"ok"}`
8. Админка `/admin/` — для супер-админа

## 7. Версии статики

После изменений CSS/JS обновите `?v=` в `api/index.html`, `login.html`, `admin/index.html` для сброса кэша браузера.

Текущая версия стилей: **styles.css?v=12**

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

