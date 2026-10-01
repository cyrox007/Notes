# Применение обновления и откат

Документ описывает разрушительную часть подписанного updater после того, как пакет уже проверен, помещён во внешний staging, для него создан backup и подготовлен release candidate.

## Граница безопасности

До изменения live tree транзакция обязана:

1. владеть внешним operation lock;
2. владеть maintenance mode;
3. повторно проверить backup;
4. повторно проверить candidate tree;
5. подтвердить ожидаемую исходную версию;
6. выполнить текущий healthcheck;
7. выполнить candidate migration dry-run;
8. зафиксировать `live_mutation_started=true` перед первым разрушительным действием.

После этой отметки новый `--apply` запрещён: используется recovery.

## Переключение кода

Updater не распаковывает ZIP поверх рабочей установки.

Release-owned верхнеуровневые элементы подготавливаются во временном каталоге на том же filesystem и переключаются контролируемыми filesystem operations.

Installation-specific состояние сохраняется отдельно: `.env`, private storage, updater state и другие явно разрешённые mutable roots.

## Миграции

После переключения кода запускается migration runner новой версии.

В 1.0.13 учтено окно, когда новый код уже активен, а старая схема ещё не знает нового значения lifecycle: до миграции нелицензированный модуль закрыто блокируется совместимым состоянием, после миграции reconcile записывает `unlicensed`.

## Post-health

После миграций updater проверяет:

- версию;
- healthcheck;
- состояние миграций;
- критические runtime-контракты;
- WebSocket.

Если Messenger после обновления разрешён, WebSocket перезапускается. Если Messenger больше не входит в лицензионную композицию, прежний процесс штатно останавливается и это не считается ошибкой всего релиза.

## Rollback

После destructive boundary авторитетный recovery artifact — проверенный pre-update backup, а не release candidate.

Rollback:

- возвращает release-owned код;
- восстанавливает БД из проверенного dump;
- проверяет исходную версию и health;
- возвращает WebSocket к исходному состоянию;
- снимает maintenance только после подтверждённого terminal-state.

## Recovery после обрыва

Журнал транзакции и maintenance state находятся вне application tree. Если PHP/process оборвался, следующий ранний HTTP boot-gate продолжает recovery до загрузки обычного приложения.

CLI recovery остаётся аварийным последним средством.

## Запуск

Основной пользовательский путь — Admin → Updates.

Низкоуровневые команды применяются для диагностики и тестовых контуров:

```bash
php bin/update_apply.php --apply --transaction=<id>
php bin/update_apply.php --recover --transaction=<id>
```

## Проверки

Обязательны сценарии success, migration failure, post-health failure, прерывание процесса, rollback кода/БД, stale recovery и повторный запуск после terminal-state.
