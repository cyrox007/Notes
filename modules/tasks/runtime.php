<?php
declare(strict_types=1);

$root = __DIR__;
$files = [
    '/models/TaskModel.php',
    '/models/SubtaskModel.php',
    '/models/TaskCategoryModel.php',
    '/models/TaskCategoryRelationModel.php',
    '/models/TaskReminderModel.php',
    '/services/TaskBoardService.php',
    '/middlewares/RequireTasksUse.php',
    '/middlewares/EnforceTaskCreatePolicy.php',
    '/controllers/TaskController.php',
    '/controllers/TaskBoardController.php',
    '/TasksCapability.php',
    '/TasksRuntimeProvider.php',
];

foreach ($files as $file) {
    $path = $root . $file;
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Tasks runtime file is missing or unsafe: ' . $file);
    }
    require_once $path;
}

return new \Modules\Tasks\TasksRuntimeProvider();
