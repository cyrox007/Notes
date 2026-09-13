# Модуль «Ежедневник / Задачи»

Документ описывает актуальный контракт Tasks для Workspace Organizer. Модуль предназначен для персональных задач пользователя: статусы, приоритеты, сроки, подзадачи и категории.

## Возможности

- создание, редактирование и soft-delete задач;
- статусы `pending`, `in_progress`, `completed`, `cancelled`;
- приоритеты `low`, `medium`, `high`, `urgent`;
- срок выполнения и признак просрочки;
- фильтры «все / сегодня / неделя / просроченные / по статусу»;
- безопасная сортировка по явному allowlist;
- checklist-подзадачи;
- личные и системные категории;
- статистика по всем активным задачам пользователя.

## База данных

Canonical fresh-install schema: `database/tasks_schema.sql`.

Перед Tasks schema должна существовать canonical таблица `users`, которую на fresh install создаёт `database/messenger_schema.sql`.

```bash
mysql -u root -p workspace < database/messenger_schema.sql
mysql -u root -p workspace < database/tasks_schema.sql
```

Tasks schema содержит пять реально используемых таблиц:

| Таблица | Назначение |
|---|---|
| `tasks` | задачи пользователя |
| `subtasks` | checklist задачи |
| `task_categories` | личные и системные категории |
| `task_category_relations` | many-to-many task/category |
| `task_reminders` | база для будущего механизма напоминаний |

`task_category_relations` имеет UNIQUE `(task_id, category_id)`, поэтому повторное назначение категории идемпотентно.

> `task_history` в текущем приложении не реализован и не входит в canonical schema. Напоминания имеют таблицу/модель, но пользовательский scheduler/notification flow пока не подключён.

## Модели

- `TaskModel` — задача и legacy helper-методы домена;
- `SubtaskModel` — подзадача;
- `TaskCategoryModel` — категория;
- `TaskCategoryRelationModel` — связь задачи с категорией;
- `TaskReminderModel` — данные будущих напоминаний.

UI не зависит от вызова методов ORM-объекта из Smarty. `TaskController` формирует явный view-model: `priority_color`, `status_label`, `is_overdue`, `completion_percentage`, `categories`, `subtasks`.

## HTTP routes

Маршруты объявлены в `core/routerConfig.php` и защищены `LoginRequared`.

| Метод | Route | Назначение |
|---|---|---|
| GET | `/tasks/` | список, фильтры, статистика |
| POST | `/tasks/` | создать задачу |
| POST | `/tasks/{uid}/update` | изменить поля/статус |
| POST | `/tasks/{uid}/delete` | soft-delete задачи |
| POST | `/tasks/{taskUid}/subtask` | добавить подзадачу |
| POST | `/tasks/subtask/{id}/toggle` | переключить подзадачу |
| POST | `/tasks/subtask/{id}/delete` | удалить подзадачу |
| POST | `/tasks/category` | создать личную категорию |
| POST | `/tasks/{taskUid}/category/{id}` | назначить категорию |
| DELETE | `/tasks/{taskUid}/category/{id}` | снять категорию |

State-changing запросы проходят общую CSRF-защиту. `core/common.js` автоматически добавляет `X-CSRF-Token` к same-origin `fetch`/XHR; обычные формы содержат `{csrf_token}`.

## ACL

Задача всегда принадлежит одному `user_id`.

Пользователь может:
- видеть и менять только свои задачи;
- добавлять/переключать/удалять подзадачи только внутри своих задач;
- создавать личные категории;
- назначать своим задачам только собственные категории или системные категории с `user_id IS NULL`;
- не может использовать чужую персональную категорию, даже зная её ID.

Проверка ACL выполняется на сервере. Значения из DOM, URL или JavaScript не считаются подтверждением права доступа.

## Валидация

Controller использует явные allowlist:

- status: `pending`, `in_progress`, `completed`, `cancelled`;
- priority: `low`, `medium`, `high`, `urgent`;
- sort: `created_at`, `updated_at`, `title`, `due_date`, `priority`, `status`;
- direction: только ASC/DESC;
- category color: только `#RRGGBB`;
- category icon: только `fa-*` безопасного формата.

Название задачи — до 255 символов, описание — до 10 000, название категории — до 120, название подзадачи — до 255.

`datetime-local` нормализуется на сервере в MySQL `DATETIME`. При переходе задачи в `completed` выставляется `completed_at`; при возврате в другой статус `completed_at` очищается.

## Пользовательская инструкция

### Создать задачу

1. Откройте **Ежедневник** (`/tasks/`).
2. Нажмите **+ Новая задача**.
3. Введите название, при необходимости описание, приоритет и срок.
4. Нажмите **Создать задачу**.

### Изменить статус

Статус можно поменять селектором в карточке. Checkbox слева быстро переводит задачу в `completed`; снятие отметки возвращает её в `pending`.

### Редактировать задачу

Нажмите кнопку с карандашом. В карточке откроется форма редактирования названия, описания, статуса, приоритета и срока. Сохранение выполняется через `POST /tasks/{uid}/update`.

### Подзадачи

Нажмите **+ Добавить** в блоке подзадач, введите название. Подзадачу можно отметить выполненной или удалить. Процент выполнения считается по текущему checklist.

### Категории

Новая категория создаётся на странице задач: имя, цвет и одна из разрешённых иконок. Затем выберите категорию в карточке задачи и нажмите **Добавить категорию**. Кнопка `×` на badge снимает категорию с задачи.

### Фильтры и сортировка

Фильтры не меняют статистические карточки: статистика показывает состояние всех активных задач пользователя. Список можно отдельно сортировать по созданию, изменению, сроку, приоритету, статусу или названию.

## JSON contract для интерактивных действий

AJAX-запросы отправляют `Accept: application/json` и `X-Requested-With: XMLHttpRequest`.

Успех:

```json
{"success": true}
```

Ошибка ACL/валидации:

```json
{"success": false, "error": "Описание ошибки"}
```

Например, quick status update:

```text
POST /tasks/11111111111111111111111111111111/update
Content-Type: application/x-www-form-urlencoded
Accept: application/json

status=completed
```

## CI

Workflow `.github/workflows/tasks-contract.yml` проверяет:

- PHP syntax и отсутствие старого undefined `$task` ACL;
- clean import `messenger_schema.sql + tasks_schema.sql`;
- наличие всех canonical Tasks tables;
- UNIQUE relation task/category;
- owner/outsider update ACL;
- корректную установку/очистку `completed_at`;
- owner/outsider ACL для subtasks;
- запрет назначения чужой категории;
- доступ к системной категории;
- идемпотентность повторного attach.

## Ограничения текущей версии

Пока не реализованы scheduler/уведомления для `task_reminders`, рекуррентные задачи, совместные задачи, комментарии, task attachments и календарный view. Эти функции не следует считать частью текущего контракта только из-за наличия таблицы/модели-заготовки.

## Требования

- PHP 8.3+;
- MySQL 8.x / совместимая MariaDB;
- Composer dependencies проекта;
- Smarty и общий Workspace Organizer core.
