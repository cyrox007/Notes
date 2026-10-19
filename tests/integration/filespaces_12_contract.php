<?php

declare(strict_types=1);

// Изолированная проверка общего файлового пространства на MySQL 8.4.
$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'Core\\')) {
        return;
    }
    $path = $root . '/core/' . substr($class, 5) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});
require_once $root . '/modules/files/services/FileSpaceAccessService.php';
require_once $root . '/modules/files/services/FileSpaceFolderService.php';

use App\Services\Files\FileSpaceAccessService;
use App\Services\Files\FileSpaceFolderService;
use Core\DatabaseOwnership;
use Core\InstallerDatabaseService;

function filespaceCheck(bool $valid, string $message): void
{
    if ($valid) {
        return;
    }
    fwrite(STDERR, 'Ошибка проверки Filespaces 1.2: ' . $message . PHP_EOL);
    exit(1);
}

$database = (string) getenv('FILESPACES_DBNAME');
filespaceCheck(preg_match('/^fsp_[a-z0-9_]+$/D', $database) === 1,
    'запускать только в изолированной FILESPACES_DBNAME');
$host = (string) (getenv('DBHOST') ?: '127.0.0.1');
$port = (int) (getenv('DBPORT') ?: 3306);
$user = (string) (getenv('DBUSER') ?: 'root');
$password = (string) (getenv('DBPASS') ?: '');
putenv('DBNAME=' . $database);

$ownership = DatabaseOwnership::fromPackageRoot($root);
foreach (['file_spaces','file_space_nodes','file_space_acl','file_space_events'] as $table) {
    filespaceCheck(in_array($table, $ownership->tables(), true), 'не зарегистрирована таблица ' . $table);
}
filespaceCheck(in_array('database/migrations/20261018_file_spaces_foundation.sql',
    $ownership->migrationFiles(), true), 'не зарегистрирована миграция Filespaces');

$schemas = array_map(static fn (string $path): string => $root . '/' . $path, $ownership->schemaFiles());
$installer = new InstallerDatabaseService();
$installer->importSchemas($host, $port, $database, $user, $password, $schemas);
$pdo = $installer->connect($host, $port, $database, $user, $password);
foreach (['fsp-admin','fsp-member','fsp-reader'] as $name) {
    $installer->createAdminUser(
        $pdo, $name, $name . '@example.test', 'filespaces-test-password', 'Test', 'Files'
    );
}
$getId = $pdo->prepare('SELECT id FROM users WHERE username=:username');
$ids = [];
foreach (['fsp-admin','fsp-member','fsp-reader'] as $name) {
    $getId->execute([':username' => $name]);
    $ids[$name] = (int) $getId->fetchColumn();
}
$admin = $ids['fsp-admin'];
$member = $ids['fsp-member'];
$reader = $ids['fsp-reader'];
$regularRole = (int) $pdo->query("SELECT id FROM roles WHERE code='user'")->fetchColumn();
filespaceCheck($regularRole > 0, 'не найдена штатная роль пользователя');

foreach ([$member,$reader] as $id) {
    $pdo->prepare('UPDATE users SET role=888 WHERE id=:id')->execute([':id' => $id]);
    $pdo->prepare('DELETE FROM user_roles WHERE user_id=:id')->execute([':id' => $id]);
    $pdo->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (:id,:role)')
        ->execute([':id' => $id, ':role' => $regularRole]);
}

$access = new FileSpaceAccessService($pdo);
$folders = new FileSpaceFolderService($pdo, $access);
$space = $access->space();
filespaceCheck($space['uid'] === 'global' && $space['owner_user_id'] === null,
    'общие файлы не должны принадлежать администратору');
filespaceCheck(!$access->decision($member, 'global', null, 'view')['allowed'],
    'до выдачи прав глобальный корень закрыт');
filespaceCheck($access->decision($admin, 'global', null, 'manage_acl')['allowed'],
    'администратор сохраняет восстановительный доступ');

$grant = static function (?int $folderId, string $subject, int $subjectId,
    string $permission, string $effect, int $version, string $commandId,
    ?bool $inherit = null) use ($folders, $admin): array {
    return $folders->setRule(
        $admin, $folderId, $subject, $subjectId, $permission,
        $effect, $version, $commandId, $inherit
    );
};
$viewGrant = $grant(null, 'everyone', 0, 'view', 'allow', 1, 'fsp-view-all-001');
filespaceCheck($viewGrant['version'] === 2, 'версия корня должна увеличиться');
$listGrant = $grant(null, 'everyone', 0, 'list', 'allow', 2, 'fsp-list-all-001');
filespaceCheck($listGrant['version'] === 3, 'право просмотра списка настроено');
filespaceCheck($access->decision($member, 'global', null, 'view')['allowed'],
    'пользователь получил просмотр пространства');
filespaceCheck(!$access->decision($member, 'global', null, 'create_folder')['allowed'],
    'просмотр не означает право создания');

$roleGrant = $grant(null, 'role', $regularRole, 'create_folder', 'allow', 3, 'fsp-folder-role-001');
filespaceCheck($roleGrant['version'] === 4, 'ролевая политика изменила версию');
filespaceCheck($access->decision($member, 'global', null, 'create_folder')['allowed'],
    'роль пользователя разрешает создание папок');

$created = $folders->createFolder($member, null, 'Проект', 4, 'fsp-create-folder-001');
$folderId = (int) ($created['folder_id'] ?? 0);
filespaceCheck($folderId > 0 && $created['version'] === 5,
    'папка создана в общем пространстве');
$replay = $folders->createFolder($member, null, 'Проект', 4, 'fsp-create-folder-001');
filespaceCheck($replay['replayed'] === true && $replay['folder_id'] === $folderId,
    'повтор не создаёт дублирующую папку');
$conflict = $folders->createFolder($member, null, 'Вторая папка', 4, 'fsp-create-folder-002');
filespaceCheck($conflict['error'] === 'version_conflict' && $conflict['current_version'] === 5,
    'устаревшая вкладка получает 409 без создания');
filespaceCheck(count($folders->browse($reader)['items']) === 1,
    'читатель видит доступную папку');

$local = $grant($folderId, 'user', $member, 'view', 'allow', 1, 'fsp-local-view-001', false);
filespaceCheck($local['version'] === 2, 'папка получила собственные правила');
filespaceCheck($access->decision($member, 'global', $folderId, 'view')['allowed'],
    'конкретный пользователь видит закрытую папку');
filespaceCheck(!$access->decision($reader, 'global', $folderId, 'view')['allowed'],
    'остальные не видят закрытую папку');
filespaceCheck(count($folders->browse($reader)['items']) === 0,
    'закрытая папка не появляется в списке');

$deny = $grant($folderId, 'everyone', 0, 'view', 'deny', 2, 'fsp-deny-view-001');
filespaceCheck(!$access->decision($member, 'global', $folderId, 'view')['allowed'],
    'явный запрет сильнее разрешения конкретному пользователю');
$denied = false;
try {
    $folders->browse($member, $folderId);
} catch (\DomainException $error) {
    $denied = $error->getCode() === 404;
}
filespaceCheck($denied, 'нельзя открыть запрещённую папку по прямому URL');

$allowAgain = $grant($folderId, 'everyone', 0, 'view', 'remove', 3, 'fsp-remove-deny-001');
filespaceCheck($allowAgain['version'] === 4, 'запрет удалён');
$author = $grant($folderId, 'author', 0, 'rename_own', 'allow', 4, 'fsp-author-001');
filespaceCheck($access->decision($member, 'global', $folderId, 'rename_own')['allowed'],
    'автору доступно право управления своим объектом');
filespaceCheck(!$access->decision($reader, 'global', $folderId, 'rename_own')['allowed'],
    'чужие объекты нельзя изменять по правам автора');

$unchanged = $pdo->query('SELECT COUNT(*) FROM user_files')->fetchColumn();
filespaceCheck((int) $unchanged === 0, 'личные файлы не должны создаваться миграцией Filespaces');
$events = (int) $pdo->query('SELECT COUNT(*) FROM file_space_events')->fetchColumn();
filespaceCheck($events >= 7, 'значимые операции записаны в журнал');

require __DIR__ . '/filespaces_transfers_12_scenarios.php';
require __DIR__ . '/filespaces_operations_12_scenarios.php';
require __DIR__ . '/filespaces_admin_12_scenarios.php';
require __DIR__ . '/filespaces_purge_12_scenarios.php';
require __DIR__ . '/filespaces_pagination_12_scenarios.php';
require __DIR__ . '/filespaces_bulk_load_12_scenarios.php';
require __DIR__ . '/filespaces_security_12_scenarios.php';

echo "Контракт файловых пространств Files 1.2 выполнен\n";
