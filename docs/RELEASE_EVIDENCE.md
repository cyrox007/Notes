# Доказательная база релиза 1.0

Workspace Organizer 1.0 хранит release evidence в воспроизводимом и машиночитаемом виде. Автоматический release-evidence gate дополняет уже существующие проверки lifecycle модулей, HTTPS/WSS, installer, updater, rollback, security, retention и production health.

## Кроссбраузерные и мобильные проверки

Автоматическая browser-матрица авторизует реального пользователя и проверяет основную оболочку workspace, а также Notes, Tasks, Files и Profile в следующих конфигурациях:

- Chromium desktop, 1366x768;
- Firefox desktop, 1366x768;
- WebKit desktop, 1366x768;
- Chromium mobile, touch/mobile context 390x844;
- WebKit mobile, touch/mobile context 412x915.

Каждый сценарий обязан:

- пройти реальный login flow;
- отрисовать основное содержимое workspace;
- загрузить Notes, Tasks, Files и Profile с HTTP 200;
- не создавать JavaScript page errors;
- удерживать документ в заданном viewport без горизонтального переполнения страницы.

Мобильные сценарии дополнительно проверяют реальный lifecycle боковой панели: изначально закрыта, открывается через кнопку меню, показывает backdrop и закрывается через Escape с корректным состоянием `aria-expanded`.

Существующий workflow Browser HTTPS and WSS E2E остаётся release evidence для авторизованного realtime Messenger: reconnect, messages и transient activity presence. Эта матрица не дублирует WSS-тест во всех трёх браузерных движках.

## Нагрузочные и длительные проверки

После того как browser-матрица экспортирует пять независимых авторизованных PHP sessions, тот же release-candidate process получает авторизованные GET-запросы, распределённые round-robin по этим сессиям. Это не позволяет ошибочно измерять блокировку одной PHP session как конкурентность всего приложения.

Нагрузочные цели:

- главная workspace;
- Notes;
- Tasks;
- Files;
- Profile.

Пороговые значения CI по умолчанию:

- фиксированная нагрузка: 600 запросов с concurrency 12;
- soak: 45 секунд с concurrency 4;
- допустимые HTTP/auth/transport errors: 0;
- максимальная p95 latency: 2500 мс для обеих фаз;
- минимальная пропускная способность fixed-load: 5 запросов/сек;
- минимальная пропускная способность soak: 3 запроса/сек.

Это release-regression thresholds для GitHub runner, а не заявление о production capacity. Реальный sizing production зависит от CPU, базы данных, storage, reverse proxy, сети и характера нагрузки. Увеличивать thresholds только для сокрытия регрессии недопустимо; изменение порога требует отдельного проверенного обоснования.

## Артефакты проверки

Workflow загружает машиночитаемые артефакты для точного commit:

- JSON кроссбраузерных/мобильных проверок;
- JSON load/soak;
- JSON healthcheck после выполнения.

CI также требует чистый PHP runtime log: fatal, uncaught и parse errors приводят к провалу evidence job.

## Приёмка релиза

Автоматические evidence не подменяют реальное human beta evidence. Перед финальным merge в `master` и созданием tag `v1.0.0` владелец релиза дополнительно должен зафиксировать решение о приёмке release candidate и подтвердить:

1. нет открытых P0/P1 дефектов с потерей данных;
2. нет открытых P0/P1 security-дефектов;
3. нет нерешённых release-blocking регрессий, найденных на beta/RC тестировании;
4. точный release head имеет зелёные release evidence и Stable release gate;
5. production evidence по backup/restore, upgrade и rollback, требуемые release runbook, актуальны.

Если продукт не проходил проверку на репрезентативной группе реальных beta-пользователей, это нужно зафиксировать явно, а не заявлять о beta coverage, которого фактически не было.
