# Crontab для проекта «Журнал ОС»

```crontab
# Напоминания о дедлайнах писем в Telegram — ежедневно в 09:00
0 9 * * * php /var/www/cron_deadline_reminders.php >> /var/log/os_journal_reminders.log 2>&1
```

Альтернатива по HTTP (если нет CLI-доступа):

```crontab
0 9 * * * curl -s "https://example.com/cron_deadline_reminders.php?token=<CRON_TOKEN>" >> /var/log/os_journal_reminders.log 2>&1
```

`CRON_TOKEN` задаётся в `.env.local`.
