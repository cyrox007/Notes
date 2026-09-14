<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$errors = [];

$require = static function (string $relative, string $reason) use ($root, &$errors): void {
    if (!is_file($root . '/' . $relative)) {
        $errors[] = "missing {$relative} — {$reason}";
    }
};

// 0.12 durable baseline remains mandatory for every later release.
$require('.github/workflows/profile-browser-lifecycle.yml', 'Profile browser lifecycle is not integrated');
$require('.github/workflows/admin-browser-lifecycle.yml', 'Admin browser lifecycle is not integrated');
$require('.github/workflows/fault-injection-browser.yml', 'browser-visible DB/storage fault injection is not integrated');
$require('assets/js/feedback.js', 'shared feedback layer is not integrated');
$require('app/services/ListQuery.php', 'bounded list-query contract is not integrated');
$require('.github/release-governance.json', 'machine-readable merge governance is not integrated');

// 0.13 product UX closure.
$require('assets/js/tasks-kanban.js', 'Tasks kanban flow is not integrated');
$require('assets/js/notes-editor-013.js', 'Notes 0.13 editor is not integrated');
$require('app/views/notes_page/editor-013.css', 'Notes 0.13 editor styling is not integrated');
$require('app/services/ProfileMetricsService.php', 'Profile workspace metrics are not integrated');
$require('app/views/profile_page/metrics.css', 'Profile workspace metrics UI is not integrated');
$require('assets/js/file-manager-polish.js', 'File Manager 0.13 workspace polish is not integrated');
$require('assets/js/file-manager-drop-upload.js', 'File Manager drag/drop upload is not integrated');
$require('assets/js/messenger-connection-ux.js', 'Messenger reconnect/offline UX is not integrated');
$require('.github/workflows/installer-0.13-contract.yml', '0.13 installer schema regression is not integrated');

$roadmapPath = $root . '/docs/PRODUCT_UX_0.13.md';
if (!is_file($roadmapPath)) {
    $errors[] = 'missing docs/PRODUCT_UX_0.13.md';
} else {
    $roadmap = (string) file_get_contents($roadmapPath);
    foreach ([
        'Статус релиза: **закрыт**',
        '## 0.14 beta backlog',
        'Messenger reconnect/offline UX',
        'Profile metrics',
    ] as $marker) {
        if (!str_contains($roadmap, $marker)) {
            $errors[] = "0.13 roadmap marker missing: {$marker}";
        }
    }
}

$versionPath = $root . '/core/Version.php';
if (is_file($versionPath)) {
    $version = (string) file_get_contents($versionPath);
    if (!str_contains($version, "public const VERSION = '0.13.0-alpha';")) {
        $errors[] = 'final release commit must set Core\\Version::VERSION to 0.13.0-alpha';
    }
    if (!str_contains($version, 'public const VERSION_CODE = 1300;')) {
        $errors[] = 'final release commit must set VERSION_CODE to 1300';
    }
    if (!str_contains($version, "public const RELEASE_DATE = '2026-09-14';")) {
        $errors[] = 'final release commit must set RELEASE_DATE to 2026-09-14';
    }
} else {
    $errors[] = 'missing core/Version.php';
}

$readme = is_file($root . '/README.md') ? (string) file_get_contents($root . '/README.md') : '';
if (!str_contains($readme, '**Версия:** `0.13.0-alpha`')) {
    $errors[] = 'README.md must advertise 0.13.0-alpha';
}
if (!str_contains($readme, 'compatibility upgrade SQL')) {
    $errors[] = 'README.md must preserve the canonical-schema + compatibility-upgrade DB contract';
}

$changelog = is_file($root . '/CHANGELOG.md') ? (string) file_get_contents($root . '/CHANGELOG.md') : '';
if (!str_contains($changelog, '## 0.13.0-alpha — 2026-09-14')) {
    $errors[] = 'CHANGELOG.md must contain the 0.13.0-alpha release section';
}

if ($errors !== []) {
    fwrite(STDERR, "Workspace 0.13 alpha readiness: BLOCKED\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Workspace 0.13 alpha readiness: OK\n");
