<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$expected = [
    '.github/workflows/updater-transaction-preflight.yml',
    '.github/workflows/admin-update-ui.yml',
    '.github/workflows/security-observability.yml',
    '.github/workflows/websocket-deployment-contract.yml',
    '.github/workflows/module-platform-contract.yml',
    '.github/workflows/updater-remote-delivery.yml',
    '.github/workflows/signed-updater-foundation.yml',
    '.github/workflows/updater-live-apply.yml',
    '.github/workflows/retention-purge.yml',
    '.github/workflows/release-gate.yml',
    '.github/workflows/beta4-upgrade-rollback-drill.yml',
    '.github/workflows/updater-release-candidate.yml',
    '.github/workflows/profile-browser-lifecycle.yml',
    '.github/workflows/license-readonly-enforcement.yml',
    '.github/workflows/file-manager-http-integrity.yml',
    '.github/workflows/hosting-installer.yml',
    '.github/workflows/core-security-phase2.yml',
    '.github/workflows/license-trust-operations.yml',
    '.github/workflows/admin-browser-lifecycle.yml',
    '.github/workflows/notes-browser-lifecycle.yml',
    '.github/workflows/license-contract.yml',
    '.github/workflows/updater-transaction-backup.yml',
    '.github/workflows/file-manager-browser-lifecycle.yml',
    '.github/workflows/browser-wss-e2e.yml',
    '.github/workflows/release-evidence.yml',
];

foreach ($expected as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        fwrite(STDERR, "[FAIL] missing release workflow: {$relative}\n");
        exit(1);
    }
    $source = file_get_contents($path);
    if (!is_string($source)) {
        fwrite(STDERR, "[FAIL] cannot read release workflow: {$relative}\n");
        exit(1);
    }
    if (!str_contains($source, 'runs-on: [self-hosted, Linux, X64, wb-ci]')) {
        fwrite(STDERR, "[FAIL] release workflow is not pinned to wb-ci: {$relative}\n");
        exit(1);
    }
    if (str_contains($source, 'runs-on: ubuntu-latest')) {
        fwrite(STDERR, "[FAIL] release workflow still consumes GitHub-hosted minutes: {$relative}\n");
        exit(1);
    }
}

fwrite(STDOUT, "[OK] current 1.0 release workflows target self-hosted wb-ci runner\n");
