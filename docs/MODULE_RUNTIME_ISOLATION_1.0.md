# Workspace Organizer 1.0 — изоляция runtime модулей

Актуально: 22 сентября 2026.

Этот документ фиксирует **текущий** runtime-контракт. Историческая миграция завершена: все встроенные production-модули переведены на изолированный runtime, а переходный рекурсивный загрузчик product-кода больше не является частью рабочей архитектуры.

## Текущее состояние

Изолированы все шесть встроенных модулей:

- `admin`;
- `files`;
- `messenger`;
- `notes`;
- `profile`;
- `tasks`.

Для каждого из них manifest содержит:

```json
"runtime": {
  "mode": "isolated",
  "entrypoint": "runtime.php"
}
```

Core загружает platform/shared primitives явно и подключает product runtime только через выбранную composition модулей.

## Граница изолированного runtime

Entrypoint модуля:

- задаётся относительным PHP-путём внутри `modules/<id>/`;
- не может выходить за корень модуля;
- загружается только когда модуль присутствует в effective runtime composition;
- возвращает `Core\ModuleRuntimeProvider`;
- сообщает тот же module ID, что и manifest;
- запускается после зависимостей;
- владеет регистрацией маршрутов модуля;
- экспортирует только capabilities, объявленные в `module.json`.

Отключение или отсутствие одного модуля не должно требовать редактирования внутренних файлов другого модуля и не должно приводить к fatal error ядра.

## Межмодульные capabilities

Изолированные модули не подключают внутренние PHP-файлы соседнего модуля по пути. Межмодульные сервисы обнаруживаются через `Core\ModuleCapabilityRegistry`.

Правила:

- идентификатор capability объявляется в manifest provider-модуля;
- runtime provider экспортирует тот же набор capabilities;
- capability доступна только от модуля, входящего в effective composition;
- у активной capability ровно один provider; дубликат закрывает запуск с ошибкой;
- после boot registry запечатывается и не изменяется во время request dispatch;
- consumer получает capability через `ModuleRuntimeLoader::getInstance()->capabilities()`;
- consumer может потребовать ожидаемый interface/class; несовпадение типа закрывается с ошибкой;
- диагностика может показать module owner capability без раскрытия внутренних filesystem paths.

Пример будущей интеграции:

```php
$player = ModuleRuntimeLoader::getInstance()
    ->capabilities()
    ->require('media.playback', MediaPlayback::class);
```

## Владение файлами, схемой и состоянием

Изолированный модуль владеет своими:

- controllers/services/domain models;
- views/assets;
- permissions/capabilities;
- storage namespace;
- schema/migration ownership metadata;
- health/lifecycle hooks;
- update metadata.

Канонические корневые SQL-каталоги остаются известной границей платформы 1.x для модулей с БД. Это не возвращает runtime ownership в Core и не является основанием заново переносить уже изолированные модули.

## Исторический порядок миграции

Модули переносились в порядке:

1. Notes;
2. Tasks;
3. Files;
4. Profile;
5. Admin;
6. Messenger.

Этот список теперь **исторический**. Он не означает, что Profile/Admin/Messenger остаются legacy.

Узкие compatibility bridges допускаются только там, где они не содержат product UI/business logic и не возвращают ownership маршрутов/сервисов в shared runtime.

## Критерий сохранения изоляции

Регрессией считается любое из следующего:

- manifest production-модуля возвращён в `runtime.mode = legacy`;
- product controller/service/model снова размещён в shared Core и требуется конкретному модулю;
- другой модуль напрямую подключает внутренний файл соседа;
- capability provider зависит от boot order или допускает дубликат;
- отключение модуля ломает загрузку Core;
- route ownership возвращается в общий product router;
- package/update composition не умеет корректно работать без отсутствующего модуля.

Такие изменения требуют отдельного воспроизводимого дефекта и регрессионной проверки. Исторический текст аудитов сам по себе не переоткрывает завершённую миграцию.
