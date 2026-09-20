<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function retentionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] retention contract: {$message}\n");
        exit(1);
    }
}

function retentionText(string $root, string $path): string
{
    $full = $root . '/' . $path;
    retentionAssert(is_file($full), "missing file {$path}");
    $content = file_get_contents($full);
    retentionAssert(is_string($content), "cannot read {$path}");
    return $content;
}

$manifest = json_decode(retentionText($root, 'database/migrations/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
$migrations = $manifest['migrations'] ?? [];
retentionAssert(is_array($migrations), 'canonical migration list missing');

$expectedMigrations = [
    '20260918_notes_retention_timestamps.sql',
    '20260918_messenger_retention_timestamps.sql',
];
foreach ($expectedMigrations as $migration) {
    retentionAssert(in_array($migration, $migrations, true), "canonical manifest missing {$migration}");
    $sql = retentionText($root, 'database/migrations/' . $migration);
    retentionAssert(str_contains($sql, 'ADD COLUMN deleted_at DATETIME DEFAULT NULL'), "{$migration} lacks deleted_at");
    retentionAssert(str_contains($sql, 'SET deleted_at = CURRENT_TIMESTAMP'), "{$migration} lacks upgrade-time retention backfill");
}

foreach ([
    'notes' => '20260918_notes_retention_timestamps.sql',
    'messenger' => '20260918_messenger_retention_timestamps.sql',
] as $module => $migration) {
    $moduleManifest = json_decode(
        retentionText($root, "modules/{$module}/module.json"),
        true,
        32,
        JSON_THROW_ON_ERROR
    );
    $owned = $moduleManifest['database']['migrations'] ?? [];
    retentionAssert(
        in_array('database/migrations/' . $migration, $owned, true),
        "{$module} manifest does not own {$migration}"
    );
}

$notesSchema = retentionText($root, 'database/notes_schema.sql');
retentionAssert(
    preg_match('/CREATE TABLE IF NOT EXISTS .*note_attachments[\\s\\S]*?deleted_at.*DATETIME DEFAULT NULL/s', $notesSchema) === 1,
    'fresh Notes schema lacks attachment deleted_at'
);
retentionAssert(
    str_contains($notesSchema, 'idx_note_attachments_deleted'),
    'fresh Notes schema lacks retention index'
);

$messengerSchema = retentionText($root, 'database/messenger_module_schema.sql');
retentionAssert(
    preg_match('/CREATE TABLE IF NOT EXISTS .*messenger_attachments[\\s\\S]*?deleted_at.*DATETIME DEFAULT NULL/s', $messengerSchema) === 1,
    'fresh Messenger schema lacks attachment deleted_at'
);
retentionAssert(
    str_contains($messengerSchema, 'idx_messenger_attachments_deleted'),
    'fresh Messenger schema lacks retention index'
);

$noteAttachmentController = retentionText($root, 'modules/notes/controllers/NoteAttachmentController.php');
retentionAssert(
    str_contains($noteAttachmentController, 'SET is_deleted = 1, deleted_at = CURRENT_TIMESTAMP'),
    'direct Note attachment delete does not timestamp retention'
);

$noteController = retentionText($root, 'modules/notes/controllers/NoteController.php');
retentionAssert(
    str_contains($noteController, 'UPDATE note_attachments SET is_deleted = 1, deleted_at = CURRENT_TIMESTAMP'),
    'Note delete cascade does not timestamp attachments'
);

$messengerCleanup = retentionText($root, 'modules/messenger/services/MessengerMediaCleanupService.php');
retentionAssert(
    str_contains($messengerCleanup, 'SET is_deleted = 1, deleted_at = CURRENT_TIMESTAMP'),
    'Messenger orphan cleanup does not timestamp soft-delete'
);
retentionAssert(
    str_contains($messengerCleanup, 'SET is_deleted = 0, deleted_at = NULL'),
    'Messenger orphan cleanup retry does not clear retention timestamp'
);

$service = retentionText($root, 'app/services/RetentionService.php');
foreach ([
    'retention.purge_completed',
    'retention.account_blocked',
    'retention.account_purged',
    'owned_shared_messenger_group',
    'owned_shared_task_board',
    'administrative_role_assignment',
    'outside the application tree',
    'a.deleted_at >= :attachment_cutoff',
] as $marker) {
    retentionAssert(str_contains($service, $marker), "RetentionService missing safety marker {$marker}");
}
$filesystemCleanupPosition = strpos($service, '$this->removePaths($paths, $result)');
$userDeletePosition = strpos($service, 'DELETE FROM users WHERE id = :id');
retentionAssert($filesystemCleanupPosition !== false, 'account filesystem cleanup marker missing');
retentionAssert($userDeletePosition !== false, 'irreversible user DELETE marker missing');
retentionAssert(
    $filesystemCleanupPosition < $userDeletePosition,
    'account filesystem cleanup must occur before irreversible user DELETE'
);

$cli = retentionText($root, 'bin/retention.php');
retentionAssert(str_contains($cli, "'apply'"), 'retention CLI lacks apply flag');
retentionAssert(str_contains($cli, "'yes'"), 'retention CLI lacks explicit confirmation flag');
retentionAssert(
    str_contains($cli, 'Permanent purge requires explicit --yes confirmation'),
    'retention CLI does not fail closed without confirmation'
);
retentionAssert(
    str_contains($cli, '$service->preview'),
    'retention CLI default preview path missing'
);

$env = retentionText($root, 'default.env');
foreach (['RETENTION_SOFT_DELETE_DAYS=30', 'RETENTION_DEACTIVATED_ACCOUNT_DAYS=30'] as $marker) {
    retentionAssert(str_contains($env, $marker), "default.env missing {$marker}");
}

$docs = retentionText($root, 'docs/OPERATIONS.md');
foreach ([
    '## Retention and permanent purge',
    'php bin/retention.php --apply --yes --json',
    'backup/restore drill',
    'Permanent account deletion exists only in the retention CLI',
] as $marker) {
    retentionAssert(str_contains($docs, $marker), "operations runbook missing {$marker}");
}

fwrite(STDOUT, "[OK] retention timestamps, ownership, purge safety and runbook contract\n");
