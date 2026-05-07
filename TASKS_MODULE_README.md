# Модуль "Ежедневник/Задачи" (Task Manager)

## Обзор

Модуль представляет собой систему управления задачами и ежедневник пользователя с возможностью:
- Создания, редактирования и удаления задач
- Управления статусами и приоритетами задач
- Работы с подзадачами (чек-листы)
- Категоризации задач
- Фильтрации и сортировки
- Отслеживания сроков выполнения

## Структура базы данных

Все таблицы описаны в файле `database/tasks_schema.sql`:

1. **tasks** - основные задачи пользователей
2. **task_categories** - категории задач (личные и системные)
3. **task_category_relations** - связь задач с категориями
4. **subtasks** - подзадачи (чек-листы)
5. **task_reminders** - напоминания о задачах
6. **task_history** - история изменений задач

### Установка БД

```bash
mysql -u username -p database_name < database/tasks_schema.sql
```

## Архитектура MVC

### Models (app/models/)

- **TaskModel.php** - основная модель задачи
  - Статусы: pending, in_progress, completed, cancelled
  - Приоритеты: low, medium, high, urgent
  - Методы: getCategories(), getSubtasks(), getReminders(), complete(), isOverdue()

- **TaskCategoryModel.php** - модель категории задач
- **TaskCategoryRelationModel.php** - модель связи задача-категория
- **SubtaskModel.php** - модель подзадачи
- **TaskReminderModel.php** - модель напоминания

### Controllers (app/controllers/)

**TaskController.php** - основной контроллер модуля:

| Метод | Route | Описание |
|-------|-------|----------|
| index() | GET /tasks | Список задач с фильтрацией |
| create() | POST /tasks | Создание новой задачи |
| update() | POST /tasks/{uid}/update | Обновление задачи |
| delete() | GET /tasks/{uid}/delete | Удаление задачи |
| addSubtask() | POST /tasks/{uid}/subtask | Добавление подзадачи |
| toggleSubtask() | POST /tasks/subtask/{id}/toggle | Переключение статуса подзадачи |
| deleteSubtask() | POST /tasks/subtask/{id}/delete | Удаление подзадачи |
| createCategory() | POST /tasks/category | Создание категории |
| attachCategory() | POST /tasks/{uid}/category/{id} | Привязка категории |
| detachCategory() | DELETE /tasks/{uid}/category/{id} | Отвязка категории |

### Views (app/views/)

- **tasks_page/index.tpl** - главная страница списка задач
- **tasks_page/style.css** - стили модуля
- **^elements/task_item/index.tpl** - шаблон элемента задачи

## Функционал

### Статусы задач

- **pending** - ожидает выполнения
- **in_progress** - в процессе выполнения  
- **completed** - завершена
- **cancelled** - отменена

### Приоритеты

- **low** - низкий (серый)
- **medium** - средний (синий)
- **high** - высокий (оранжевый)
- **urgent** - срочный (красный)

### Фильтры

- **all** - все задачи
- **today** - задачи на сегодня
- **week** - задачи на неделю
- **pending** - ожидающие
- **in_progress** - в процессе
- **overdue** - просроченные
- **completed** - завершенные

### Подзадачи

Каждая задача может содержать неограниченное количество подзадач в формате чек-листа. 
При completion всех подзадач отображается прогресс 100%.

### Категории

Система поддерживает:
- **Системные категории** (user_id = NULL): Работа, Личное, Покупки, Здоровье, Обучение, Дом
- **Пользовательские категории**: создаются каждым пользователем индивидуально

## Роутинг

В файле `core/routerConfig.php` добавлена группа маршрутов `/tasks`:

```php
$router->group('/tasks', function (Router $addRoute) {
    $addRoute->add("GET", '/', [TaskController::class, 'index'], [LoginRequared::class], 'tasks');
    $addRoute->add("POST", '/', [TaskController::class, 'create'], [LoginRequared::class], 'task_create');
    // ... другие маршруты
});
```

## API Endpoints

### JSON API

Некоторые методы возвращают JSON ответы:

**Добавление подзадачи:**
```
POST /tasks/{taskUid}/subtask
Content-Type: application/x-www-form-urlencoded

title=Название подзадачи

Response:
{
    "success": true,
    "subtask": {
        "id": 123,
        "title": "Название подзадачи",
        "is_completed": 0
    }
}
```

**Переключение подзадачи:**
```
POST /tasks/subtask/{subtaskId}/toggle

Response:
{
    "success": true,
    "is_completed": 1
}
```

**Привязка категории:**
```
POST /tasks/{taskUid}/category/{categoryId}

Response:
{
    "success": true
}
```

## Стили

CSS файл `app/views/tasks_page/style.css` включает:

- Адаптивную верстку (mobile-first)
- Цветовую индикацию приоритетов
- Статистические карточки
- Модальное окно создания задачи
- Стили для подзадач и категорий

## Безопасность

- Все маршруты защищены middleware `LoginRequared`
- Проверка прав доступа к задачам (user_id)
- Safe-удаление (флаг is_deleted вместо физического удаления)
- CSRF токены в формах

## Расширение функционала

### Возможные улучшения:

1. **Напоминания** - реализовать отправку уведомлений (email, push)
2. **Рекуррентные задачи** - повторяющиеся задачи (ежедневно, еженедельно)
3. **Комментарии** - обсуждение задач
4. **Файлы** - прикрепление файлов к задачам
5. **Совместный доступ** - шеринг задач между пользователями
6. **Календарь** - view задач в календарном формате
7. **Теги** - гибкая система тегирования
8. **Экспорт** - экспорт задач в CSV/PDF

## Пример использования

### Создание задачи через форму

```html
<form action="/tasks/" method="post">
    <input type="text" name="title" required>
    <textarea name="description"></textarea>
    <select name="priority">
        <option value="low">Низкий</option>
        <option value="medium" selected>Средний</option>
        <option value="high">Высокий</option>
        <option value="urgent">Срочный</option>
    </select>
    <input type="datetime-local" name="due_date">
    <button type="submit">Создать</button>
</form>
```

### Получение задач в коде

```php
use App\Models\TaskModel;

// Получить все активные задачи пользователя
$tasks = TaskModel::select()
    ->where('user_id', '=', $userId)
    ->where('is_deleted', '=', 0)
    ->orderBy('due_date', 'ASC')
    ->get();

foreach ($tasks as $task) {
    if ($task->isOverdue()) {
        echo "Задача просрочена: " . $task->title;
    }
    
    $subtasks = $task->getSubtasks();
    $percentage = $task->getCompletionPercentage();
}
```

## Зависимости

- PHP 7.4+
- MySQL 5.7+
- FontAwesome (для иконок)
- Smarty (для шаблонов)

## Лицензия

Модуль является частью основной системы и распространяется на тех же условиях.
