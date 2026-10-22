#!/usr/bin/env bash
set -euo pipefail

# Изменяется только cron текущего пользователя, без прав администратора ОС.
MODE="${1:-status}"
MARKER='# notes-tasks-reminder-cycle'
ROOT="$(cd "$(dirname "$0")/../.." && pwd -P)"
SCRIPT="$ROOT/bin/tasks_reminder_cycle.php"

if [[ "$MODE" != install && "$MODE" != remove && "$MODE" != status ]]; then
    echo 'Использование: bash tools/scheduler/tasks-reminders-linux.sh [status|install|remove]' >&2
    exit 2
fi
if ! command -v crontab >/dev/null 2>&1; then
    echo 'Программа crontab не установлена.' >&2
    exit 2
fi
if [[ ! -f "$SCRIPT" ]]; then
    echo 'Сценарий фонового цикла Notes не найден.' >&2
    exit 2
fi

CURRENT="$(crontab -l 2>/dev/null || true)"
CLEANED="$(printf '%s\n' "$CURRENT" | grep -Fv "$MARKER" || true)"
if [[ "$MODE" == status ]]; then
    if grep -Fq "$MARKER" <<< "$CURRENT"; then
        echo 'Расписание напоминаний Notes установлено для текущего пользователя.'
    else
        echo 'Расписание напоминаний Notes не установлено для текущего пользователя.'
    fi
    exit 0
fi
if [[ "$MODE" == remove ]]; then
    printf '%s\n' "$CLEANED" | crontab -
    echo 'Задание напоминаний Notes удалено.'
    exit 0
fi

PHP_BIN="${NOTES_PHP_BIN:-$(command -v php || true)}"
LOGGER_BIN="$(command -v logger || true)"
if [[ -z "$PHP_BIN" || ! -x "$PHP_BIN" || -z "$LOGGER_BIN" ]]; then
    echo 'Нужны исполняемые PHP 8.3+ и logger.' >&2
    exit 2
fi
if ! "$PHP_BIN" -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
    echo 'Для фонового цикла требуется PHP 8.3 или новее.' >&2
    exit 2
fi
# Cron воспринимает % как перевод строки; запрещаем также перевод строки и кавычки.
for VALUE in "$ROOT" "$PHP_BIN" "$LOGGER_BIN"; do
    if [[ "$VALUE" == *"'"* || "$VALUE" == *'%'* || "$VALUE" == *$'\n'* || "$VALUE" == *$'\r'* ]]; then
        echo 'Путь содержит символы, недопустимые для записи cron.' >&2
        exit 2
    fi
done
ENTRY="*/15 * * * * cd '$ROOT' && '$PHP_BIN' bin/tasks_reminder_cycle.php --json 2>&1 | '$LOGGER_BIN' -t notes-tasks-reminders $MARKER"
{ printf '%s\n' "$CLEANED"; printf '%s\n' "$ENTRY"; } | sed '/^$/d' | crontab -
echo 'Задание установлено: каждые 15 минут. Проверяйте состояние в админке Notes.'
