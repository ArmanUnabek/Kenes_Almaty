#!/usr/bin/env bash
#
# Быстрый локальный pre-commit хук.
# Проверяет только staged-файлы (git diff --cached):
#   - php -l   для изменённых *.php
#   - node --check для изменённых *.js
# Установка: см. scripts/ci.md
#
set -euo pipefail

# Список staged-файлов (Added / Copied / Modified), с учётом переименований.
staged() {
  git diff --cached --name-only --diff-filter=ACM -- "$@"
}

fail=0

# --- PHP ---
if command -v php >/dev/null 2>&1; then
  while IFS= read -r file; do
    [ -z "$file" ] && continue
    case "$file" in
      vendor/*|node_modules/*) continue ;;
    esac
    [ -f "$file" ] || continue
    if ! php -l "$file" >/dev/null; then
      echo "  PHP syntax error: $file"
      fail=1
    fi
  done < <(staged '*.php')
else
  echo "  [warn] php не найден в PATH — PHP-проверка пропущена"
fi

# --- JS ---
if command -v node >/dev/null 2>&1; then
  while IFS= read -r file; do
    [ -z "$file" ] && continue
    case "$file" in
      vendor/*|node_modules/*) continue ;;
    esac
    [ -f "$file" ] || continue
    if ! node --check "$file"; then
      echo "  JS syntax error: $file"
      fail=1
    fi
  done < <(staged '*.js')
else
  echo "  [warn] node не найден в PATH — JS-проверка пропущена"
fi

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "pre-commit: найдены синтаксические ошибки. Коммит отклонён."
  echo "Исправьте ошибки или используйте 'git commit --no-verify' для обхода."
  exit 1
fi

exit 0
