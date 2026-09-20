<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$errors = [];

$require = static function (string $relative, string $reason) use ($root, &$errors): string {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        $errors[] = "missing {$relative} — {$reason}";
        return '';
    }
    $text = file_get_contents($path);
    if (!is_string($text)) {
        $errors[] = "unreadable {$relative} — {$reason}";
        return '';
    }
    return $text;
};

foreach ([
    'database/migrations/20260915_module_lifecycle.sql' => 'Beta4 module lifecycle migration must remain available',
    'database/migrations/20260915_role_module_policies.sql' => 'Beta4 role policy migration must remain available',
    'database/migrations/20260915_shared_task_boards.sql' => 'Beta4 shared task board migration must remain available',
    'modules/admin/services/RoleManagementService.php' => 'current Admin runtime must retain Beta4 role-management behavior',
    'modules/tasks/services/TaskBoardService.php' => 'current Tasks runtime must retain Beta4 shared-board behavior',
    'docs/releases/v0.14.0-beta.4.md' => 'published Beta4 release notes must remain in source history',
    'docs/UPDATER_BETA4_BOOTSTRAP.md' => 'trusted Beta4 to 1.0 bootstrap runbook must remain available',
    'bin/update_bootstrap.php' => 'trusted external Beta4 bootstrap updater must remain available',
    '.github/workflows/beta4-upgrade-rollback-drill.yml' => 'exact Beta4 upgrade/rollback drill must remain available',
] as $file => $reason) {
    $require($file, $reason);
}

$releaseNotes = $require('docs/releases/v0.14.0-beta.4.md', 'Beta4 release identity evidence');
foreach (['0.14.0-beta.4', '2026'] as $marker) {
    if ($releaseNotes !== '' && !str_contains($releaseNotes, $marker)) {
        $errors[] = "Beta4 release notes missing marker: {$marker}";
    }
}

$changelog = $require('CHANGELOG.md', 'historical Beta4 changelog evidence');
if ($changelog !== '' && !str_contains($changelog, '## 0.14.0-beta.4 — 2026-09-15')) {
    $errors[] = 'CHANGELOG Beta4 release section is missing';
}

$tasksSchema = $require('database/tasks_schema.sql', 'Beta4 shared-task schema compatibility');
foreach (['task_boards', 'task_board_members', 'task_board_items', 'task_board_assignees'] as $marker) {
    if ($tasksSchema !== '' && !str_contains($tasksSchema, $marker)) {
        $errors[] = "Tasks canonical schema missing Beta4 compatibility marker: {$marker}";
    }
}

$accessSchema = $require('database/access_control_schema.sql', 'Beta4 role-policy schema compatibility');
if ($accessSchema !== '' && !str_contains($accessSchema, 'role_module_policies')) {
    $errors[] = 'access-control canonical schema is missing role_module_policies';
}

$bootstrapDoc = $require('docs/UPDATER_BETA4_BOOTSTRAP.md', 'Beta4 bootstrap procedure');
foreach (['0.14.0-beta.4', 'rollback'] as $marker) {
    if ($bootstrapDoc !== '' && !str_contains($bootstrapDoc, $marker)) {
        $errors[] = "Beta4 bootstrap documentation missing marker: {$marker}";
    }
}

$drill = $require('.github/workflows/beta4-upgrade-rollback-drill.yml', 'exact Beta4 compatibility drill');
foreach ([
    "v0.14.0-beta.4^{commit}",
    '743d9283f3bb4fca8f536ad133d542a7078af3a9',
    '--expected-source-version=0.14.0-beta.4',
] as $marker) {
    if ($drill !== '' && !str_contains($drill, $marker)) {
        $errors[] = "Beta4 upgrade drill missing immutable compatibility marker: {$marker}";
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Workspace 0.14 Beta4 compatibility baseline: BLOCKED\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Workspace 0.14 Beta4 compatibility baseline: OK\n");
