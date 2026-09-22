# Workspace Organizer — дорожная карта развития

Актуально: 22 сентября 2026.

Этот документ описывает текущее состояние и следующий продуктовый план. Исторические аудиты и старые PR сохраняются как свидетельства развития, но их закрытые пункты не являются текущими блокерами без нового воспроизводимого дефекта.

## 1.0.x — текущее состояние

### Что уже завершено

Линия 1.0 прошла основную архитектурную стабилизацию:

- все шесть встроенных production-модулей `admin`, `files`, `messenger`, `notes`, `profile`, `tasks` используют `runtime.mode = isolated`;
- рекурсивный загрузчик product-кода из `app/*` удалён из runtime-контракта;
- межмодульные интеграции используют capabilities/contracts вместо прямого чтения внутренних файлов соседнего модуля;
- browser lifecycle для Notes, Tasks, Files, Profile и Admin, HTTPS/WSS E2E, WebSocket deployment и storage fault injection входят в релизную матрицу;
- published baseline — `v1.0.1`;
- File Manager regression, перенос Profile/Admin/Messenger и пустые production trust roots из старых аудитов закрыты и не должны возвращаться в работу без новой регрессии.

Историческая последовательность PR #136–#146 остаётся полезной для понимания миграции, но больше не является списком незавершённых задач.

### 1.0.2 — финальная стабилизация перед 1.1

`1.0.2` — patch-релиз, сфокусированный на надёжности Messenger, signed updater и эксплуатационной готовности.

В текущий состав входят:

- WebSocket как основной realtime-транспорт Messenger;
- аутентифицированный HTTP Long Poll как автоматический fallback с возвратом на WebSocket после восстановления;
- исправление границы Long Poll timeout без потери события;
- сохранение неподтверждённого черновика Messenger при 403/503/network/handler failure;
- явная CSRF-защита HTTP mutation fallback;
- exact upgrade drill `v1.0.1 -> 1.0.2` с принудительным post-switch failure и проверкой автоматического отката кода и БД;
- Windows/PHP 8.1/8.3 compatibility checks и ручная приёмка OSPanel 5.2.2;
- единый обязательный набор release checks для `1.0` и `master`;
- неизменяемый production ZIP, manifest/signature и trust canaries как финальная операторская граница.

Финальная процедура и открытые human/operator gates ведутся в `docs/RELEASE_STATUS_1.0.md` и `docs/RELEASE_ACCEPTANCE.md`.

### 2FA/TOTP

2FA/TOTP остаётся отдельным незавершённым пользовательским запросом. В текущем release contract она **не включена в 1.0.2** и не должна механически подмешиваться в уже замороженный RC. Это не означает отмену функции: перед интеграцией необходимо закрыть ротацию `UNIQUE_KEY` для TOTP-секретов, восстановление доступа и полный upgrade/login lifecycle.

### Историческая граница 1.0

Старые записи о красном File Manager lifecycle, Draft Profile #146, legacy Admin/Messenger и существовании переходного recursive loader относятся к промежуточному состоянию сентября 2026 и больше не описывают текущий код.

## 1.1 — Ежедневник + Calendar

Исторический README от 15 апреля 2024 года (commit `786a321b844661539ac36e6e4e885809eb874b02`) отдельно перечислял **«Ежедневник»**. Это отдельный будущий модуль личного планирования, связанный с календарём. Он не является названием или частью модуля Tasks.

Текущий Tasks отвечает за персональные и совместные доски задач: work items, kanban/list представления, статусы, категории, сроки, приоритеты и shared boards.

Целевой Ежедневник / Calendar contract:

- day/week/month calendar views;
- agenda/day planner view;
- события и recurring events;
- reminders и уведомления;
- time blocks и планирование дня;
- связь календарного события с Task/Note/File через публичные module contracts;
- личные календари и, при необходимости, shared calendars;
- timezone-safe storage/rendering;
- import/export interoperability как post-MVP capability;
- собственные permissions/storage/schema/migrations и isolated module package с первого дня.

Tasks и Ежедневник взаимодействуют, но остаются двумя независимыми модулями: Tasks управляет задачами и досками, Ежедневник — временем, расписанием и календарным контекстом.

## 1.2 — Media playback extension

Исторический README упоминал мультимедиа-плеер. Актуальная продуктовая модель: это прежде всего **расширение платформы и модулей**, а не самостоятельный пользовательский раздел, дублирующий Files.

Целевой media capability:

- единый viewer/player contract для модулей, которые работают с медиа;
- воспроизведение audio/video из Files, Notes attachments, Messenger attachments и будущих модулей;
- inline preview без дублирования исходных file bytes;
- range requests / streaming для больших файлов;
- общий resume position/history там, где это уместно;
- metadata, duration, thumbnails/covers и media probing;
- playlists/queue могут предоставляться хост-модулем поверх общего player API;
- capability-based integration: модуль передаёт разрешённый media resource, player не читает чужие внутренние таблицы напрямую;
- codec/container support развивается через безопасные backend/browser adapters и явно заявленные capabilities.

Архитектурно это reusable platform extension/service с UI-компонентами, которым пользуются изолированные модули. Отдельный полноэкранный медиаплеер может появиться позже как оболочка над тем же API, но не является обязательным ядром функции.

## 1.3 — CodeExplorer / Workspace IDE

Исторический CodeExplorer задумывался значительно шире простого syntax-highlighted viewer. Целевое направление — собственное IDE-подобное рабочее пространство и постепенно **«GitHub на минималках»** внутри self-hosted Workspace Organizer.

Базовый IDE contract:

- project/repository tree;
- открытие нескольких файлов/tabs;
- syntax highlighting для основных языков;
- поиск по файлам и содержимому;
- безопасное редактирование text/code resources;
- diff viewer;
- file history;
- keyboard-oriented editor workflow;
- workspace/repository ACL;
- explicit autosave/manual-save policy и recovery drafts.

Repository layer следующего этапа:

- Git repository discovery/creation/import;
- status, staged/unstaged changes;
- commits и история;
- branches/tags;
- diffs между revisions;
- blame/history navigation;
- lightweight merge/change-review workflow;
- локальные repositories как основной сценарий, remote sync как отдельная capability;
- интеграция с Notes/Tasks для issue-like work items и документации через публичные contracts.

Дальнейшее развитие может добавить project overview, markdown/README rendering, lightweight issues/discussions, code review и другие GitHub-подобные функции. Выполнение произвольного кода, shell/terminal, build/test runner и remote Git credentials не входят автоматически в trusted core: для них потребуется отдельный sandbox/permission/security design.

## Module platform after 1.0

После физической изоляции first-party modules платформа развивается в сторону безопасно распространяемых packages:

- signed module packages и publisher trust metadata;
- package integrity до исполнения кода;
- dependency/version resolver;
- module-owned migrations с transactional upgrade/rollback story;
- install/enable/disable/quarantine/uninstall hooks;
- central entitlement service вместо локальных license checks;
- compatibility preflight перед activation;
- health state per module;
- curated first-party catalog, затем возможность controlled third-party distribution;
- module package builder использует тот же manifest/dependency resolver, что installer/updater.

Никакой downloaded module code не исполняется до signature/integrity/core-compatibility/dependency validation.

## Product evolution after restored capabilities

После Ежедневника/Calendar, media playback extension и CodeExplorer приоритет определяется реальным использованием. Кандидаты:

- cross-module universal search/index;
- notification/reminder center;
- richer shared workspace/team layer;
- automation/workflows between module events;
- PWA/offline capabilities только для flows, где conflict/recovery semantics определены;
- public API/webhook surface поверх тех же contracts, которые используют modules.

Эти пункты не считаются обещанием конкретной версии, пока не имеют отдельного approved design/contract.

## Architecture rules for all future modules

Новые модули не получают legacy exception. С первого коммита они должны:

- жить под `modules/<id>/`;
- использовать `runtime.mode = isolated`;
- объявлять зависимости/capabilities/storage/schema ownership;
- не добавлять product routes/controllers/services в общий core;
- не читать и не писать internal data другого модуля напрямую;
- иметь install/update/disable/recovery tests пропорционально риску;
- корректно отсутствовать из composition без fatal errors в core;
- не расширять trusted core только ради удобства реализации.

Cross-module extensions вроде media playback не отменяют изоляцию: они должны предоставляться через стабильный capability/service contract, а не через прямой доступ к внутренностям хост-модуля.

## Definition of roadmap completion

Пункт считается завершённым только когда реализация, migration/recovery story, runtime tests, packaging и документация согласованы. Наличие UI или manifest без физического runtime boundary не считается завершённой модульностью.
