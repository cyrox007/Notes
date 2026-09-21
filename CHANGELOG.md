# История версий Workspace Organizer

Формат основан на принципах Keep a Changelog. Начиная с `1.0.0` проект имеет stable platform contract: совместимость upgrade-path и пользовательских данных является release contract, а изменения схемы выполняются через явные compatibility migrations. Canonical `*_schema.sql` остаются источником текущей схемы fresh install.

## 1.0.2 — Unreleased

### Updater operations
- Добавлен единый CLI operator flow `bin/update_run.php`, который использует существующие подписанные границы: remote staging, maintenance ownership, verified code+MySQL rollback backup, external release candidate и transactional live apply.
- Destructive flow требует явный `--yes`; до начала live mutation wrapper может безопасно снять собственный maintenance, а после начала mutation rollback/recovery полностью остаются во владении `UpdateApplyCommand`.
- Для прерванной транзакции предусмотрен единый recovery-вход через `bin/update_run.php --recover --transaction=... --yes`.
- Добавлен отдельный regression contract для operator flow; прямые shell-execution shortcuts не допускаются.
- Добавлен read-only `bin/update_doctor.php`: он без сети и мутаций проверяет update trust root, PHP extensions, `proc_open`, HTTPS feed/channel, online credentials, внешние updater paths и DB configuration.
- Admin Updates показывает отдельный статус operator readiness, безопасную diagnostic command и единый CLI install/recovery flow, не добавляя destructive web endpoint.
- Vendor update/license service получил read-only health state, CLI `--status --json` и минимальный HTTPS `/health` endpoint для deployment monitoring без раскрытия credentials, лицензий или package paths.

### Release direction
- `1.0.2` является последним stabilization patch перед feature-cycle `1.1.0`.
- Основной оставшийся scope: production signed feed/manifest delivery, реальный `1.0.1 -> 1.0.2` upgrade/rollback drill, дальнейший Admin Updates UX и operations cleanup.
- Published migration/trust history `1.0.0/1.0.1` не переписывается.

## 1.0.1 — 2026-09-20

### Commercial licensing
- В подписанный installation-bound Ed25519 payload добавлено опциональное поле `max_users`; старые корректные лицензии без него остаются unlimited.
- Лимит применяется к активным аккаунтам (`users.is_active=1`): blocked-аккаунт занимает место, deactivated-аккаунт освобождает его.
- Admin provisioning, self-registration/invite registration и повторная активация деактивированного пользователя проходят через единый seat-policy boundary.
- Активация лицензии с лимитом ниже текущего количества активных аккаунтов отклоняется до замены сохранённого токена.
- Seat-changing операции сериализуются блокировкой licensing-row, чтобы параллельные регистрации не могли превысить подписанный лимит.
- Offline issuer получил `--max-users=N`; Admin/Core license UI показывает текущее использование мест.

### Module onboarding
- Добавлена практическая `docs/MODULE_DEVELOPMENT.md`: структура независимого модуля, manifest/runtime provider, routes, views/assets, capabilities, DB ownership, установка и проверка.
- Core control plane получил `php bin/control.php modules install <module-id>`, закрывающий штатный переход `discovered -> installed -> enabled` для non-bundled модулей.
- CI теперь проверяет реальный lifecycle independently installed fixture-module.

### Audit / maintenance
- Добавлен durable `user_action_log` для authenticated mutating HTTP/WebSocket операций с snapshot пользователя, фильтрацией в Admin UI и отдельным `admin.audit.view`.
- Журнал хранит только операционные метаданные: request bodies, содержимое Notes/Messenger, пароли, токены, cookie/session/CSRF не журналируются; чувствительные detail keys редактируются.
- Добавлен явный retention CLI `php bin/audit_log.php`: preview по умолчанию, irreversible purge только с `--apply --yes`; default retention — 180 дней.
- Удалён неиспользуемый legacy `.tpl` runtime tail; CI запрещает его повторное появление.

### 1.0.1 maintenance fixes / workspace integration
- Password consumers now read byte-exact POST values at credential boundaries, so complex passwords containing HTML-sensitive characters verify correctly without weakening normal request sanitization.
- Notes editor save/share controls, compact desktop sidebar, Admin license layout and Audit navigation received regression fixes discovered during local acceptance.
- Messenger received a responsive visual refresh, a single custom voice-message player, unread/global notification integration and more stable WebSocket bootstrap.
- Messenger can create Notes and Tasks directly (including from an existing message) through module capability boundaries with RBAC/role-limit enforcement and source backlinks.
- Personal File Manager content can be selected in Messenger as a copied chat attachment or revocable public link; legacy files receive stable UID backfill and link creation is controlled by the `files.can_share` role policy.
- Task/Workspace/File picker modal regressions found during acceptance are covered by native module contracts.

### Compatibility / release
- Version поднят до `1.0.1` / version code `10001`; schema и published 1.0.0 migrations не переписываются.
- Beta4 upgrade/rollback и stable release gates переведены на exact `1.0.1` identity.
- Обновлены release acceptance, production trust ceremony и инструкция выпуска лицензионных ключей.

## 1.0.0 — 2026-09-20

### Stable runtime / security boundary
- Runtime полностью отвязан от Composer `vendor/`: собственный Environment loader, native PHP view renderer и native RFC6455 WebSocket server работают из release bundle без сторонних PHP runtime packages.
- Notes, Tasks, Files, Profile, Admin и Messenger работают через isolated module runtime; Core использует deterministic composition, module-owned DB metadata и composition-aware install/update/health contracts.
- Request/Router boundary получил bounded strict JSON parsing, duplicate-route validation, fail-closed malformed requests, корректный 405 и типизированные route params.
- CSP переведён на per-request cryptographic nonce: `unsafe-inline` удалён, inline event/style attributes запрещены contract-ом.
- Hosting package исключает `.env`, `vendor/`, vendor signing tools и private signing material.

### Installation-bound licensing
- Добавлены стабильный `installation_id`, offline Ed25519 license, admin activation и recovery-safe read-only enforcement.
- License trust registry содержит только public keys; private license signing key существует только в offline vendor domain.

### Signed updater / rollback
- Реализован независимый update-signing trust domain, signed manifest, SHA-256/compatibility verification, hardened public HTTPS delivery и immutable external staging.
- ZIP audit выполняется до extraction; release candidate распаковывается вне live tree и проверяется по CRC, size и SHA-256 tree manifest.
- Перед live mutation создаются и повторно проверяются code + consistent MySQL rollback backups и durable external transaction journal.
- Migration preflight до destructive boundary является data-only: current updater читает candidate JSON/SQL и DB ledger без исполнения candidate PHP.
- Controlled live switch, migrations, post-health/version/schema verification и WebSocket restart входят в одну recovery story; ошибка после boundary автоматически восстанавливает code + MySQL.
- Crash recovery продолжает rollback по durable journal; при непроверенном rollback installation остаётся в maintenance с `rollback_failed`.

### Delivery / release evidence
- Admin UI умеет проверить signed feed и подготовить verified staged package, не открывая browser one-click destructive apply.
- Trusted external `bin/update_bootstrap.php` закрывает первый переход с опубликованной `0.14.0-beta.4`, которая предшествует updater runtime.
- CI drill устанавливает точную Beta4 через HTTP installer, доказывает signed upgrade до 1.0 и отдельно принудительный post-switch failure с automatic code + DB rollback до здоровой Beta4.
- Добавлена resumable/rollback-safe ротация `UNIQUE_KEY` / `MSG_SECRET_KEY` с maintenance boundary, checkpoints и post-rotation verification.
- Security observability пишет structured JSONL events вне application tree, редактирует чувствительный context и предоставляет threshold-based CLI summary/alerts.
- Retention contract отделяет soft-delete/deactivation от irreversible purge, использует explicit preview/apply CLI, retention timestamps и fail-closed filesystem/account ownership guards.
- Browser lifecycle coverage охватывает Notes, Tasks, Files, Profile и Admin; отдельный HTTPS/WSS smoke проверяет native Messenger realtime/reconnect path.
- Добавлен release-evidence harness для Chromium/Firefox/WebKit desktop+mobile smoke и authenticated load/soak; финальный релиз требует green evidence на точном frozen SHA.
- Завершён residual branch audit: полезные security/updater хвосты перенесены адаптированно, устаревшие ветки не мержились целиком.

## 0.14.0-beta.4 — 2026-09-15

### Roles / module policies
- Админ-панель получила полноценный Role Manager поверх persisted RBAC: создание прикладных ролей, назначение ролей пользователям и редактирование permission assignment.
- Boolean permissions отделены от типизированных `role_module_policies`, поэтому доступ к функции и её количественные/типовые ограничения больше не смешиваются.
- Role policies применяются server-side для Notes, Tasks, File Manager и Messenger: лимиты количества/размера, allowlist расширений, message rate, group/voice capabilities и collaborative-task limits.
- Общая sidebar-навигация теперь отражает effective RBAC вместо legacy numeric `users.role`; скрытие пункта меню остаётся UX-слоем, а server-side middleware/service checks — authorization boundary.
- Исправлена адаптивная сетка создания пользователей и Role Manager, чтобы admin forms не использовали пятиколоночную сетку редактора custom profile fields.

### Shared Tasks
- Добавлены shared task boards отдельно от legacy personal tasks, сохраняя прежний ownership contract личных задач.
- Доска может быть доступна выбранным участникам или динамической аудитории `all_active`; для конкретной доски действует собственный ACL owner/manager/member/viewer.
- Добавлены shared task items, несколько исполнителей, статусы/priorities/due dates и отдельный Kanban с drag-and-drop.
- Drag-and-drop shared board использует CSRF-protected server update и фиксирует карточку до asynchronous request, исключая race с `dragend`.
- Fresh schema и compatibility migration включают `task_boards`, `task_board_members`, `task_board_items`, `task_board_assignees`.

### Database / deployment / verification
- Canonical fresh install расширен до 32 обязательных таблиц; `database/tasks_schema.sql` теперь является source of truth и для shared boards, а `database/access_control_schema.sql` — для `role_module_policies`.
- `bin/migrate.php` регистрирует additive compatibility migrations для role policies и shared task boards; `bin/healthcheck.php` проверяет полный 32-table contract.
- Обновлены installer/hosting/RBAC/browser fixtures, чтобы старые lifecycle tests поднимали persisted access-control schema до выполнения новых permission middleware.
- Добавлены отдельные Beta 4 contracts для policy composition/enforcement и shared-board ACL, а static regression checks фиксируют правильное связывание Role Manager snapshot и CSRF drag-and-drop.

## 0.14.0-beta.3 — 2026-09-15

### Registration / user provisioning
- Добавлены управляемые режимы публичной регистрации: `disabled`, `open` и `invite`; безопасный default остаётся закрытым.
- Администратор может создавать обычных пользователей непосредственно из основной админ-панели независимо от публичного registration mode.
- Self-registration и admin provisioning назначают каноническую RBAC-роль `user`; выдача административных прав не смешивается с созданием аккаунта.
- Login page показывает registration entry только когда текущая policy это разрешает.

### Managed invites / security
- Добавлены управляемые инвайты с label, expiry, usage limit и revoke.
- Invite code генерируется из криптографически случайных байтов, показывается один раз и хранится в `system_settings` только как SHA-256 hash с метаданными.
- Создание аккаунта и расход managed invite выполняются в одной транзакции; singleton JSON-row блокируется через `SELECT ... FOR UPDATE`, чтобы concurrent writers не теряли изменения.
- Публичный POST регистрации защищён `AuthRateLimit` + CSRF; admin provisioning и registration/invite mutations требуют соответствующих RBAC permissions + CSRF.
- Legacy `REGISTRATION_INVITE_CODE` сохранён только как compatibility fallback до первого явного сохранения registration policy в БД.

### Deployment / verification
- Добавлен `docs/DEPLOYMENT_COMPATIBILITY.md` с отдельными рекомендациями для Open Server 6+, Open Server 5.4.x с PHP 8.1+, Linux VPS/VDS и shared hosting.
- Для shared hosting явно зафиксировано, что realtime Messenger требует long-running PHP process и WebSocket reverse proxy; cron не заменяет process manager.
- Добавлены PHP 8.1 + MySQL 8.4 registration/provisioning contract и настоящий Chromium registration lifecycle.
- Перед merge пройдены все 38 PR workflows; после merge функционального PR повторно зелёные master release/installer/module/core-security gates.

## 0.14.0-beta.2 — 2026-09-15

### WebSocket deployment hotfix
- Исправлен deployment gap, при котором Workerman мог успешно слушать `127.0.0.1:27800`, а браузер не мог подключиться к `wss://<site>/ws` без настроенного reverse proxy.
- Добавлен единый `Core\WebSocketEndpoint`: same-origin public endpoint выводится из `SITEURL`/`BASE_PATH`, а HTTPS-сайт больше не получает ошибочный direct `wss://host:27800` fallback для plain listener.
- Workerman по умолчанию остаётся loopback-only (`127.0.0.1`); публичный TLS/WSS завершается на Apache/Nginx/Caddy.
- Для Apache добавлен guarded `.htaccess` bridge `/ws -> ws://127.0.0.1:27800` при доступных proxy modules; для Open Server 6+ документированы project-local `.osp` Apache/Nginx fallbacks.
- Добавлен `php bin/ws_doctor.php`, различающий внутренний listener и публичный proxy endpoint; production healthcheck показывает canonical WebSocket proxy contract.
- Новый WebSocket deployment contract проходит на PHP 8.1 и 8.3 и превращает PHP warnings в hard failure.
- Перед hotfix-релизом повторно пройдены полный HTTPS/WSS Chromium E2E, installer, security, production operations и release gates.

### Release direction
- `0.14.0-beta.2` остаётся hotfix в beta-линейке; следующий основной milestone — `1.0.0` stable без открытия нового feature cycle.

## 0.14.0-beta.1 — 2026-09-15

### Beta transition
- Проект официально переведён из product-complete alpha в первый beta hardening baseline.
- Release identity синхронизирован между `Core\Version`, README, CHANGELOG и отдельным beta-readiness contract.
- Теги prerelease вида `v*-*` публикуются как GitHub **prerelease**, а stable tags — как обычные Releases.

### Core / security hardening
- Усилен Router/session/redirect boundary: strict session cookie policy, local-only redirect policy, typed route parameters и fail-closed malformed request handling.
- Bootstrap/security failures не должны раскрывать внутренние stack/path details браузеру; security contracts закреплены CI.
- Сохранены и повторно пройдены security baseline, production healthcheck, installer/upgrade, crypto migration и fault-injection gates.

### Modular platform
- Добавлены строгие data-only module manifests и `ModuleRegistry` с path/symlink confinement, compatibility/dependency/capability validation и deterministic load order.
- Notes, Tasks, Files, Messenger, Profile и Administration зарегистрированы как формальные модули; legacy runtime остаётся временным migration boundary.
- Добавлен persisted `module_lifecycle` registry с configured/effective state, состояниями `discovered/installed/enabled/disabled/incompatible/degraded/quarantined/uninstalled`, dependency-aware transitions и non-destructive disable/uninstall semantics.
- Fresh install и compatibility upgrade включают canonical `module_lifecycle` schema; current fresh contract содержит 27 таблиц.
- Для Workerman lifecycle reconciliation выполняется post-fork, поэтому worker не наследует pre-fork PDO connection; HTTPS/WSS Chromium regression подтверждает reconnect и realtime delivery.

### Beta boundary / next target
- Beta.1 не объявляет завершёнными module-owned runtime isolation, signed updater/recovery или licensing: эти P0/P1 platform items остаются в `Unreleased` на пути к `1.0.0`.
- Полный план остаётся в `docs/BETA_HARDENING_0.14.md`; крупные новые пользовательские функции до stable не добавляются.

## 0.13.0-alpha — 2026-09-14

### Product UX foundation
- Content area приведён к единой спокойной 0.13 design system поверх сохранённого тёмного sidebar.
- Общие buttons/inputs/cards/panels/focus states получили единый visual layer без перехода приложения в dark theme.
- Ключевые модули уплотнены и перестали выглядеть как набор больших технических форм.

### Tasks
- Добавлен kanban board по статусам «Новые / В работе / Готово / Отменено».
- Добавлены компактные task cards, list/board switch и локальное сохранение выбранного режима.
- Drag-and-drop статусов и quick-create используют существующий ownership-checked backend contract.
- Сохранён bounded server-side search/filter/sort/pagination contract.

### Notes / Voice
- Notes переведён на writing-first editor shell с отдельными actions, writing canvas и materials sidebar.
- Attachments/share/voice оформлены как first-class части editor workflow.
- Голосовые заметки получили явный recorder flow: start/stop/cancel/save, timer, preview, private playback и persisted duration metadata.
- Existing private-storage, sharing, encrypted content и browser lifecycle guarantees сохранены.

### Profile / publication
- Собственный Profile превращён в workspace hub с быстрыми переходами к Notes, Tasks и Files.
- Добавлены owner-scoped counters Notes/Tasks/Files и storage usage/quota без тяжёлого dashboard query.
- Account settings открываются компактным edit/settings entry вместо дублирования форм.
- Добавлен authenticated read-only профиль другого пользователя.
- Notes, Tasks и Files получили explicit `is_profile_public`, default private; share-link сам по себе не публикует объект в профиле.
- Public Profile использует whitelist metadata и не раскрывает note content, task description, storage path, private download URL или share token.

### File Manager
- Добавлены локальный search/sort, grid/list workspace views и сохранение выбранного режима.
- Добавлен drag-and-drop upload через существующий hardened upload pipeline.
- Storage quota, durable DB/filesystem lifecycle и browser integrity contracts не ослаблены.

### Messenger
- Добавлен production runbook `docs/MESSENGER_SERVER.md` для Workerman/WSS, reverse proxy и process manager.
- UI получил понятные online/reconnecting/offline/session-ended состояния и ручной retry.
- Перед каждым reconnect запрашивается новый short-lived WebSocket ticket; учтены browser `online`, sleep/background recovery и bounded backoff.
- Production-like HTTPS/WSS Chromium smoke теперь доказывает forced disconnect → recovery state → fresh ticket → new WSS connection → realtime delivery.

### Installer / release safety
- Fresh-install schema явно включает public-profile fields Notes/Tasks/Files и `note_attachments.duration` для voice notes.
- Отдельный `0.13 installer schema contract` фиксирует current schema + Messenger installer config.
- `docs/PRODUCT_UX_0.13.md` закрывает alpha feature scope; оставшийся косметический/mobile polish перенесён в beta backlog.
- `0.13 alpha readiness` проверяет сохранение 0.12 durable baseline, наличие ключевых 0.13 артефактов и синхронизацию Version/README/CHANGELOG.

## 0.12.0-alpha — 2026-09-14

### Usable baseline / data integrity
- Durable DB writes переведены на fail-closed contract: неудачный commit больше не может молча превращаться в пользовательский success.
- File Manager lifecycle согласован между DB и private filesystem; добавлен reconciliation path для расхождений.
- Recursive folder soft-delete больше не оставляет активных потомков и не завышает storage usage.
- File uploads и изменение quota используют общий per-user advisory lock, включая upload-vs-admin-update race.
- Browser fault injection реально ломает metadata INSERT после физического upload и доказывает отсутствие false-success и orphan-файла.
- Notes delete согласован с attachment/share metadata без преждевременного physical cleanup, сохраняя retention contract.

### Product browser E2E
- Notes: create → edit → attachment → public share → anonymous attachment → unshare → delete.
- Tasks: create → edit → subtask → completion/status → sort → delete.
- File Manager: folder → upload → preview/download → rename → quota denial → delete.
- Profile: edit → avatar upload/download → avatar remove.
- Admin: dynamic field UI → block/reactivate user → per-user quota update.
- Все основные flows выполняются также при `BASE_PATH=/workspace/` и отвергают escaped root requests, неожиданные HTTP errors и PHP warnings/fatals.

### Usability / findability
- Добавлен общий accessible toast/inline feedback/confirmation/prompt layer.
- Notes получили local draft autosave, dirty-state warning и server-side поиск/пагинацию.
- Tasks получили server-side поиск/пагинацию и inline status/subtask actions без лишних full-page reload.
- Admin users получили bounded server-side `q/page/limit/sort` и URL-preserving state.
- File Manager получил поиск и сортировку текущей папки; существующий upload flow показывает имя файла, progress и ошибку.
- Пограничный URL с номером страницы за пределами результата нормализуется на существующую страницу вместо пустой пользовательской выдачи.

### BASE_PATH / runtime hardening
- Root-relative application URLs системно переведены на `route_path`, `base_url` и JS `wspace.path()`.
- Login/Profile/Notes/Tasks/File Manager/Messenger/Admin покрыты subdirectory browser baseline.
- Исправлены Smarty 5 runtime incompatibilities, обнаруженные реальным Chromium: CSS/template parsing и callback type `Smarty\\Template`.
- Исправлены download `Content-Disposition` sanitization warnings для Notes/File Manager.

### Database architecture / Admin / storage
- Fresh install официально закреплён за canonical `database/*_schema.sql`.
- `bin/migrate.php` определён как compatibility-upgrade runner для уже существующих установок, а `schema_migrations` — как internal filename/checksum ledger; это не замена canonical schema и не предположение, что проект исторически строился на migration framework.
- Добавлены canonical `system_settings` и `user_storage_quotas` и безопасный compatibility upgrade для partial legacy schema.
- `/admin/settings` позволяет задавать общий File Manager quota и optional per-user overrides.
- Used storage не кэшируется отдельным счётчиком: значение вычисляется из активных `user_files`.
- Production healthcheck, hosting installer, DB upgrade/restore и release gate работают с current 22-table contract.

### Release governance
- Добавлен machine-readable required-check policy и PR checklist.
- `Master release gate` проверяет governance drift и наличие реальных browser workflow job IDs.
- Политика branch protection и независимого approval документирована в `docs/RELEASE_GOVERNANCE.md`; включение repository-level enforcement остаётся one-time GitHub Settings операцией с admin permission.
- Добавлен отдельный `0.12 usable-alpha readiness` gate, который проверяет наличие всех release-scope артефактов, закрытый P1 roadmap и согласованность Version/README/CHANGELOG.

## 0.11.0-alpha — 2026-09-13

### Product UI / UX
- Добавлен единый product design layer для dashboard, Notes, Tasks, Profile, File Manager, Admin и общей оболочки Messenger.
- Sidebar переработан: desktop collapse, mobile drawer/overlay, current-route state и более крупные touch targets.
- Обновлены header/footer, login и registration screens.
- Удалены Google Fonts; интерфейс использует системный font stack.
- Добавлены focus-visible, skip-link/доступные labels, aria-live states и `prefers-reduced-motion`.
- File Manager больше не выполняет пользовательский code content: незавершённый Ace/code-run flow удалён, текст/code открывается только read-only preview.
- Исправлены runtime-баги динамических File Manager actions после создания папки.
- Admin panel получил полноценную таблицу аккаунтов со статусами, блокировкой/активацией и безопасной деактивацией.
- Custom profile fields в Admin синхронизированы с canonical `user_fields` schema и получили серверную валидацию имён/типов/длины.

### Production / Core hardening
- Исправлены case-sensitive bootstrap paths `core.php` и front-controller для Linux filesystem.
- Добавлен CLI `bin/healthcheck.php` для PHP/extensions/secrets/private storage/DB/schema checks.
- Добавлен file-backed request rate limiter с `flock` и private state под `PRIVATE_STORAGE_PATH/rate-limit`.
- Login/registration защищены `AuthRateLimit`, upload endpoints — `UploadRateLimit`.
- Multi-node deployment поддерживает явный shared `RATE_LIMIT_STORAGE_PATH`; healthcheck запрещает случайный local-only limiter при `DEPLOYMENT_NODE_COUNT>1`.
- Forwarded client IP headers доверяются только от адресов из `TRUSTED_PROXY_IPS`.
- Web-registration закрыта без явного `REGISTRATION_INVITE_CODE`.
- Registration validation синхронизирована с canonical user contract.
- CSP очищена от dev-domain/Google Fonts/external JS CDN; `unsafe-eval` удалён после отказа от browser code runner.
- Добавлены Permissions Policy и COOP; `unsafe-inline` пока остаётся как известный legacy Smarty CSP debt.
- Добавлена production/deployment документация `docs/PRODUCTION.md` и operations runbook `docs/OPERATIONS.md`.
- Admin physical user delete заменён на deactivation contract: строка пользователя и связанные Notes/Tasks/Messenger данные сохраняются.
- Admin lifecycle вынесен в `AdminUserService` с повторной проверкой active administrator role, запретом self/admin targets и защитой group owner до transfer ownership.
- Reactivation восстанавливает одновременно `role` и `is_active`.
- Web-installer переработан в hosting-first fresh-install wizard: PHP/extensions/vendor preflight, автоопределение домена и `BASE_PATH`, private storage вне document root, automatic schema import, secrets и первый superadmin без ручного SQL/`.env`.
- `.env` создаётся только после успешной финализации первого администратора, поэтому оборванный fresh install можно безопасно повторить.
- Canonical Notes schema очищена от mysql-client-only `DELIMITER`, чтобы одинаково импортироваться web-installer, mysql CLI и phpMyAdmin.
- Добавлен реальный HTTP/MySQL installer smoke для размещения в `public_html/workspace`, включая CSRF/cookies, 20-table contract, healthcheck и installer lock после установки.
- Добавлен upload-ready hosting bundle с production `vendor/`; tag `v*` публикует ZIP как GitHub Release asset, поэтому Composer не требуется на конечном shared hosting.
- Добавлен реальный Chromium HTTPS/WSS E2E через TLS Nginx + PHP + Workerman + MySQL: две пользовательские сессии, authenticated WSS и realtime Alice→Bob сообщение без reload.
- Добавлен CI restore drill: MySQL dump/checksum/restore и private-storage archive/checksum/restore.
- Документирована безопасная rotation `WS_TICKET_SECRET`; `UNIQUE_KEY`/`MSG_SECRET_KEY` запрещено заменять без отдельного re-encryption процесса.
- Добавлен `Master release gate`, который запускается после merge/push в `master` и проверяет уже объединённый commit: Composer audit, PHP/JS lint, canonical schemas, production healthcheck, version contract и upload-ready hosting bundle.

### Merged hardening after initial 0.10 baseline
- **PR #50:** Messenger forwarding и «Сохранённые сообщения», независимые forwarded media copies и минимизированные forwarding metadata.
- **PR #51:** Notes private attachments/share ACL, truthful attachment encryption flag и актуализированная документация.
- **PR #52:** canonical Tasks schema, ACL/validation contract, рабочие subtasks/categories/UI и Tasks integration CI.
- **PR #53:** private user avatars, canonical Profile contract и safe account deactivation вместо physical user delete.
- **PR #54:** DB compatibility upgrade runner, checksums, fresh-vs-upgrade installer contract и legacy DB integration test.
- **PR #55:** resumable fail-closed legacy Messenger/Notes ciphertext migration CLI.
- **PR #56:** product-wide UI/UX refresh, production headers/rate limits/healthcheck и canonical Admin lifecycle.
- **PR #57:** zero-CLI hosting installer, hosting-like HTTP smoke и upload-ready release bundle.
- **PR #58:** production-like browser HTTPS/WSS E2E и runtime bootstrap fixes найденные настоящим Chromium smoke.
- **PR #59:** operations hardening, shared limiter safeguards, trusted proxies, backup/restore drill и key-rotation runbook.

## 0.10.0-alpha — 2026-09-13

### Security baseline
- Закрыта возможность исполняемых публичных upload-файлов; File Manager переведён на private storage и авторизованную выдачу.
- Авторизация WebSocket переведена на короткоживущие HMAC tickets с server-bound identity.
- Добавлены origin allowlist и явный allowlist WebSocket actions.
- Исправлены destructive GET/CSRF-риски и middleware coverage для критических маршрутов.
- Пароли используют `password_hash`/Argon2id, сессия ротируется при входе.
- Активные crypto paths работают fail-closed.

### Crypto
- `CryptMethods` использует `UNIQUE_KEY` и libsodium XChaCha20-Poly1305.
- Messenger text/caption encryption использует версионированный XChaCha20-Poly1305 payload.
- Legacy AES-CBC чтение Messenger возможно только при явно заданном временном legacy key.
- Silent plaintext fallback для новых зашифрованных данных запрещён.

### Messenger v2
- Каноническая схема `users / dialogs / user_to_dialogs / messages / messenger_attachments / message_user_deletions`.
- Личные и групповые чаты, reply, edit, delete-for-me и sender-only delete-for-all.
- Явные read/delivered cursors и UI статусов отправки/доставки/прочтения.
- Multi-device WebSocket registry: события доставляются всем активным вкладкам/устройствам пользователя.
- Per-user состояния диалогов: pin, mute, archive.
- Private media attachments с MIME allowlist, ACL и Range streaming.
- Групповые роли owner/admin/member, добавление/удаление участников, transfer ownership, rename и private group avatar.
- Media reply-to и cleanup orphan uploads.
- Bounded encrypted search без plaintext message index.
- Голосовые сообщения: MediaRecorder upload, private storage, player, seek и скорости 1x/1.5x/2x.
- Realtime reactions с серверным allowlist, атомарным toggle, aggregate count и персональным `reacted_by_me`.

### CI
- Security baseline workflow: Composer validate/install/audit, PHP lint, JS syntax, clean schema smoke и crypto fail-closed tests.
- Отдельные integration workflows для Messenger groups, media lifecycle, encrypted search, voice recording и reactions.
- Реальные HTTP multipart smoke tests применяются для критичных upload-путей Messenger.

## 0.9.0-alpha — 2026-05-14

- Стабилизация ORM (`where`, `GROUP BY`, aliases).
- Переход части контрактов с `user_uid` на `user_id`.
- Исправления создания/редактирования Notes и обновление crypto helpers того периода.
- Исправления Router, логирования, sidebar/profile UI и документации.

## 0.8.0-alpha — 2026-05-07

- Добавлены Tasks/ежедневник и File Manager.
- Добавлены мультимедиа-просмотр и CodeExplorer.
- Расширены регистрация по invite, профиль и admin panel.
- Добавлены middleware/routing и ранняя WebSocket-интеграция Messenger.

## 0.7.0-alpha

- Первый функциональный Messenger и Notes sharing.
- Realtime сообщения, typing indicator и ранняя поддержка media/voice.

## 0.6.0-alpha

- Расширенный профиль пользователя.
- Смена пароля и удаление аккаунта.
- Admin panel, блокировка пользователей и custom profile fields.

## 0.5.0-alpha

- ORM/database layer: SELECT/JOIN/WHERE/GET/FIRST и логирование SQL.

## 0.4.0-alpha

- Middleware, Request, redirect/session helpers и переработка Router/Core.

## 0.3.0-alpha

- Smarty, MVC, Router и базовое подключение к БД.

## 0.2.0-alpha

- Регистрация, авторизация, сессии и базовый профиль.

## 0.1.0-alpha

- Начальная структура приложения и конфигурация окружения.