<?php

declare(strict_types=1);

// Одноразовый HTTP-стенд: прямые вставки допустимы только для тестовых данных.
// Рабочий модуль обращается к БД исключительно через ядро.
$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'Core\\')) {
        return;
    }
    $file = $root . '/core/' . substr($class, 5) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

use Core\DatabaseOwnership;
use Core\InstallerDatabaseService;

if (getenv('DBNAME') !== 'defects_http_load') {
    throw new RuntimeException('Разрешена только одноразовая БД defects_http_load');
}
$password = (string) getenv('DEFECTS_HTTP_PASSWORD');
if (strlen($password) < 20) {
    throw new RuntimeException('Не указан случайный пароль нагрузочного пользователя');
}

$host = (string) (getenv('DBHOST') ?: '127.0.0.1');
$port = (int) (getenv('DBPORT') ?: 3306);
$dbUser = (string) (getenv('DBUSER') ?: 'root');
$dbPassword = (string) (getenv('DBPASS') ?: '');
$ownership = DatabaseOwnership::fromPackageRoot($root);
$schemas = array_map(static fn (string $path): string => $root . '/' . $path, $ownership->schemaFiles());
$installer = new InstallerDatabaseService();
$installer->importSchemas($host, $port, 'defects_http_load', $dbUser, $dbPassword, $schemas);
$pdo = $installer->connect($host, $port, 'defects_http_load', $dbUser, $dbPassword);
$installer->createAdminUser(
    $pdo, 'defects-http-load-admin', 'defects-http-load@example.test',
    $password, 'Испытатель', 'Дефектов'
);
$actor = (int) $pdo->query(
    "SELECT id FROM users WHERE username='defects-http-load-admin'"
)->fetchColumn();

$pdo->exec("INSERT INTO module_lifecycle
    (module_id,installed_version,manifest_hash,configured_state,effective_state)
    VALUES ('defects','1.2.0',REPEAT('0',64),'enabled','enabled')");
$areaInsert = $pdo->prepare('INSERT INTO defect_areas(name) VALUES (?)');
$categoryInsert = $pdo->prepare('INSERT INTO defect_object_categories(area_id,name) VALUES (?,?)');
$objectInsert = $pdo->prepare('INSERT INTO defect_objects(category_id,name) VALUES (?,?)');
$serviceInsert = $pdo->prepare('INSERT INTO defect_services(name) VALUES (?)');

$objects = [];
for ($areaIndex = 1; $areaIndex <= 4; $areaIndex++) {
    $areaInsert->execute([sprintf('Испытательный РЭС %d', $areaIndex)]);
    $areaId = (int) $pdo->lastInsertId();
    for ($categoryIndex = 1; $categoryIndex <= 3; $categoryIndex++) {
        $categoryInsert->execute([$areaId, sprintf('Категория %d', $categoryIndex)]);
        $categoryId = (int) $pdo->lastInsertId();
        for ($objectIndex = 1; $objectIndex <= 4; $objectIndex++) {
            $objectInsert->execute([$categoryId, sprintf('Объект %d-%d-%d', $areaIndex, $categoryIndex, $objectIndex)]);
            $objects[] = (int) $pdo->lastInsertId();
        }
    }
}
for ($serviceIndex = 1; $serviceIndex <= 4; $serviceIndex++) {
    $serviceInsert->execute([sprintf('Испытательная служба %d', $serviceIndex)]);
}

$insertDefect = $pdo->prepare(
    "INSERT INTO defects
    (object_id,description,priority,discovered_at,due_at,registered_by_name,
     registered_by_user_id,creation_command_id,creation_request_hash,status)
    VALUES (:object_id,:description,'normal',:discovered,:due,'Нагрузочный стенд',
            :actor,:command,:hash,'open')"
);
$insertResponsible = $pdo->prepare(
    'INSERT INTO defect_responsible_services(defect_id,service_id) VALUES (?,?)'
);
$insertHistory = $pdo->prepare(
    "INSERT INTO defect_history
    (entity_type,entity_id,event_type,actor_user_id,command_id,payload_json,created_at)
    VALUES ('defect',:id,'created',:actor,:command,:payload,UTC_TIMESTAMP())"
);
$pdo->beginTransaction();
try {
    for ($index = 0; $index < 720; $index++) {
        $command = 'seed_' . bin2hex(random_bytes(16));
        $description = sprintf('Подготовленная запись HTTP-стенда %04d', $index);
        $objectId = $objects[$index % count($objects)];
        $insertDefect->execute([
            'object_id' => $objectId,
            'description' => $description,
            'discovered' => gmdate('Y-m-d H:i:s', time() - 3600),
            'due' => gmdate('Y-m-d H:i:s', time() + 86400 * 30),
            'actor' => $actor,
            'command' => $command,
            'hash' => hash('sha256', $description),
        ]);
        $id = (int) $pdo->lastInsertId();
        $insertResponsible->execute([$id, 1 + ($index % 4)]);
        $insertHistory->execute([
            'id' => $id,
            'actor' => $actor,
            'command' => $command,
            'payload' => json_encode(
                ['description' => $description, 'object_id' => $objectId],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            ),
        ]);
    }
    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    throw $error;
}

echo json_encode([
    'username' => 'defects-http-load-admin',
    'object_id' => $objects[0],
    'service_ids' => [1, 2],
    'known_defect_id' => 1,
    'seeded_records' => 720,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
