<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function tasksAjaxAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$scriptPath = $root . '/modules/tasks/assets/tasks-page.js';
$script = file_get_contents($scriptPath);
if (!is_string($script)) {
    throw new RuntimeException('Не удалось прочитать modules/tasks/assets/tasks-page.js');
}

tasksAjaxAssert(
    !str_contains($script, 'window.location.reload()'),
    'Частые действия Tasks не должны выполнять полную перезагрузку страницы'
);
tasksAjaxAssert(
    str_contains($script, 'async function refreshTaskSection'),
    'Tasks должен уметь обновлять отдельный серверный фрагмент карточки'
);
tasksAjaxAssert(
    str_contains($script, "refreshTaskSection(addSubtaskButton.dataset.taskId, '.task-subtasks')"),
    'Добавление подзадачи должно обновлять только блок подзадач'
);
tasksAjaxAssert(
    str_contains($script, "refreshTaskSection(taskUid, '.task-subtasks')"),
    'Удаление подзадачи должно обновлять только блок подзадач'
);
tasksAjaxAssert(
    substr_count($script, "refreshTaskSection(taskUid, '.task-meta')") >= 2,
    'Назначение и снятие категории должны обновлять только метаданные задачи'
);
tasksAjaxAssert(
    str_contains($script, "root.addEventListener('click', async (event) =>"),
    'Динамические кнопки Tasks должны обслуживаться делегированным обработчиком'
);
tasksAjaxAssert(
    str_contains($script, "root.addEventListener('change', async (event) =>"),
    'Динамические переключатели подзадач должны обслуживаться делегированным обработчиком'
);
tasksAjaxAssert(
    str_contains($script, "headers: {\n                    'Accept': 'text/html',\n                    'X-Requested-With': 'XMLHttpRequest'"),
    'Локальное обновление должно запрашивать серверную разметку через AJAX'
);

fwrite(STDOUT, "[OK] Частые действия Tasks работают без полной перезагрузки страницы\n");
