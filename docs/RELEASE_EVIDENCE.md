# Доказательства готовности релиза 1.0

Workspace Organizer 1.0 хранит доказательства релизной готовности в воспроизводимом и машиночитаемом виде. Автоматический gate release-evidence дополняет существующие проверки lifecycle модулей, HTTPS/WSS, installer, updater, rollback, security, retention и production health.

## Cross-browser и mobile evidence

Автоматическая browser-матрица аутентифицирует реального пользователя и проверяет основной workspace shell, а также Notes, Tasks, Files и Profile в следующих окружениях:

- Chromium desktop, 1366x768;
- Firefox desktop, 1366x768;
- WebKit desktop, 1366x768;
- Chromium mobile, 390x844, touch/mobile context;
- WebKit mobile, 412x915, touch/mobile context.

Каждый сценарий должен:

- выполнить реальный login flow;
- отрисовать основной контент workspace;
- загрузить Notes, Tasks, Files и Profile с HTTP 200;
- не создавать JavaScript-ошибок страницы;
- удерживать документ в заданном viewport без горизонтального overflow документа.

Mobile-сценарии дополнительно подтверждают реальный lifecycle sidebar: изначально закрыт, открывается через menu control, показывает backdrop и закрывается через Escape с корректным состоянием `aria-expanded`.

Существующий workflow Browser HTTPS and WSS E2E остаётся релизным доказательством аутентифицированного realtime-поведения Messenger. Он проверяет предпочтительный WSS path, принудительный reconnect со свежим ticket, автоматический HTTP long-poll fallback без блокировки пула PHP workers и доставку durable HTTP-fallback mutation клиенту, который остаётся подключённым через WebSocket, посредством общего DB revision bridge. Transient typing/activity остаётся дополнительной возможностью WebSocket. Эта матрица намеренно не дублирует один и тот же transport-тест в трёх browser engines.

## Load и soak evidence

После того как browser-матрица экспортирует пять независимых аутентифицированных PHP sessions, тот же процесс релиз-кандидата нагружается аутентифицированными GET-запросами, распределёнными round-robin между этими sessions. Это не позволяет ошибочно измерять lock одной PHP session как concurrency приложения.

Целевые endpoints нагрузки:

- workspace home;
- Notes;
- Tasks;
- Files;
- Profile.

Пороговые значения CI по умолчанию:

- fixed load: 600 запросов с concurrency 12;
- soak: 45 секунд с concurrency 4;
- допустимые HTTP/auth/transport errors: 0;
- максимальная p95 latency: 2500 мс для обеих фаз;
- минимальный fixed-load throughput: 5 запросов/секунду;
- минимальный soak throughput: 3 запроса/секунду.

Это пороги release-regression для GitHub runner, а не заявление о production capacity. Реальные требования к production по-прежнему зависят от CPU, базы данных, storage, reverse proxy, сети и характера нагрузки. Увеличивать пороги, чтобы скрыть регрессию, недопустимо; изменение порога требует явного проверенного обоснования.

## Артефакты доказательств

Workflow загружает машиночитаемые artifacts для точного commit:

- cross-browser/mobile JSON;
- load/soak JSON;
- post-run healthcheck JSON.

CI также требует чистый PHP runtime log: fatal, uncaught и parse errors приводят к падению evidence job.

## Релизная приёмка

Автоматические доказательства не подменяют human beta evidence. До финального merge в `master` и создания тега релиза владелец релиза дополнительно должен зафиксировать решение о приёмке release candidate и подтвердить:

1. нет открытых P0/P1 дефектов с риском потери данных;
2. нет открытых P0/P1 дефектов безопасности;
3. нет неразрешённой release-blocking regression, найденной в beta/RC testing;
4. exact release head имеет зелёные release evidence и Stable release gate;
5. требуемые release runbook доказательства production backup/restore, upgrade и rollback актуальны.

Если продукт не проходил через репрезентативную human beta cohort, зафиксируйте это явно вместо заявления о несуществующем beta coverage.
