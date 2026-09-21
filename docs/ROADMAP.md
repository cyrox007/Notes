# Workspace Organizer — Development Roadmap

Актуально: 21 сентября 2026.

Этот документ — текущая рабочая дорожная карта. Он не заменяет исторические audit/runbook документы и не объявляет незавершённую работу готовой. Статусы ниже отражают фактическое состояние ветки `1.0` на момент обновления.

Источники roadmap разделены намеренно:

1. **release blockers 1.0** — незавершённые технические обязательства текущей линии;
2. **восстановленный исходный продуктовый план** — функции, явно перечисленные в историческом README проекта;
3. **post-1.0 развитие** — рекомендации по эксплуатации, модульной платформе и дальнейшему развитию после стабильного релиза.

## 1.0 — текущий статус

### Завершённые prerelease-направления

- **Native WebSocket hardening и Messenger activity** — PR #136 слит в `1.0`: bounded outbound buffering, RFC6455 CLOSE validation, per-connection failure isolation, executable socket regressions и realtime activity states.
- **Protocol/origin hardening** — PR #137 слит в `1.0`: browser assets и same-origin Messenger WebSocket больше не завязаны на scheme из `SITEURL`; HTTP/HTTPS и WS/WSS следуют фактическому request origin; forwarded headers доверяются только через `TRUSTED_PROXY_IPS`.
- **UI control convergence** — PR #138 слит в `1.0`: глобальный alpha-era `FormInput` stylesheet убран из native shell, введён общий 1.0 control layer; module-specific geometry остаётся собственностью модулей.
- **Module runtime foundation** — PR #139 слит; изолированный runtime loader и capability/runtime boundary используются как основа физической миграции модулей.
- **Tasks naming correction** — PR #141 слит: текущий Tasks не маскируется под будущий модуль «Ежедневник».
- **Notes isolation** — PR #142 слит.
- **Tasks isolation** — PR #143 слит; browser lifecycle evidence усилен PR #144.
- **Files isolation** — PR #145 слит.

### Оставшиеся P0 blockers перед 1.0.0

#### File Manager browser lifecycle regression

После #145 на чистой ветке `1.0` воспроизводится красный `File Manager browser lifecycle`, при этом `File Manager HTTP integrity` остаётся зелёным. Регрессия должна быть исправлена отдельным изменением Files lifecycle до финального GO. Она не относится к #136/#137/#138 и не должна маскироваться изменениями других модулей.

#### Physical module runtime isolation

Физическая изоляция уже частично выполнена; универсальное утверждение «все production modules остаются legacy» больше не соответствует состоянию проекта.

Завершено:

1. isolated runtime loader/foundation;
2. Notes;
3. Tasks;
4. Files.

Осталось:

5. Profile — PR #146, Draft до зелёной базы и повторной проверки;
6. Admin;
7. Messenger — после уже выполненной синхронизации native WebSocket и protocol/origin boundaries.

Целевой isolated module owns:

- bootstrap/runtime provider;
- routes;
- controllers/services/domain models;
- views/assets;
- permissions/capabilities;
- storage namespace;
- migrations/schema ownership metadata;
- healthcheck;
- lifecycle hooks/update metadata.

Core после миграции загружает только platform/shared primitives. Межмодульный доступ допускается через явный contract/capability/service, а не прямое подключение чужих внутренних файлов.

Release gate остаётся прежним: ни один bundled production module не должен оставаться `runtime.mode = legacy` к стабильному 1.0.0.

#### Repository convergence / dead-code cleanup

После подтверждения runtime coverage:

- удалить неиспользуемые `.tpl` duplicates после native-only renderer migration;
- удалить пустые compatibility classes и мёртвые legacy UI artifacts;
- объединить временные QA/polish CSS layers там, где они только перекрывают друг друга;
- удалить тесты только когда их invariant полностью покрыт более сильным contract/E2E, а не ради уменьшения количества файлов;
- release bundle не должен содержать исторические runtime artifacts, которые никогда не исполняются.

#### Release ceremony

Перед тегом 1.0.0:

- production key ceremony: независимые license/update signing keys;
- full Beta4 -> 1.0 signed upgrade drill;
- destructive-boundary rollback drill;
- recovery drill после simulated crash;
- fresh-install hosting drill;
- финальный branch/unique-commit audit;
- RC/release notes и production runbook review;
- полный CI/release gate на точном release commit;
- отдельное подтверждение, что File Manager browser lifecycle и все module lifecycle gates зелёные.

Наличие стабильного значения `Core\Version` само по себе не является GO-сигналом для релиза.

## 1.0.2 — stabilization before 1.1.0

`1.0.1` опубликован как stable release. Перед открытием feature-cycle `1.1.0` выпускается ещё один patch-релиз `1.0.2`, сфокусированный на эксплуатации и updater delivery, а не на новых продуктовых модулях.

Scope `1.0.2`:

- единый operator flow поверх существующего signed updater: remote staging → maintenance → verified code+MySQL rollback backup → external release candidate → transactional live apply;
- fail-closed recovery: после начала live mutation только `UpdateApplyCommand` владеет rollback/recovery и снятием maintenance;
- production signed feed/manifest/signature delivery и реальный upgrade drill `1.0.1 -> 1.0.2`;
- дальнейшая доработка Admin Updates UX без прямого unzip/overwrite и без обхода transaction journal;
- проверка updater/recovery на Windows/OSPanel и Linux hosting profiles;
- повторяемые backup/restore drills и эксплуатационные health/incident checks;
- актуализация release/runbook документации после фактической проверки обновления.

`1.0.2` считается завершённым только после exact-head CI, реального upgrade/rollback drill и публикации подписанного update manifest. После этого development переключается на `1.1.0`.

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
