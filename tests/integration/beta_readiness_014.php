<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$errors = [];

$require = static function (string $relative, string $reason) use ($root, &$errors): void {
    if (!is_file($root . '/' . $relative)) {
        $errors[] = "missing {$relative} — {$reason}";
    }
};

foreach ([
    'core/SessionSecurity.php' => 'session hardening is missing',
    'core/RedirectPolicy.php' => 'redirect policy is missing',
    'core/ModuleManifest.php' => 'module manifest contract is missing',
    'core/ModuleRegistry.php' => 'module registry is missing',
    'core/ModuleLifecycleStore.php' => 'persisted module lifecycle is missing',
    'core/WebSocketEndpoint.php' => 'canonical WebSocket endpoint contract is missing',
    'bin/ws_doctor.php' => 'WebSocket deployment diagnostics are missing',
    'database/module_lifecycle_schema.sql' => 'canonical module lifecycle schema is missing',
    'database/migrations/20260915_module_lifecycle.sql' => 'module lifecycle compatibility migration is missing',
    'docs/CORE_SECURITY_AUDIT_0.14.md' => 'core security audit is missing',
    'docs/MODULE_PLATFORM_0.14.md' => 'module platform contract is missing',
    'docs/BETA_HARDENING_0.14.md' => 'beta hardening roadmap is missing',
    'docs/OPEN_SERVER_WEBSOCKET.md' => 'Open Server WebSocket deployment guide is missing',
    'docs/DEPLOYMENT_COMPATIBILITY.md' => 'deployment compatibility matrix is missing',
    'docs/releases/v0.14.0-beta.3.md' => 'curated beta.3 release notes are missing',
    '.github/workflows/core-security-phase2.yml' => 'core security regression gate is missing',
    '.github/workflows/module-platform-contract.yml' => 'module platform regression gate is missing',
    '.github/workflows/module-lifecycle-contract.yml' => 'module lifecycle regression gate is missing',
    '.github/workflows/rbac-foundation.yml' => 'RBAC foundation gate is missing',
    '.github/workflows/rbac-enforcement.yml' => 'RBAC enforcement gate is missing',
    '.github/workflows/browser-wss-e2e.yml' => 'HTTPS/WSS browser gate is missing',
    '.github/workflows/websocket-deployment-contract.yml' => 'WebSocket deployment regression gate is missing',
    '.github/workflows/registration-policy.yml' => 'registration/provisioning regression gate is missing',
    '.github/workflows/registration-browser.yml' => 'registration browser lifecycle gate is missing',
] as $file => $reason) {
    $require($file, $reason);
}

$versionPath = $root . '/core/Version.php';
$version = is_file($versionPath) ? (string) file_get_contents($versionPath) : '';
foreach ([
    "public const VERSION = '0.14.0-beta.3';",
    "public const STATUS = 'beta';",
    'public const VERSION_CODE = 1403;',
    "public const RELEASE_DATE = '2026-09-15';",
] as $marker) {
    if (!str_contains($version, $marker)) {
        $errors[] = "version marker missing: {$marker}";
    }
}

$readme = is_file($root . '/README.md') ? (string) file_get_contents($root . '/README.md') : '';
foreach (['**Версия:** `0.14.0-beta.3`', '`1.0.0` stable', '27 обязательных таблиц'] as $marker) {
    if (!str_contains($readme, $marker)) {
        $errors[] = "README beta marker missing: {$marker}";
    }
}

$changelog = is_file($root . '/CHANGELOG.md') ? (string) file_get_contents($root . '/CHANGELOG.md') : '';
if (!str_contains($changelog, '## 0.14.0-beta.3 — 2026-09-15')) {
    $errors[] = 'CHANGELOG beta.3 release section is missing';
}
if (!str_contains($changelog, 'Основная цель после первого beta — `1.0.0` stable')) {
    $errors[] = 'CHANGELOG must point Unreleased at 1.0.0 stable';
}

$packageWorkflow = is_file($root . '/.github/workflows/hosting-package.yml')
    ? (string) file_get_contents($root . '/.github/workflows/hosting-package.yml')
    : '';
foreach (['--prerelease', 'EXPECTED_TAG', 'docs/releases/${GITHUB_REF_NAME}.md'] as $marker) {
    if (!str_contains($packageWorkflow, $marker)) {
        $errors[] = "hosting release contract missing: {$marker}";
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Workspace 0.14 beta readiness: BLOCKED\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Workspace 0.14 beta readiness: OK\n");
