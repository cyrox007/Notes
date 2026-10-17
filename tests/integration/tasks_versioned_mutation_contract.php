<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function versionedTasksAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Контракт версионных мутаций Tasks нарушен: {$message}\n");
        exit(1);
    }
}

$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) {
        throw new RuntimeException('Не удалось прочитать ' . $path);
    }
    return str_replace("\r\n", "\n", $content);
};

$provider = $read('modules/tasks/TasksRuntimeProvider.php');
$runtime = $read('modules/tasks/runtime.php');
$controller = $read('modules/tasks/controllers/TaskMutationController.php');
$view = $read('modules/tasks/views/elements/task_item/index.php');
$page = $read('modules/tasks/views/index.php');
$client = $read('modules/tasks/assets/tasks-versioned-mutations.js');

versionedTasksAssert(
    str_contains($provider, "[TaskMutationController::class, 'update']")
    && str_contains($provider, "[TaskMutationController::class, 'delete']"),
    'маршруты изменения и удаления должны идти через TaskMutationController'
);
versionedTasksAssert(
    str_contains($runtime, "'/services/TaskVersionMutationService.php'")
    && str_contains($runtime, "'/controllers/TaskMutationController.php'"),
    'runtime Tasks должен явно загружать сервис и контроллер мутаций'
);
versionedTasksAssert(
    str_contains($controller, 'TaskVersionMutationService')
    && str_contains($controller, "post('expected_version'")
    && str_contains($controller, "post('command_id'")
    && str_contains($controller, "'version_conflict'")
    && str_contains($controller, '$this->json($result, 409)'),
    'HTTP-контроллер должен требовать version/command_id и возвращать 409 при конфликте'
);
versionedTasksAssert(
    str_contains($view, 'data-version="<?= $version ?>"')
    && substr_count($view, 'name="expected_version"') >= 3
    && substr_count($view, 'name="command_id"') >= 3,
    'карточка и fallback-формы должны нести текущую версию и идентификаторы команд'
);
versionedTasksAssert(
    str_contains($view, 'class="task-delete-form"'),
    'форма удаления должна иметь отдельный AJAX-маркер'
);
versionedTasksAssert(
    str_contains($page, "moduleAsset('tasks', 'tasks-versioned-mutations.js')"),
    'страница Tasks должна подключать версионный AJAX-клиент'
);
versionedTasksAssert(
    str_contains($client, 'expected_version')
    && str_contains($client, 'command_id')
    && str_contains($client, "error?.status === 409")
    && str_contains($client, "error?.payload?.error === 'version_conflict'")
    && str_contains($client, 'task.dataset.version = String(normalized)'),
    'AJAX-клиент должен передавать версию/command_id, распознавать 409 и обновлять версию DOM'
);
versionedTasksAssert(
    str_contains($client, "root.addEventListener('submit'")
    && str_contains($client, "form.closest('.task-edit-form')")
    && str_contains($client, "form.closest('.task-archive-form')")
    && str_contains($client, "form.closest('.task-delete-form')"),
    'редактирование, архив и удаление должны выполняться без полной навигации при доступном JS'
);
versionedTasksAssert(
    !str_contains($client, 'window.location.reload()'),
    'конфликт версии не должен маскироваться полной перезагрузкой страницы'
);

fwrite(STDOUT, "[OK] Tasks использует version + expected_version + command_id на HTTP/AJAX границе\n");
