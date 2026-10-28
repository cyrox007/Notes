<?php

declare(strict_types=1);

use Core\DatabaseManager;
use Modules\Defects\Services\DefectRegistryService;
use Modules\Defects\Services\DefectServiceAssignmentService;

$root = dirname(__DIR__, 2);
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/modules/defects/services/DefectHistoryService.php';
require_once $root . '/modules/defects/services/DefectRegistryService.php';
require_once $root . '/modules/defects/services/DefectServiceAssignmentService.php';

$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=defects_browser;charset=utf8mb4',
    'root', 'root',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$database = DatabaseManager::forConnection($pdo);
$command = 'mysql_assign_' . bin2hex(random_bytes(12));
$defectId = 0;

try {
    $database->execute(
        "INSERT INTO defects
           (object_id,description,priority,discovered_at,due_at,registered_by_name,
            registered_by_user_id,creation_command_id,creation_request_hash)
         VALUES (1,'Проверка назначений MySQL','normal',UTC_TIMESTAMP(),
            DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 DAY),'Проверка',NULL,:command,:hash)",
        ['command' => $command, 'hash' => hash('sha256', $command)]
    );
    $defectId = (int) $database->lastInsertId();
    $database->execute(
        'INSERT INTO defect_responsible_services (defect_id,service_id)'
        . ' VALUES (:defect_id,1)', ['defect_id' => $defectId]
    );

    $result = (new DefectServiceAssignmentService($database))->replace(
        $defectId, 1, 1,
        [['service_id' => 1, 'executor_name' => 'Иванов Иван Иванович']],
        'mysql_change_' . bin2hex(random_bytes(12))
    );
    if ($result['version'] !== 2) {
        throw new RuntimeException('Версия назначений не изменилась');
    }

    $detail = (new DefectRegistryService($database))->detail($defectId);
    if (($detail['services'][0]['executor_name'] ?? '') !== 'Иванов Иван Иванович') {
        throw new RuntimeException('ФИО исполнителя не сохранилось в MySQL');
    }
    echo 'Сохранение назначений в MySQL и чтение карточки проверены' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Ошибка операции назначений MySQL: ' . $error::class
        . ', ' . $error->getMessage() . PHP_EOL);
    throw $error;
} finally {
    if ($defectId > 0) {
        $database->execute(
            "DELETE FROM defect_history WHERE entity_type='defect' AND entity_id=:id",
            ['id' => $defectId]
        );
        $database->execute('DELETE FROM defect_responsible_services WHERE defect_id=:id', ['id' => $defectId]);
        $database->execute('DELETE FROM defects WHERE id=:id', ['id' => $defectId]);
    }
}
