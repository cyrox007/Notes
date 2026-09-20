<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/ModuleManifest.php';
require_once $root . '/core/DatabaseOwnership.php';

use Core\DatabaseOwnership;

function ownershipAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

/** @param list<string> $moduleIds */
function ownershipFixture(string $root, array $moduleIds): string
{
    $tmp = sys_get_temp_dir() . '/notes-db-ownership-' . bin2hex(random_bytes(6));
    if (!mkdir($tmp . '/modules', 0777, true) && !is_dir($tmp . '/modules')) {
        throw new RuntimeException('Cannot create ownership fixture');
    }
    foreach ($moduleIds as $id) {
        $target = $tmp . '/modules/' . $id;
        if (!mkdir($target, 0777, true) && !is_dir($target)) {
            throw new RuntimeException('Cannot create module fixture: ' . $id);
        }
        if (!copy($root . '/modules/' . $id . '/module.json', $target . '/module.json')) {
            throw new RuntimeException('Cannot copy module manifest: ' . $id);
        }
    }
    return $tmp;
}

function removeFixture(string $path): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

$canonical = json_decode((string) file_get_contents($root . '/database/migrations/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
ownershipAssert(is_array($canonical['migrations'] ?? null), 'canonical migration manifest is readable');

$full = DatabaseOwnership::fromPackageRoot($root);
ownershipAssert(in_array('users', $full->tables(), true), 'core owns users');
ownershipAssert(in_array('system_settings', $full->tables(), true), 'core owns system_settings');
ownershipAssert(in_array('dialogs', $full->tables(), true), 'Messenger tables are included in full package');
ownershipAssert(in_array('notes', $full->tables(), true), 'Notes tables are included in full package');
ownershipAssert(in_array('user_storage_quotas', $full->tables(), true), 'Files quota is included in full package');
ownershipAssert(in_array('database/core_identity_schema.sql', $full->schemaFiles(), true), 'core identity schema is canonical');
ownershipAssert(in_array('database/audit_schema.sql', $full->schemaFiles(), true), 'core audit schema is canonical');
ownershipAssert(in_array('database/file_storage_quota_schema.sql', $full->schemaFiles(), true), 'Files owns quota fresh schema');
ownershipAssert(
    $full->migrationNamesInCanonicalOrder($canonical['migrations']) === $canonical['migrations'],
    'full packaged composition preserves the canonical migration order'
);

$coreFixture = ownershipFixture($root, []);
try {
    $coreOnly = DatabaseOwnership::fromPackageRoot($coreFixture);
    ownershipAssert($coreOnly->tables() === [
        'users', 'system_settings', 'roles', 'permissions', 'role_permissions',
        'user_roles', 'role_module_policies', 'module_lifecycle', 'user_action_log',
    ], 'core-only package requires only platform tables');
    ownershipAssert(!in_array('dialogs', $coreOnly->tables(), true), 'core-only does not require Messenger tables');
    ownershipAssert(!in_array('user_storage_quotas', $coreOnly->tables(), true), 'core-only does not require Files quota');
} finally {
    removeFixture($coreFixture);
}

$notesFixture = ownershipFixture($root, ['notes']);
try {
    $notesOnly = DatabaseOwnership::fromPackageRoot($notesFixture);
    ownershipAssert(in_array('notes', $notesOnly->tables(), true), 'Notes package owns Notes tables');
    ownershipAssert(!in_array('tasks', $notesOnly->tables(), true), 'Notes package does not require Tasks tables');
    ownershipAssert(!in_array('dialogs', $notesOnly->tables(), true), 'Notes package does not require Messenger tables');
} finally {
    removeFixture($notesFixture);
}

$profileFixture = ownershipFixture($root, ['profile']);
try {
    $profileOnly = DatabaseOwnership::fromPackageRoot($profileFixture);
    ownershipAssert(in_array('user_fields', $profileOnly->tables(), true), 'Profile owns user_fields');
    ownershipAssert(
        !in_array('20260914_profile_publication.sql', $profileOnly->migrationNamesInCanonicalOrder($canonical['migrations']), true),
        'cross-module publication migration is excluded without Notes/Tasks/Files'
    );
} finally {
    removeFixture($profileFixture);
}

echo "Database ownership contract: OK\n";
