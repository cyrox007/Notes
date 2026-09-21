# Workspace Organizer 0.14 — контракт модульной платформы

Практическая инструкция разработчика/оператора по добавлению нового модуля находится в [`MODULE_DEVELOPMENT.md`](MODULE_DEVELOPMENT.md).

Этот документ определяет целевой контракт для независимо поставляемых продуктовых модулей. Фаза 1 добавила manifest/registry control plane. Фаза 5 добавляет persisted lifecycle state, при этом текущий продуктовый код явно помечен как `runtime.mode = legacy`.

## Ядро и модуль

Ядру принадлежат только платформенные примитивы: bootstrap, request/router primitives, authentication/session/security primitives, координация database transaction/migration, module registry, проверка обновлений, entitlement verification, health/observability и общие UI shell contracts.

Продуктовые возможности Notes, Tasks, File Manager, Messenger, Profile и Administration являются модулями. Модуль не должен становиться частью trusted core только потому, что его PHP-файлы присутствуют на диске.

## Расположение manifest

У каждого установленного модуля ровно один manifest:

```text
modules/<module-id>/module.json
```

Имя каталога и `id` в manifest должны совпадать. Module IDs и capability IDs имеют ограниченный формат идентификаторов. Manifest проверяется до загрузки продуктового кода приложения.

Текущая версия schema: `1`.

Обязательные поля:

- `id`, `name`, `version`;
- `core.min`, `core.max_exclusive`;
- `dependencies`;
- глобально уникальные `capabilities`;
- `package.bundled`, `package.default_enabled`;
- `license.feature` — центральный entitlement key, а не локальная реализация лицензии;
- `runtime.mode` — `legacy` или `isolated`;
- `storage_namespaces`.

## Правила fail-closed

Discovery/startup завершается ошибкой, если:

- отсутствует/повреждён корень modules или manifest;
- каталог модуля является symlink на границе discovery;
- schema/types/identifiers manifest некорректны;
- ID каталога и manifest различаются;
- объявленная dependency отсутствует;
- dependencies образуют цикл;
- два модуля заявляют одну capability;
- persisted module, не находящийся явно в состоянии `uninstalled`, исчез с диска;
- отсутствует lifecycle table или содержит некорректное state.

**Валидный** manifest, диапазон core которого не включает запущенную версию ядра, отличается от повреждённого пакета. Он остаётся зарегистрированным, сохраняет configured lifecycle intent и при reconciliation получает effective state `incompatible`. Пока он несовместим, runtime никогда его не включает. Это позволяет последующему совместимому обновлению core вернуть ранее настроенное состояние, не забывая намерение оператора.

## Composition

`ModuleRegistry::resolveComposition()` работает только с manifests: принимает запрошенный набор packages, добавляет обязательные dependencies и возвращает детерминированный порядок dependency-first. Поэтому package/distribution planning не меняется из-за того, что в одной конкретной установке модуль отключён.

`ModuleRegistry::enabledComposition()` учитывает runtime state: возвращает только модули, persisted **effective** state которых равно `enabled`. Disabled, incompatible, degraded, quarantined и uninstalled modules никогда не включаются неявно ради удовлетворения dependency.

Целевые поддерживаемые формы пакета:

- core + Notes;
- core + Tasks;
- core + Files;
- core + Messenger;
- curated bundles;
- полный Workspace;
- licensed/custom enterprise composition.

Package builder обязан использовать тот же manifest resolver, что installer/update preflight, чтобы distribution не могла создать composition с отсутствующими или циклическими dependencies.

## Persisted lifecycle

Lifecycle state хранится в `module_lifecycle`. Платформа разделяет два понятия:

- `configured_state` — сохранённое намерение оператора/package;
- `effective_state` — то, что запущенный core фактически может предоставить после reconciliation совместимости и dependencies.

Configured states:

- `discovered`;
- `installed`;
- `enabled`;
- `disabled`;
- `degraded`;
- `quarantined`;
- `uninstalled`.

Effective state дополнительно включает `incompatible`.

Bundled modules при первой reconciliation регистрируются с учётом `package.default_enabled`. Новый обнаруженный **non-bundled** package всегда регистрируется как `discovered`; `default_enabled` не может сам активировать сторонний код.

Если configured `enabled` module теряет enabled dependency, его effective state становится `degraded`, но configured intent остаётся `enabled`. После восстановления compatibility/dependencies reconciliation может вернуть его в `enabled` без создания нового намерения оператора.

Lifecycle transitions ограничены. Включение требует, чтобы все dependencies были effectively enabled и совместимы с core. Отключение, quarantine или uninstall модуля отклоняется, если от него зависит другой effectively enabled module. Выход из quarantine требует явного перехода в `disabled` перед повторным включением.

Изменения состояния disable/uninstall неразрушительны: пользовательские данные не очищаются. Physical package removal, data retention/purge и signed update recovery остаются отдельными явными операциями.

`manifest_hash` хранит наблюдаемую локальную identity manifest. Reconciliation может обновить его, если содержимое deployed package изменилось. Это **не** publisher signature и не authorization на выполнение скачанного кода.

## Текущая runtime-граница

Фаза 5 сохраняет и согласует lifecycle state до рекурсивной загрузки `app/*`. Registry уже предоставляет effective runtime composition, но текущие modules всё ещё используют `runtime.mode = legacy`; их PHP files/routes пока физически не изолированы lifecycle state.

Поэтому состояние `disabled`/`quarantined` на этой фазе — контракт control plane, а не заявление, что весь legacy code уже перестал загружаться. Следующая фаза переносит module-owned route/bootstrap providers за `enabledComposition()` и доказывает isolation на reference module.

## Цель изоляции

`isolated` module владеет своими:

- route provider;
- controllers/services/domain models;
- migrations и schema ownership metadata;
- assets/templates;
- storage namespace;
- permissions/capabilities;
- healthcheck;
- update metadata;
- lifecycle hooks.

Cross-module access должен проходить через объявленный contract/capability/service. Прямые записи в tables/storage другого модуля не являются поддерживаемой integration boundary.

## Целостность пакетов и подписи

SHA-256 hash, предоставляемый manifest/lifecycle registry, отражает только identity локального содержимого manifest. Это **не** cryptographic publisher signature.

До включения удалённой установки/обновления платформа обязана проверять signed release metadata и содержимое package с помощью trusted public verification keys. Package code не должен выполняться до проверки signature/integrity/compatibility. Private signing keys никогда не распространяются вместе с клиентскими installations.

## Граница лицензирования

`license.feature` — только entitlement identifier. Modules не реализуют независимые license checks. Будущий `LicenseManager/EntitlementService` оценивает подписанное package entitlement и выдаёт центральное решение по capability.

Ошибка лицензии может отключить коммерческую capability согласно policy, но не должна уничтожать пользовательские данные, мешать backup/recovery или переводить security updates в небезопасное состояние.

## Правило миграции

Существующие modules остаются `legacy`, пока вся их runtime boundary не перенесена за module contract. Изменение manifest на `isolated` без ownership routes/bootstrap/storage/migrations и regression coverage запрещено.
