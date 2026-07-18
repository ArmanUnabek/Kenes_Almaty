# CI и локальные проверки

## GitHub Action — `.github/workflows/ci.yml`

Запускается автоматически на каждый `push` и `pull_request`. Один job `verify`:

1. **checkout** исходников.
2. **setup-php 8.3** (+ расширения `pdo_sqlite`/`sqlite3`/`mbstring`/… для
   тестовой in-memory БД) + `composer install --no-interaction --no-progress`
   (ставит зависимости, включая `chillerlan/php-qrcode` и `phpunit`).
3. **setup-node (LTS)** + `npm ci || npm install`.
4. **PHP lint** — `php -l` рекурсивно по всем `*.php`, кроме `vendor/` и
   `node_modules/`. Падает при любой синтаксической ошибке.
5. **JS syntax check** — `node --check` по ключевым бандлам
   (`dist/app.js`, `dist/admin.js`, `dist/login.js`) и всем `api/js/*.js`.
6. **Build** — `npm run build` (esbuild собирает все три бандла
   `dist/{app,login,admin}.js` через `scripts/build-frontend.mjs`).
7. **Referenced assets exist** — `scripts/check-referenced-assets.sh`: каждый
   локальный `/assets|/dist|/js`-ресурс, на который ссылаются страницы
   (`api/index.php`, `login.php`, `appeal.php`, `api/docs/index.php`,
   `admin/index.html`, `login.html`), обязан присутствовать в репозитории
   (ловит пропавшие файлы вроде бандла Bootstrap/Swagger, которые
   `php -l`/`node --check` не видят).
8. **Single asset version** — `scripts/check-asset-versions.sh`: единая `?v=N`
   на всех tracked html/php-страницах (в т.ч. SPA-шелл `api/index.php`), чтобы
   деплой не отдавал смешанный кэш. Бампить — `scripts/bump-assets.sh <N>`.
9. **Unit tests (PHPUnit)** — `vendor/bin/phpunit --no-coverage`, весь набор
   (158 тестов): ловит регрессии схемы БД, CSRF, TOTP и пр.
10. **Smoke test** — `php tests/smoke.php`. Lint-секция отрабатывает,
    HTTP-секция скипается без запущенного сервера (WARN, exit 0).

Никаких внешних CDN/Pusher — только официальные GitHub Actions.

## Локальный pre-commit хук — `scripts/pre-commit.sh`

Быстрый хук: проверяет **только staged-файлы** (`git diff --cached`):
- `php -l` для изменённых `*.php`;
- `node --check` для изменённых `*.js`.

При синтаксической ошибке коммит отклоняется. Обойти: `git commit --no-verify`.

### Установка

Символьная ссылка (рекомендуется — обновляется вместе с репозиторием):

```bash
ln -sf ../../scripts/pre-commit.sh .git/hooks/pre-commit
chmod +x .git/hooks/pre-commit
```

Либо копия:

```bash
cp scripts/pre-commit.sh .git/hooks/pre-commit
chmod +x .git/hooks/pre-commit
```

### Windows

Git for Windows выполняет хук через встроенный bash, поэтому символьная
ссылка/копия работает так же. Если `ln` недоступен — используйте вариант с `cp`.

Проверить хук вручную (без коммита):

```bash
bash scripts/pre-commit.sh
```
