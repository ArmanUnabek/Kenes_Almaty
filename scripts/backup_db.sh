#!/usr/bin/env bash
#
# backup_db.sh — резервное копирование MySQL/MariaDB базы проекта.
#
# Креды берутся из переменных окружения (DB_HOST, DB_PORT, DB_NAME, DB_USER,
# DB_PASS) или из .env / .env.local в корне проекта (тот же формат KEY=VALUE,
# что читает db.php; .env.local имеет приоритет).
#
# Использование:
#   ./scripts/backup_db.sh [backup_dir]
#   backup_dir по умолчанию: <корень проекта>/backups
#
# Ротация: хранит последние 14 архивов, более старые удаляются.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
BACKUP_DIR="${1:-$PROJECT_ROOT/backups}"
KEEP=14

# --- Загрузка .env / .env.local (KEY=VALUE, # — комментарий) -----------------
load_env_file() {
    local file="$1"
    [ -f "$file" ] || return 0
    while IFS= read -r line || [ -n "$line" ]; do
        # пропускаем пустые строки и комментарии
        case "$line" in
            ''|\#*) continue ;;
        esac
        case "$line" in
            *=*)
                local key="${line%%=*}"
                local value="${line#*=}"
                # trim пробелов вокруг ключа
                key="$(echo "$key" | tr -d '[:space:]')"
                # снять обрамляющие кавычки, если есть
                value="${value%$'\r'}"
                case "$value" in
                    \"*\") value="${value#\"}"; value="${value%\"}" ;;
                    \'*\') value="${value#\'}"; value="${value%\'}" ;;
                esac
                export "$key=$value"
                ;;
        esac
    done < "$file"
}

# Значения из окружения имеют приоритет над .env-файлами
ENV_DB_HOST="${DB_HOST:-}"; ENV_DB_PORT="${DB_PORT:-}"; ENV_DB_NAME="${DB_NAME:-}"
ENV_DB_USER="${DB_USER:-}"; ENV_DB_PASS="${DB_PASS:-}"

load_env_file "$PROJECT_ROOT/.env"
load_env_file "$PROJECT_ROOT/.env.local"

DB_HOST="${ENV_DB_HOST:-${DB_HOST:-localhost}}"
DB_PORT="${ENV_DB_PORT:-${DB_PORT:-3306}}"
DB_NAME="${ENV_DB_NAME:-${DB_NAME:-}}"
DB_USER="${ENV_DB_USER:-${DB_USER:-}}"
DB_PASS="${ENV_DB_PASS:-${DB_PASS:-}}"

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
    echo "ERROR: DB_NAME/DB_USER не заданы (окружение или .env/.env.local)" >&2
    exit 1
fi

command -v mysqldump >/dev/null 2>&1 || { echo "ERROR: mysqldump не найден" >&2; exit 1; }
command -v gzip >/dev/null 2>&1 || { echo "ERROR: gzip не найден" >&2; exit 1; }

mkdir -p "$BACKUP_DIR"

STAMP="$(date +%Y-%m-%d_%H%M%S)"
OUT="$BACKUP_DIR/${DB_NAME}_${STAMP}.sql.gz"

# Пароль через MYSQL_PWD, чтобы не светился в ps
export MYSQL_PWD="$DB_PASS"

echo "Дамп базы '$DB_NAME' -> $OUT"
mysqldump \
    --host="$DB_HOST" \
    --port="$DB_PORT" \
    --user="$DB_USER" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --default-character-set=utf8mb4 \
    "$DB_NAME" | gzip > "$OUT"

unset MYSQL_PWD

# Проверка, что дамп не пустой
if [ ! -s "$OUT" ]; then
    echo "ERROR: дамп пустой: $OUT" >&2
    rm -f "$OUT"
    exit 1
fi

# --- Ротация: оставить последние $KEEP ---------------------------------------
ls -1t "$BACKUP_DIR"/${DB_NAME}_*.sql.gz 2>/dev/null | tail -n +$((KEEP + 1)) | while IFS= read -r old; do
    echo "Удаляю старый бэкап: $old"
    rm -f -- "$old"
done

echo "OK: $(du -h "$OUT" | cut -f1) $OUT"
