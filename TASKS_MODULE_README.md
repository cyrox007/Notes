# Модуль «Задачи»

Документ описывает текущий контракт изолированного модуля Tasks в Workspace Organizer 1.x. «Задачи» и будущий модуль «Ежедневник + Calendar» — разные модули: Tasks отвечает за задачи, доски и исполнителей, а Ежедневник будет отвечать за время и календарное планирование.

## Runtime и ownership

Manifest модуля:

```text
modules/tasks/module.json
```

Tasks работает как изолированный bundled module:

```text
runtime.mode = isolated
runtime.entrypoint = runtime.php
capability = workspace.tasks
```

Модулю принадлежат routes, controllers/services/models/views/assets, task policies и database ownership. Межмодульная интеграция должна идти через объявленные capabilities, а не через прямое подключение внутренних файлов другого module.

## Возможности

### Личные задачи

- создание, редактирование и soft-delete задач;
- статусы `pending`, `in_progress`, `completed`, `cancelled`;
- приоритеты `low`, `medium`, `high`, `urgent`;
- сроки выполнения и признак просрочки;
- kanban/list views;
- drag-and-drop изменения status;
- фильтры, server-side поиск, сортировка и пагинация;
- checklist-подзадачи;
- личные и системные категории;
- role policies для количественных ограничений.

### Общие доски

- отдельные shared task boards;
- выбранные участники либо аудитория `all_active`;
- board ACL: `owner`, `manager`, `member`, `viewer`;
- несколько исполнителей;
- status/priority/due date;
- отдельный Kanban;
- ограничения по role policies на создание, количество досок, размер команды и assignment.

## База данных

Каноническая схема:

```text
database/tasks_schema.sql
```

Текущий manifest объявляет ownership таблиц:

```text
tasks
subtasks
task_categories
task_category_relations
task_reminders
task_boards
task_board_members
task_board_items
task_board_assignees
```

Compatibility upgrade scripts:

```text
database/migrations/20260913_tasks_contract.sql
database/migrations/20260915_shared_task_boards.sql
```

Каноническая schema описывает текущее состояние fresh install. Уже применённые compatibility migrations не переписываются.

## ACL и авторизация

Доступ к Tasks начинается с persisted RBAC и permission `tasks.use`.

Для личных задач пользователь работает только со своими objects. Для shared boards дополнительно применяется board-level ACL. Значения ID/UID из DOM, URL или JavaScript сами по себе никогда не считаются доказательством права доступа.

State-changing HTTP actions проходят CSRF и server-side authorization. UI visibility не заменяет Service/Controller checks.

## Валидация

Domain values принимаются только из server-side allowlists. В частности:

- status: `pending`, `in_progress`, `completed`, `cancelled`;
- priority: `low`, `medium`, `high`, `urgent`;
- sort/direction — только разрешённые поля и направления;
- category color/icon — только безопасный формат;
- даты нормализуются на сервере.

При переходе task в `completed` фиксируется `completed_at`; при возврате в другой status timestamp очищается.

## Управление module lifecycle

Bundled Tasks обычно включается при установке. Проверить effective state можно через:

```bash
php bin/control.php modules list
```

Отключение выполняется через control plane, а не удалением каталога:

```bash
php bin/control.php modules disable tasks
php bin/control.php modules enable tasks
```

Disable не удаляет пользовательские данные.

## Проверка после установки/обновления

```bash
php bin/migrate.php --status
php bin/healthcheck.php
```

Для разработки и CI используются module/runtime contracts и browser lifecycle Tasks. Изменение routes, schema ownership, module capability или lifecycle должно сопровождаться соответствующим regression coverage.

## Текущая граница с будущим Ежедневником

`task_reminders` остаётся частью исторического Tasks schema, но наличие таблицы не означает, что Tasks становится календарём.

Будущий модуль «Ежедневник + Calendar» описан в `docs/ROADMAP.md` и должен быть отдельным isolated module. Связь Tasks ↔ Calendar должна проходить через публичные module capabilities/contracts.
