<?php

declare(strict_types=1);

if (!defined('SITEPATH')) {
    define('SITEPATH', dirname(__DIR__, 2));
}
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

require SITEPATH . '/core/config.php';
require SITEPATH . '/core/DatabaseManager.php';
require SITEPATH . '/modules/files/services/FileLifecycleService.php';
require SITEPATH . '/app/services/StorageQuotaService.php';

use App\Services\FileLifecycleService;
use App\Services\StorageQuotaService;
use Core\DatabaseManager;

$db = DatabaseManager::getInstance();
$privateRoot = rtrim((string) getenv('PRIVATE_STORAGE_PATH'), DIRECTORY_SEPARATOR);
if ($privateRoot === '') {
    throw new RuntimeException('PRIVATE_STORAGE_PATH is required for lifecycle integration test');
}

$fixtureRoot = $privateRoot . '/file_manager/lifecycle-ci/files';
@mkdir($fixtureRoot, 0700, true);
$externalRoot = sys_get_temp_dir() . '/workspace-file-lifecycle-external';
@mkdir($externalRoot, 0700, true);

$username = 'file-lifecycle-ci';
$email = 'file-lifecycle-ci@example.test';
$db->execute('DELETE FROM users WHERE username = :username', [':username' => $username]);
$db->execute(
    'INSERT INTO users (uid,username,email,password_hash,firstname,lastname,role,is_active) '
    . 'VALUES (:uid,:username,:email,:password_hash,:firstname,:lastname,888,1)',
    [
        ':uid' => '52000000-0000-4000-8000-000000000001',
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => password_hash('integration-password', PASSWORD_ARGON2ID),
        ':firstname' => 'File',
        ':lastname' => 'Lifecycle',
    ]
);
$userId = (int) $db->fetchValue('SELECT id FROM users WHERE username = :username', [':username' => $username]);
if ($userId <= 0) {
    throw new RuntimeException('Failed to create lifecycle fixture user');
}

$insertItem = static function (array $values) use ($db, $userId): int {
    $db->execute(
        'INSERT INTO user_files (uid,user_id,parent_id,name,type,mime_type,size,path,extension,is_deleted) '
        . 'VALUES (:uid,:user_id,:parent_id,:name,:type,:mime_type,:size,:path,:extension,:is_deleted)',
        [
            ':uid' => $values['uid'],
            ':user_id' => $userId,
            ':parent_id' => $values['parent_id'],
            ':name' => $values['name'],
            ':type' => $values['type'],
            ':mime_type' => $values['mime_type'] ?? null,
            ':size' => $values['size'] ?? 0,
            ':path' => $values['path'] ?? null,
            ':extension' => $values['extension'] ?? null,
            ':is_deleted' => $values['is_deleted'] ?? 0,
        ]
    );
    return (int) $db->fetchValue('SELECT id FROM user_files WHERE uid = :uid', [':uid' => $values['uid']]);
};

$rootFolderId = $insertItem([
    'uid' => 'lifecycle-root', 'parent_id' => null, 'name' => 'Root', 'type' => 'folder', 'mime_type' => 'directory',
]);
$childFolderId = $insertItem([
    'uid' => 'lifecycle-child', 'parent_id' => $rootFolderId, 'name' => 'Child', 'type' => 'folder', 'mime_type' => 'directory',
]);

$fileOne = $fixtureRoot . '/one.txt';
$fileTwo = $fixtureRoot . '/two.txt';
file_put_contents($fileOne, 'one');
file_put_contents($fileTwo, 'two');
$insertItem([
    'uid' => 'lifecycle-file-one', 'parent_id' => $rootFolderId, 'name' => 'one', 'type' => 'file',
    'mime_type' => 'text/plain', 'size' => 3, 'path' => $fileOne, 'extension' => 'txt',
]);
$insertItem([
    'uid' => 'lifecycle-file-two', 'parent_id' => $childFolderId, 'name' => 'two', 'type' => 'file',
    'mime_type' => 'text/plain', 'size' => 3, 'path' => $fileTwo, 'extension' => 'txt',
]);

$quotaService = new StorageQuotaService($db);
if ($quotaService->usedBytes($userId) !== 6) {
    throw new RuntimeException('Quota fixture precondition failed: active subtree bytes were not counted');
}

$service = new FileLifecycleService($db);
$result = $service->softDeleteTree($userId, $rootFolderId);
if ($result['deleted_records'] !== 4 || $result['cleanup_failures'] !== 0) {
    throw new RuntimeException('Recursive File Manager soft-delete contract failed');
}
$count = (int) $db->fetchValue(
    'SELECT COUNT(*) FROM user_files WHERE user_id = :user_id AND is_deleted = 1 AND uid LIKE :prefix',
    [':user_id' => $userId, ':prefix' => 'lifecycle-%']
);
if ($count !== 4) {
    throw new RuntimeException('Folder descendants were not soft-deleted atomically');
}
if (is_file($fileOne) || is_file($fileTwo)) {
    throw new RuntimeException('Physical cleanup did not run after durable soft-delete');
}
if ($quotaService->usedBytes($userId) !== 0) {
    throw new RuntimeException('Soft-deleted descendants must not count toward storage quota');
}

$missingPath = $fixtureRoot . '/missing.txt';
$missingId = $insertItem([
    'uid' => 'lifecycle-missing', 'parent_id' => null, 'name' => 'missing', 'type' => 'file',
    'mime_type' => 'text/plain', 'size' => 1, 'path' => $missingPath, 'extension' => 'txt',
]);
$leftoverPath = $fixtureRoot . '/leftover.txt';
file_put_contents($leftoverPath, 'leftover');
$leftoverId = $insertItem([
    'uid' => 'lifecycle-leftover', 'parent_id' => null, 'name' => 'leftover', 'type' => 'file',
    'mime_type' => 'text/plain', 'size' => 8, 'path' => $leftoverPath, 'extension' => 'txt', 'is_deleted' => 1,
]);
$externalPath = $externalRoot . '/keep.txt';
file_put_contents($externalPath, 'keep');
$blockedId = $insertItem([
    'uid' => 'lifecycle-blocked', 'parent_id' => null, 'name' => 'blocked', 'type' => 'file',
    'mime_type' => 'text/plain', 'size' => 4, 'path' => $externalPath, 'extension' => 'txt', 'is_deleted' => 1,
]);

$report = $service->reconcile(false);
if (!in_array($missingId, $report['active_missing'], true)) {
    throw new RuntimeException('Reconciliation did not report active metadata with missing physical file');
}
if (!in_array($leftoverId, $report['deleted_leftovers'], true)) {
    throw new RuntimeException('Reconciliation did not report deleted metadata with leftover physical file');
}
if (!in_array($blockedId, $report['blocked_paths'], true)) {
    throw new RuntimeException('Reconciliation did not block path outside managed storage');
}

$cleanup = $service->reconcile(true);
if ($cleanup['deleted_cleaned'] < 1 || is_file($leftoverPath)) {
    throw new RuntimeException('Reconciliation cleanup did not remove safe deleted leftover');
}
if (!is_file($externalPath)) {
    throw new RuntimeException('Reconciliation removed a file outside managed storage');
}

$db->execute('DELETE FROM users WHERE id = :id', [':id' => $userId]);
@unlink($externalPath);
@rmdir($externalRoot);

echo "File Manager recursive delete/reconciliation/quota contract: OK\n";
