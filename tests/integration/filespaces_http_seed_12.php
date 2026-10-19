<?php

declare(strict_types=1);

// Подготовка только одноразовой базы fsp_http_load для нагрузочного HTTP-стенда.
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
require_once $root . '/modules/files/services/FileSpaceStorageGuard.php';
require_once $root . '/modules/files/services/FileSpaceAccessService.php';
require_once $root . '/modules/files/services/FileSpaceFolderService.php';

use App\Services\Files\FileSpaceAccessService;
use App\Services\Files\FileSpaceFolderService;
use Core\DatabaseOwnership;
use Core\InstallerDatabaseService;

$database = (string) getenv('DBNAME');
if (preg_match('/^fsp_http_load(_[a-z0-9]+)?$/D', $database) !== 1) {
    throw new RuntimeException('Разрешена только отдельная тестовая база fsp_http_load');
}
$host = (string) (getenv('DBHOST') ?: '127.0.0.1');
$port = (int) (getenv('DBPORT') ?: 3306);
$user = (string) (getenv('DBUSER') ?: 'root');
$password = (string) (getenv('DBPASS') ?: '');
$testPassword = (string) getenv('FSP_HTTP_PASSWORD');
if (strlen($testPassword) < 20) {
    throw new RuntimeException('Не указан случайный пароль нагрузочного пользователя');
}

$owner = DatabaseOwnership::fromPackageRoot($root);
$schemas = array_map(static fn (string $path): string => $root . '/' . $path, $owner->schemaFiles());
$installer = new InstallerDatabaseService();
$installer->importSchemas($host, $port, $database, $user, $password, $schemas);
$pdo = $installer->connect($host, $port, $database, $user, $password);
$installer->createAdminUser(
    $pdo, 'files-http-load-admin', 'files-http-load@example.test',
    $testPassword, 'Проверка', 'Нагрузки'
);
$actor = (int) $pdo->query(
    "SELECT id FROM users WHERE username='files-http-load-admin'"
)->fetchColumn();
$access = new FileSpaceAccessService($pdo);
$folders = new FileSpaceFolderService($pdo, $access);
$space = $access->space();
$created = $folders->createFolder(
    $actor, null, 'Нагрузочный каталог',
    (int) $space['version'], 'files-http-load-fixture-folder'
);
$folderId = (int) $created['folder_id'];

$storage = (string) getenv('PRIVATE_STORAGE_PATH');
if (!is_dir($storage) || realpath($storage) === false) {
    throw new RuntimeException('Нужно заранее создать приватный каталог за пределами веб-корня');
}
$path = realpath($storage) . '/file_spaces/global';
if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
    throw new RuntimeException('Не удалось подготовить физические файлы');
}
$key = bin2hex(random_bytes(24)) . '.txt';
$bytes = 'Проверка общих файлов по HTTP — Files 1.2';
if (file_put_contents($path . '/' . $key, $bytes, LOCK_EX) === false) {
    throw new RuntimeException('Не удалось подготовить файл для скачивания');
}
chmod($path . '/' . $key, 0600);

$stmt = $pdo->prepare(
    'INSERT INTO file_space_nodes
       (uid,space_id,parent_id,node_type,name,created_by,updated_by,
        mime_type,byte_size,storage_key)
     VALUES (:uid,:space,:parent,"file",:name,:actor,:editor,
             "text/plain",:size,:storage)'
);
$stmt->execute([
    ':uid' => bin2hex(random_bytes(16)),
    ':space' => (int) $space['id'],
    ':parent' => $folderId,
    ':name' => 'Нагрузочный.txt',
    ':actor' => $actor,
    ':editor' => $actor,
    ':size' => strlen($bytes),
    ':storage' => $key,
]);
$fileId = (int) $pdo->lastInsertId();

// Несколько удалённых объектов в одной папке показывают повторные проверки ACL
// и версии родителя при отрисовке корзины. Это только одноразовые тестовые данные.
$deleted = $pdo->prepare(
    'INSERT INTO file_space_nodes
       (uid,space_id,parent_id,node_type,name,created_by,updated_by,is_deleted,deleted_at)
     VALUES (:uid,:space,:parent,"folder",:name,:actor,:editor,1,NOW())'
);
for ($index = 0; $index < 30; $index++) {
    $deleted->execute([
        ':uid' => bin2hex(random_bytes(16)),
        ':space' => (int) $space['id'],
        ':parent' => $folderId,
        ':name' => sprintf('Удалённая-%03d', $index),
        ':actor' => $actor,
        ':editor' => $actor,
    ]);
}
echo json_encode([
    'folder_id' => $folderId,
    'file_id' => $fileId,
    'expected_text' => $bytes,
    'username' => 'files-http-load-admin',
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
