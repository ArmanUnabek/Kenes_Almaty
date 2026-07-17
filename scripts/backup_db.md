# Бэкап базы данных (`scripts/backup_db.sh`)

Скрипт делает дамп MySQL/MariaDB базы через `mysqldump --single-transaction`
(консистентный дамп InnoDB без блокировок), сжимает gzip'ом и хранит
последние **14** архивов (более старые удаляются автоматически).

## Откуда берутся креды

Тот же механизм, что и у `db.php`:

1. Переменные окружения `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
   (имеют высший приоритет);
2. иначе — файлы `.env`, затем `.env.local` в корне проекта
   (формат `KEY=VALUE`, `#` — комментарий; `.env.local` перезаписывает `.env`).

Ничего дополнительно настраивать не нужно — на проде уже есть `.env.local`.

## Запуск вручную

```bash
cd /path/to/project
chmod +x scripts/backup_db.sh   # один раз
./scripts/backup_db.sh                       # дампы в ./backups/
./scripts/backup_db.sh /home/user/db_backups # или в свой каталог
```

Имя файла: `<DB_NAME>_YYYY-MM-DD_HHMMSS.sql.gz`.

## Crontab (ежедневно в 03:00)

```
0 3 * * * /bin/bash /path/to/project/scripts/backup_db.sh /home/user/db_backups >> /home/user/db_backups/backup.log 2>&1
```

Замените `/path/to/project` и `/home/user/db_backups` на реальные пути.
Добавить: `crontab -e`, вставить строку, сохранить.

## Восстановление из бэкапа

```bash
gunzip -c backups/p-354458_3_2026-07-12_030000.sql.gz | mysql -h localhost -u DB_USER -p DB_NAME
```

## Важно

- Каталог с бэкапами должен быть **вне** web-root или закрыт от раздачи
  (иначе дампы будут доступны по HTTP).
- Пароль передаётся через `MYSQL_PWD`, а не в аргументах — не виден в `ps`.
- Скрипт завершится с ошибкой (exit 1), если дамп пустой или mysqldump упал —
  cron пришлёт вывод на почту, если настроен `MAILTO`.
