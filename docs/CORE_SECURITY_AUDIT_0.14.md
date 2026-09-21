# Workspace Organizer 0.14 — журнал аудита Core/Security

Статус: **активный аудит**. Этот файл является журналом release evidence для цикла beta hardening 0.14. Это намеренно не одноразовый текстовый обзор: каждая security-sensitive поверхность должна завершиться явным состоянием reviewed/fixed/regression-covered до объявления beta.

## Правило аудита

Строка считается завершённой только когда соответствующий код проверен относительно своей threat model, а все Critical/High findings исправлены и имеют regression coverage. Статуса `reviewed` без evidence недостаточно для beta.

Значения status:

- `not-reviewed` — evidence аудита 0.14 ещё нет;
- `reviewing` — аудит идёт;
- `finding` — есть одно или несколько нерешённых findings;
- `fixed` — известный finding исправлен, regression test ещё отсутствует или неполон;
- `covered` — поверхность проверена и, где это практически возможно, защищена повторяемым contract/test.

## Матрица поверхностей

| Поверхность | Статус | Требуемые evidence |
|---|---|---|
| Bootstrap/autoload/startup errors | finding | явная load boundary, отсутствие disclosure secrets/paths, fail-closed startup tests |
| Module discovery/manifest/registry | reviewing | schema validation, path confinement, tests dependencies/cycle/core-version |
| Web-server/source/package exposure | fixed | production deny boundary, повторённая E2E router и regression coverage |
| Router/path parsing/redirects | not-reviewed | fuzz/negative cases route parser, safe redirects, method handling |
| Request parsing/input boundaries | not-reviewed | malformed JSON, oversized/nested input, contract raw-vs-escaped |
| Session/cookie lifecycle | not-reviewed | fixation, cookie flags, logout invalidation, concurrent session behavior |
| Authentication/registration | not-reviewed | brute force/rate limit, credential errors, invite lifecycle, password contract |
| Authorization/ACL/admin | not-reviewed | object-level access matrix, blocked/inactive revocation, privilege transitions |
| CSRF/origin/browser security | not-reviewed | unsafe methods, token rotation, SameSite/origin policy, CSP target |
| DatabaseManager/ORM | not-reviewed | injection boundaries, identifier allowlists, transaction/failure semantics |
| Migrations/installer/upgrade | not-reviewed | malicious/partial package behavior, checksum, interruption/recovery |
| Private storage/path handling | not-reviewed | traversal/symlink/race/MIME/ACL tests |
| File uploads/downloads | not-reviewed | parser abuse, size limits, executable content, quota/race behavior |
| Notes crypto | not-reviewed | key/AAD/error behavior, corruption/truncation, key rotation design |
| Messenger crypto/WSS | not-reviewed | ticket/origin/ACL/reconnect/replay/abuse boundaries |
| Secrets/config/proxy trust | not-reviewed | missing/weak secrets, forwarded headers, deployment fail-closed behavior |
| Dependencies/supply chain | not-reviewed | Composer audit и дизайн package provenance/update signature |
| Update manager | not-reviewed | signed metadata/package, downgrade protection, atomicity/recovery |
| License/entitlement manager | not-reviewed | signed entitlement, offline/grace behavior, tamper/failure behavior |
| Logging/observability | not-reviewed | отсутствие утечки secrets, correlation IDs, audit events, alertable failures |
| Backup/restore/data retention | not-reviewed | restore drill, retention при module disable/uninstall, purge semantics |
| Multi-node/concurrency | not-reviewed | locks/shared state/duplicate workers/idempotency |
| Browser/client JS boundaries | not-reviewed | DOM XSS, unsafe HTML, URL handling, CSP-compatible assets |

## Findings

### A14-001 — детали bootstrap exception попадали HTTP-клиенту

**Severity:** Medium  
**State:** исправлено в Phase 1; regression coverage нужно добавить в startup/security contract.

`index.php` логировал bootstrap exception, а затем передавал то же exception message в публичную HTML error page. Startup exceptions могут содержать filesystem/configuration details и возникают именно тогда, когда обычная application error handling ещё недоступна.

Phase 1 заменяет публичный ответ на непрозрачный incident identifier, сохраняя exception class/message только в server log. Ни stack trace, ни raw bootstrap exception браузеру не отображаются.

### A14-002 — неявная рекурсивная загрузка `app/*` создаёт слишком широкую execution boundary

**Severity:** Medium architectural/security risk  
**State:** finding / migration started.

`core.php` рекурсивно подключает PHP из каталогов models, services, controllers, socket handlers и middleware. Механизм появился до цели модульной поставки и делает package composition неявной: одного присутствия кода на диске фактически достаточно, чтобы он вошёл в runtime bootstrap.

Это не рассматривается как самостоятельная remote-code-execution vulnerability: атакующий, способный записать произвольный PHP в application tree, уже обладает сильной примитивой. Однако такое поведение несовместимо с signed module packages, deterministic composition, quarantine и license/update enforcement.

Phase 1 добавляет validated module manifests и fail-closed registry до legacy application loading. Последующие фазы 0.14 должны убрать рекурсивную загрузку product modules в пользу явных isolated module bootstrap/routes.

### A14-003 — у текущих product modules нет криптографически аутентифицированной package identity

**Severity:** High для будущей threat model remote update; сейчас это ещё не exposed remote-update vulnerability, потому что update channel не реализован.  
**State:** finding / planned.

Manifest integrity hash из Phase 1 обнаруживает identity содержимого manifest внутри running installation, но **не является package signature**. До включения любого remote core/module update в 0.14 нужно проверять signed release metadata и package contents через public verification key, встроенный в core или явно доверенный ему. Private signing keys никогда не должны поставляться вместе с приложением.

### A14-004 — central router и legacy runtime связывают доступность module с наличием исходников

**Severity:** Architectural  
**State:** finding / planned.

`core/routerConfig.php` централизованно импортирует и регистрирует каждый product controller. Поэтому disabled/missing module пока не может исчезнуть как цельная capability. Module registry из Phase 1 — только metadata/control-plane foundation; ownership routes будет перенесён в module-owned route providers отдельным PR.

### A14-005 — каталоги module/source не были последовательно смоделированы как непубличный контент

**Severity:** Medium  
**State:** исправлено в Phase 1; browser/static regression coverage обеспечивают существующие E2E suites через hardened router.

Production baseline Apache уже запрещал прямой доступ к source/config directories, но новый каталог `modules/` ещё не входил в deny list. Кроме того, `tests/e2e/router.php` напрямую отдавал любой существующий repository file, поэтому browser CI не воспроизводил production source boundary.

Phase 1 добавляет `modules` в private-source deny rule Apache и заставляет PHP E2E router запрещать те же source/package segments и sensitive root metadata до static-file fast path. Это не позволяет считать module manifests/будущий module code браузерными assets и заставляет browser CI проверять целевую boundary.

## Evidence Phase 1

Phase 1 добавляет:

- `Core\ModuleManifest` со строгой проверкой JSON schema/type/identifier/core-version;
- `Core\ModuleRegistry` с root confinement, отказом от symlink на module boundary, уникальным ownership capabilities, проверкой наличия dependencies и отказом от cycles;
- deterministic dependency-aware composition resolution;
- явные manifests для текущих product modules Notes, Tasks, Files, Messenger, Profile и Administration;
- явный `runtime.mode = legacy`, чтобы репозиторий не выдавал текущие modules за isolated до фактической изоляции;
- отдельный CI `module-platform-contract` с реальными positive/negative registry fixtures;
- bootstrap validation всех module manifests до загрузки legacy application code;
- удаление raw bootstrap exception details из HTTP responses;
- запрет Apache и browser-E2E на прямой доступ к `modules/`/source-package.

## Обязательная следующая последовательность аудита/реализации

1. Усилить boundaries Router/Request/session и добавить negative/fuzz-style contracts.
2. Добавить persisted module installation/lifecycle state (`installed/enabled/disabled/incompatible/degraded/quarantined`) и reconciliation с signed manifests.
3. Перенести route registration за module providers; мигрировать один low-coupling module end-to-end как reference isolated module.
4. Разделить module migrations/assets/storage/healthchecks по owned namespaces и enforce cross-module boundaries.
5. Реализовать signed package/update metadata и state machine update transaction/recovery.
6. Реализовать централизованную signed entitlement verification после стабилизации module/update trust boundary.
7. Завершить оставшуюся audit matrix и закрыть каждый Critical/High finding до `0.14.0-beta.1`.

## Beta-blocking rule

`0.14.0-beta.1` нельзя помечать tag, пока хотя бы одна audit row остаётся `not-reviewed` или хотя бы один Critical/High finding остаётся нерешённым. Medium findings требуют либо fix, либо явного документированного принятия риска с regression containment и ответственным владельцем stable target.
