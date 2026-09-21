# Workspace Organizer 0.14 — усиление безопасности / модульная платформа

> Канал релиза: **beta**. `0.14.0-beta.1` фиксирует первый hardening baseline; незакрытые P0/P1 пункты этого документа продолжаются в `master` как blockers на пути к `1.0.0` stable.

## Цель релиза

0.14 — первый beta-hardening релиз после product-complete `0.13.0-alpha`.

Главная цель — не расширять продукт новыми пользовательскими функциями, а превратить Workspace Organizer в защищённую, диагностируемую и модульную платформу, которую можно безопасно поставлять, обновлять и лицензировать в разных комбинациях компонентов.

Ключевой принцип 0.14: **ядро должно быть минимальным, fail-closed и стабильным; каждый функциональный модуль — явно зарегистрированным, изолированным и управляемым через формальный lifecycle contract.**

Абсолютной «невзламываемости» не существует. Цель проекта — максимально уменьшить attack surface, исключить известные классы ошибок, сделать критические операции fail-closed, обеспечить defense-in-depth и превратить security invariants в автоматически проверяемые контракты.

---

## P0 — Полный аудит ядра и security hardening

0.14 включает повторный аудит ядра уже не на уровне отдельных найденных уязвимостей, а как системный threat-model review.

### Поверхность атаки

Обязательный аудит:

- bootstrap/autoload и границы загрузки кода;
- Router и route registration;
- Request parsing, headers, cookies, sessions;
- authentication, authorization, account lifecycle;
- CSRF, XSS, CSP, clickjacking, open redirect;
- SQL injection и unsafe dynamic identifiers;
- upload/download paths, MIME, filenames, archive/path traversal;
- private storage и filesystem permissions;
- SSRF и любые server-side outbound requests;
- command/process execution;
- template injection и unsafe Smarty contexts;
- unserialize/deserialization и dynamic class loading;
- WebSocket authentication, Origin, message actions, reconnect/replay;
- rate limiting / brute-force / resource exhaustion;
- crypto/key management и legacy decrypt paths;
- secrets, `.env`, logs и error pages;
- installer, migrations, compatibility upgrade;
- update mechanism и software supply chain;
- module installation/loading;
- licensing/entitlement paths;
- admin/superadmin privilege boundaries;
- failure behavior при DB/storage/network corruption;
- backup/restore и disaster recovery.

### Требования к ядру

- fail-closed для security-sensitive операций;
- отсутствие silent fallback из защищённого режима в небезопасный;
- deny-by-default для маршрутов, permissions, module capabilities и socket actions;
- централизованная обработка ошибок без утечки secrets/SQL/path/stack trace пользователю;
- структурированные security/audit events без sensitive payload;
- строгие input/domain contracts;
- idempotency там, где повтор запроса может повредить состояние;
- bounded operations для поиска, batch jobs, migrations и фоновых задач;
- transaction/durable-write contract для пользовательского success;
- автоматические negative tests, fault injection и malformed-input tests;
- dependency audit и supply-chain verification в release gate.

### Результат аудита

Должен появиться отдельный документ `CORE_SECURITY_AUDIT_0.14.md` с:

1. картой trust boundaries;
2. threat model;
3. списком attack surfaces;
4. найденными рисками с severity;
5. исправлениями;
6. остаточными рисками;
7. security invariants;
8. regression contracts, которые не позволяют вернуть исправленную уязвимость.

---

## P0 — Формальная модульная архитектура

Текущий рекурсивный bootstrap `app/models`, `services`, `controllers`, `socket`, `handlers`, `middlewares` должен быть заменён/ограничен формальной системой модулей.

### Минимальное ядро

В core остаются только платформенные механизмы:

- bootstrap/config;
- Router;
- Request/Response;
- session/authentication primitives;
- CSRF/security primitives;
- DB connection/transaction primitives;
- module loader/registry;
- migration coordinator;
- event/contracts layer;
- logging/audit/healthcheck primitives;
- update manager;
- license/entitlement verifier;
- installer/package manager integration.

Функциональные подсистемы не должны попадать в core только потому, что они исторически существовали там.

### Manifest модуля

Каждый модуль получает manifest — например `module.json` или эквивалентный immutable PHP descriptor.

Минимальный contract manifest:

```text
id
name
version
vendor
core_compatibility
required_modules
optional_modules
provided_capabilities
required_capabilities
routes
socket_handlers
migrations
permissions
settings_schema
storage_namespace
assets
healthchecks
license_feature
package_signature/checksum metadata
```

Manifest валидируется до загрузки исполняемого кода модуля.

### Реестр модулей

Система должна фиксировать каждый модуль и его состояние.

Нужен canonical module registry с как минимум:

- module id;
- установленная версия;
- enabled/disabled state;
- compatibility state;
- integrity/signature state;
- install/update timestamp;
- migration state;
- dependency state;
- license/entitlement state;
- last healthcheck/error state.

Целевые lifecycle states:

```text
discovered
installed
enabled
disabled
incompatible
degraded
quarantined
uninstalled
```

Неизвестный, повреждённый, неподписанный или несовместимый модуль не должен автоматически исполняться.

### Изоляция модулей

Обязательные правила:

- модуль не регистрирует routes/socket handlers глобальным побочным эффектом при `include`;
- модуль не читает/пишет таблицы другого модуля напрямую без явно разрешённого platform contract;
- межмодульное взаимодействие идёт через interfaces/capabilities/events/services;
- модуль имеет собственный namespace;
- модуль имеет собственные migrations и migration ledger;
- модуль имеет собственный storage namespace;
- module assets загружаются только если модуль включён;
- module permissions объявлены явно;
- module config валидируется по schema;
- отключённый модуль не оставляет активные routes/jobs/socket actions;
- невозможность загрузить один optional module не должна ломать boot всей системы;
- критический core dependency failure должен останавливать boot fail-closed;
- module error должен быть локализован и отражён как `degraded/quarantined`, если безопасная изоляция возможна.

### Набор базовых модулей

В рамках миграции 0.14 необходимо формально классифицировать существующие части системы, например:

- Notes;
- Tasks;
- File Manager;
- Messenger;
- Profile/Public Profile;
- Admin/Administration;
- дополнительные будущие integrations.

Точная граница Auth/Profile/Admin и минимального core определяется после dependency audit, а не заранее по текущей структуре каталогов.

---

## P0 — Поставка системы в разных комбинациях модулей

Installer/package builder должен уметь формировать и проверять разные product compositions.

### Профили пакета

Нужен machine-readable package profile, например:

```text
core
core + notes
core + notes + tasks
core + files
core + messenger
full workspace
custom enterprise bundle
```

Это не должен быть набор ручных `if` в installer.

Package resolver обязан:

- разрешать dependencies;
- запрещать несовместимые комбинации;
- автоматически включать required modules;
- явно показывать optional modules;
- проверять core compatibility;
- проверять license entitlements;
- строить deterministic installation plan;
- выполнять preflight до изменения DB/filesystem;
- сохранять установленную composition в system registry.

### Отключение / удаление

`disable` и `uninstall` — разные операции.

- Disable не уничтожает пользовательские данные.
- Uninstall по умолчанию также не должен автоматически удалять данные.
- Destructive data removal — отдельное явное действие с backup/preflight/confirmation.
- Нельзя удалить модуль, от которого зависят активные модули.
- После disable/uninstall маршруты, меню, jobs, socket actions и permissions модуля должны исчезнуть из runtime registration.

---

## P0 — Система обновления ядра и модулей

0.14 должен заложить production-grade update subsystem вместо ручной замены файлов.

### Метаданные обновления

Update source предоставляет signed metadata:

- package id;
- type (`core` / `module`);
- version;
- supported current versions;
- required core version;
- dependency constraints;
- checksum;
- digital signature;
- migration requirements;
- release channel;
- security severity;
- package URL/reference.

### Безопасность цепочки поставки

- update metadata проверяется цифровой подписью;
- package checksum проверяется до распаковки;
- подпись проверяется до исполнения любого кода из пакета;
- private signing key никогда не поставляется внутри приложения;
- installation содержит только public verification key/key set;
- key rotation должна быть предусмотрена заранее;
- unsigned/invalid package отклоняется fail-closed;
- downgrade запрещён по умолчанию;
- rollback не может установить неподписанный artifact.

### Lifecycle обновления

Обязательная последовательность:

1. fetch metadata;
2. verify signature;
3. dependency/compatibility resolve;
4. disk/permissions/runtime preflight;
5. backup/snapshot plan;
6. maintenance/update lock;
7. stage files вне active runtime;
8. verify staged package;
9. apply migrations по versioned contract;
10. atomic/controlled switch;
11. post-update healthcheck;
12. commit update state;
13. rollback/recovery при failure.

Для DB rollback предпочтение отдаётся backup/snapshot/recovery plan, а не предположению, что любой `DOWN migration` безопасно обратим.

### Независимость core/module

- core update не должен молча ломать enabled modules;
- несовместимый module блокирует update до явного решения либо получает совместимую версию в том же transaction plan;
- module update не должен изменять core files;
- module updater не должен писать в чужой module namespace;
- update state и installed checksums фиксируются в registry.

### Обновления безопасности

Критические security updates ядра должны иметь отдельный приоритет и не должны искусственно блокироваться коммерческой лицензией, если это создаёт риск для уже установленной системы.

---

## P1 — Система лицензирования пакета и модулей

Лицензирование должно быть отдельным entitlement layer, а не разрозненными `if ($license)` внутри модулей.

### Модель лицензии

Поддержать:

- license id;
- installation/customer id;
- edition/package;
- разрешённые modules/features;
- expiration/maintenance window при необходимости;
- update entitlement;
- optional limits;
- offline signed license document;
- online activation/refresh как дополнительный режим, а не единственный способ boot.

### Криптографическая модель

- license подписывает только license authority;
- приложение содержит public key для проверки;
- private signing key не находится в customer package;
- signed entitlement нельзя подменить изменением DB;
- canonical license payload имеет versioned format;
- поддерживается key rotation;
- system clock manipulation и expiry behavior должны иметь явно описанный contract.

### Поведение runtime

License failure не должен повреждать данные.

При отсутствии entitlement модуль может перейти в controlled state (`disabled` / restricted read-only, если это допустимо для данного продукта), но:

- данные не удаляются;
- admin получает понятный diagnostic state;
- backup/export/recovery paths не блокируются без необходимости;
- security-critical core fixes не должны превращаться в заложника billing state;
- license server outage не должен немедленно превращать локальную установку в неработоспособную, если используется валидный ранее выданный signed entitlement.

### Контракт module + license

Manifest может объявлять `license_feature`, но решение принимает единый `LicenseManager/EntitlementService`.

Модуль не должен самостоятельно:

- хранить свой секретный license key;
- обращаться к внешнему license API на каждом request;
- реализовывать собственный криптографический формат;
- изменять данные пользователя при истечении лицензии.

---

## P1 — Наблюдаемость и журнал платформы

Запланированный beta-hardening 0.14 сохраняется и расширяется требованиями модульной архитектуры.

Нужны:

- structured application logs;
- security audit log;
- module lifecycle log;
- update history;
- license state transitions без sensitive payload;
- healthcheck каждого enabled module;
- aggregate platform health;
- metrics/alerts для auth errors, DB failures, queue/background failures, storage pressure, WebSocket state, update failures;
- correlation/request id для диагностики.

---

## P1 — матрица upgrade / compatibility

0.14 должен проверяться не только на fresh install.

Матрица минимум:

- `0.13.0-alpha -> 0.14 beta`;
- root install;
- `BASE_PATH=/workspace/`;
- full package;
- несколько минимальных module compositions;
- module enable/disable;
- module update;
- core update с compatible modules;
- отказ core update при incompatible module;
- interrupted update + recovery;
- valid/expired/invalid/offline license states.

---

## P1 — кроссбраузерные / мобильные / soak / load проверки

Сохраняем ранее запланированный beta scope:

- Chromium + Firefox baseline для критических flows;
- mobile/tablet responsive regression;
- Messenger reconnect/long-session soak;
- concurrent upload/quota races;
- DB/storage fault injection;
- bounded load baseline для Notes/Tasks/File Manager/Messenger;
- long-running worker restart/recovery;
- installer/update interruption tests.

---

## P1 — контракт retention данных / ownership модулей

Необходимо определить:

- какой модуль владеет каждой таблицей/column group/storage namespace;
- какие данные остаются после disable/uninstall;
- retention/cleanup policy;
- cross-module references;
- orphan cleanup;
- export/import ownership;
- backup/restore composition awareness.

---

## P1 — управление релизом

0.14 также должен закрыть repository-side enforcement:

- required checks реально включены для `master`;
- PR required;
- branch up-to-date required;
- stale approvals dismissed;
- force push/deletion blocked;
- independent approval, когда доступен независимый reviewer;
- отдельный 0.14 beta readiness gate.

---

## Архитектурные критерии готовности 0.14

0.14 нельзя считать beta-ready, пока не выполнены все пункты ниже:

- [ ] завершён и задокументирован полный core/security audit;
- [ ] для P0 findings нет открытых Critical/High без явного release exception;
- [ ] bootstrap больше не исполняет неизвестные функциональные компоненты только потому, что файл оказался в рекурсивно загружаемом каталоге;
- [ ] каждый функциональный модуль имеет manifest и запись в module registry;
- [ ] module dependencies и core compatibility проверяются автоматически;
- [ ] отключённый модуль полностью исчезает из runtime routes/assets/jobs/socket registration;
- [ ] повреждённый/несовместимый/невалидный модуль не может молча исполняться;
- [ ] минимум три разные module composition проходят fresh-install + healthcheck + browser smoke;
- [ ] update subsystem проверяет подпись/checksum до исполнения package code;
- [ ] core/module update имеет preflight, backup/recovery и post-update healthcheck;
- [ ] прерывание update проверено fault-injection тестом;
- [ ] license entitlement проверяется единым сервисом и криптографически подписанным payload;
- [ ] invalid/expired/offline license states имеют безопасное и документированное поведение;
- [ ] licensing не удаляет пользовательские данные и не ломает recovery paths;
- [ ] есть module/update/license audit trail;
- [ ] upgrade matrix из 0.13 и module lifecycle покрыта CI;
- [ ] cross-browser/mobile critical-flow regression существует;
- [ ] observability/metrics/alerts contract определён и проверяется;
- [ ] `master` required-check enforcement реально включён;
- [ ] отдельный `0.14 beta readiness` gate зелёный на release candidate.

---

## Рекомендуемый порядок реализации

1. Core/security audit + dependency/trust-boundary map.
2. Module manifest + registry + loader без миграции функциональных модулей.
3. Перевод одного простого модуля на новый lifecycle как reference implementation.
4. Dependency/capability/event contracts.
5. Последовательная миграция Notes / Tasks / File Manager / Profile / Messenger / Admin на module platform.
6. Package composition resolver + installer integration.
7. Signed update metadata/package verification.
8. Transactional update/recovery pipeline.
9. License/entitlement service.
10. Observability + module/update/license audit trail.
11. Upgrade/composition/browser/fault-injection matrix.
12. 0.14 beta readiness + release candidate.

## Дисциплина scope

0.14 не должен превращаться в очередной feature release. Пользовательские функции допускаются только если они необходимы для:

- security;
- module lifecycle;
- update/recovery;
- licensing/administration;
- observability;
- compatibility/regression.

Остальные product features откладываются до следующего цикла после стабилизации платформенного ядра.
