# Workspace Organizer 1.0 — runtime-изоляция модулей

Самих `module.json`/lifecycle недостаточно для runtime isolation. Этот документ отслеживает физическую миграцию, необходимую до того, как release gate 1.0 сможет заявлять о модульности.

## Runtime-граница

Изолированный модуль объявляет:

```json
"runtime": {
  "mode": "isolated",
  "entrypoint": "runtime.php"
}
```

Entrypoint:

- должен быть относительным PHP path внутри `modules/<id>/`;
- не может использовать traversal или выходить за module root;
- загружается только когда module входит в выбранную runtime composition;
- обязан возвращать `Core\ModuleRuntimeProvider`;
- обязан сообщать тот же module ID, что и manifest;
- запускается в порядке dependency-first;
- владеет регистрацией собственных routes;
- экспортирует concrete service objects ровно для capabilities, объявленных в `module.json`.

Модуль нельзя переключать из `legacy` в `isolated`, пока его product runtime files и routes действительно не перенесены за этот entrypoint.

## Межмодульные capabilities

Изолированные modules не импортируют internal PHP files другого модуля и не ищут его classes по path. Cross-module services обнаруживаются через `Core\ModuleCapabilityRegistry`.

Правила:

- capability identifiers объявляются в manifest модуля-провайдера;
- runtime provider обязан экспортировать ровно тот же набор capabilities;
- предоставлять capabilities могут только modules в effective runtime composition;
- у одной активной capability ровно один активный provider; duplicate registration завершается fail-closed вместо зависимости от boot order;
- после boot модулей registry запечатывается и не может меняться во время request dispatch;
- consumers запрашивают capability service через `ModuleRuntimeLoader::getInstance()->capabilities()`;
- consumers могут требовать ожидаемый interface/class; type mismatch завершается fail-closed;
- registry предоставляет provider ownership для diagnostics без раскрытия filesystem paths провайдера.

Пример будущей интеграции:

```php
$player = ModuleRuntimeLoader::getInstance()
    ->capabilities()
    ->require('media.playback', MediaPlayback::class);
```

Таким образом Files/Notes/Messenger могут использовать media playback без зависимости от внутренней структуры controller/service/model модуля реализации.

Сам **registry является инфраструктурой платформы 1.0**, потому что устраняет прямой cross-module dependency pattern во время изоляции модулей. Реальный provider `media.playback`, реализация codec/streaming и UI player остаются post-1.0 продуктовой работой и находятся под feature freeze.

## Переходный loader

`core.php` всё ещё содержит рекурсивный loader `app/*`, пока существуют bundled legacy modules. Это временная compatibility infrastructure, а не допустимая финальная boundary.

При миграции каждого модуля его controllers/services/models/socket handlers удаляются из общего `app/*`, views/assets/runtime переносятся в `modules/<id>/`, route block удаляется из `core/routerConfig.php`, после чего manifest меняется на `isolated`.

Когда изолирован последний bundled module, рекурсивный product loader удаляется. Общие authentication/session/security/platform primitives, остающиеся core-owned, загружаются явно как core/shared infrastructure.

## Порядок миграции

1. Notes — reference implementation.
2. Tasks.
3. Files.
4. Profile.
5. Admin.
6. Messenger — последним, потому что его HTTP module boundary должна сойтись с native WebSocket runtime и protocol/origin hardening.

Текущее состояние миграции `1.0` после этапа Admin:

- isolated: `notes`, `tasks`, `files`, `profile`, `admin`;
- оставшийся legacy bundled module: `messenger`.

Profile сохраняет только узкие compatibility view bridges в `app/views/profile_page/*.php`, пока существующие controllers используют исторические template names. Эти bridges не содержат product UI/business logic Profile: product views/assets и runtime code принадлежат `modules/profile`, а module владеет routes/capability. Позже bridges можно удалить, изменив controller template identifiers на `@profile/*`, не меняя runtime boundary.

## Критерии завершения для модуля

Мигрированный module:

- физически расположен в `modules/<id>/`;
- не оставляет product controller/service/model/view route ownership в общих legacy locations;
- владеет собственным route provider;
- владеет storage/schema/migration metadata;
- может быть исключён из runtime composition без загрузки его entrypoint или routes;
- завершается fail-closed при missing/escaping/invalid entrypoint;
- экспортирует только capabilities, объявленные в manifest;
- использует capability contracts вместо прямого доступа к internals другого module;
- проходит module-specific HTTP/browser/data regression tests;
- остаётся совместимым с updater/package composition и lifecycle reconciliation.

## Release gate 1.0

Перед созданием tag 1.0:

- каждый bundled production module (`admin`, `files`, `messenger`, `notes`, `profile`, `tasks`) должен сообщать `runtime.mode = isolated`;
- ни один bundled module не должен зависеть от переходного recursive product loader;
- отключение/удаление module не должно требовать изменения внутренних файлов другого module;
- duplicate или undeclared capability providers должны завершаться fail-closed;
- web roots module package остаются непубличными, если не открыты явно через core routing/static asset policy.
