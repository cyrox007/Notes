<?php
declare(strict_types=1);

$root = __DIR__;
$files = [
    '/models/TaskModel.php',
    '/models/SubtaskModel.php',
    '/models/TaskCategoryModel.php',
    '/models/TaskCategoryRelationModel.php',
    '/models/TaskReminderModel.php',
    '/services/TasksAccountOwnership.php',
    '/services/TaskPersonalQueue.php',
    '/services/TaskBoardService.php',
    '/services/TaskDayOrderService.php',
    '/controllers/TaskDayOrderController.php',
    '/services/TaskVersionMutationService.php',
    '/services/TaskReadApiService.php',
    '/services/TaskApiTokenService.php',
    '/services/TaskApiTrafficService.php',
    '/services/TaskApiCreateService.php',
    '/services/TaskNotificationActionService.php',
    '/../../app/services/notifications/NotificationActionTokenStore.php',
    '/services/TaskRelationMutationService.php',
    '/services/TaskBoardVersionMutationService.php',
    '/middlewares/RequireTasksUse.php',
    '/middlewares/EnforceTaskCreatePolicy.php',
    '/controllers/TaskController.php',
    '/controllers/TaskMutationController.php',
    '/controllers/TaskReadApiController.php',
    '/controllers/TaskApiTokenController.php',
    '/controllers/TaskWriteApiController.php',
    '/controllers/TaskBoardApiController.php',
    '/controllers/TaskRelationController.php',
    '/controllers/TaskBoardController.php',
    '/TasksCapability.php',
    '/TasksCalendarLinkProvider.php',
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
