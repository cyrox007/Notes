<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflowPath = $root . '/.github/workflows/findability-contract.yml';

function findabilitySourceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] findability source contract: {$message}\n");
        exit(1);
    }
}

findabilitySourceAssert(is_file($workflowPath), 'findability workflow is missing');
$workflow = file_get_contents($workflowPath);
findabilitySourceAssert(is_string($workflow), 'findability workflow cannot be read');

foreach ([
    'modules/notes/controllers/NoteController.php',
    'modules/tasks/controllers/TaskController.php',
    'modules/admin/controllers/AdminController.php',
    'modules/admin/services/AdminUserService.php',
    'app/views/core/base.php',
] as $currentPath) {
    findabilitySourceAssert(
        str_contains($workflow, $currentPath),
        "workflow does not verify current path: {$currentPath}"
    );
}

foreach ([
    'app/controllers/NoteController.php',
    'app/controllers/TaskController.php',
    'app/views/core/base.tpl',
] as $legacyPath) {
    findabilitySourceAssert(
        !str_contains($workflow, $legacyPath),
        "workflow still depends on pre-isolation path: {$legacyPath}"
    );
}

findabilitySourceAssert(
    preg_match("/pull_request:\s*\n\s*branches:\s*\[master,\s*'1\.0'\]/m", $workflow) === 1,
    'findability contract must run during both stabilization and final master PRs'
);

fwrite(STDOUT, "[OK] findability workflow follows isolated runtime paths\n");
