<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflowPath = $root . '/.github/workflows/hosting-package.yml';

function hostingPackageAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] hosting package source contract: {$message}\n");
        exit(1);
    }
}

hostingPackageAssert(is_file($workflowPath), 'hosting-package.yml is missing');
$workflow = file_get_contents($workflowPath);
hostingPackageAssert(is_string($workflow), 'hosting-package.yml cannot be read');
$workflow = str_replace("\r\n", "\n", $workflow);

$moduleSocket = 'modules/messenger/socket/NativeMessengerServer.php';
$legacySocket = 'app/socket/NativeMessengerServer.php';

hostingPackageAssert(
    substr_count($workflow, $moduleSocket) >= 2,
    'release packaging must build and inspect the module-owned Messenger socket runtime'
);
hostingPackageAssert(
    !str_contains($workflow, 'test -f "$ROOT/' . $legacySocket . '"'),
    'release packaging still requires the removed pre-isolation Messenger socket path'
);
hostingPackageAssert(
    !str_contains($workflow, "grep -F '{$legacySocket}'"),
    'release bundle inspection still expects the removed pre-isolation Messenger socket path'
);
hostingPackageAssert(
    str_contains($workflow, 'test ! -e "$ROOT/' . $legacySocket . '"'),
    'release packaging does not assert that the pre-isolation Messenger socket path stays absent'
);
hostingPackageAssert(
    str_contains($workflow, "grep -R -F 'Workerman\\\\' modules/messenger/socket ws_server core.php"),
    'vendor-free runtime scan does not inspect the module-owned Messenger socket runtime'
);

hostingPackageAssert(
    str_contains($workflow, "permissions:\n  contents: read"),
    'hosting package workflow must use read-only repository permissions'
);
hostingPackageAssert(
    str_contains($workflow, 'workflow_dispatch:')
        && str_contains($workflow, 'version:')
        && str_contains($workflow, 'EXPECTED_VERSION=')
        && str_contains($workflow, 'test "$VERSION" = "$EXPECTED_VERSION"'),
    'manual release-candidate build must use the exact application release version'
);
hostingPackageAssert(
    str_contains($workflow, 'Record immutable bundle checksum')
        && str_contains($workflow, 'sha256sum "$BUNDLE_FILE"')
        && str_contains($workflow, 'BUNDLE_CHECKSUM=')
        && str_contains($workflow, 'SOURCE_SHA_FILE=')
        && str_contains($workflow, 'BUNDLE_SOURCE_SHA='),
    'release packaging must record the exact ZIP SHA-256 and source SHA beside the workflow artifact'
);

hostingPackageAssert(
    str_contains($workflow, 'bootstrap-1.0.2-updater.php')
        && str_contains($workflow, 'UPDATE_102_BOOTSTRAP=')
        && str_contains($workflow, 'UPDATE_102_BOOTSTRAP_CHECKSUM=')
        && str_contains($workflow, '${{ env.UPDATE_102_BOOTSTRAP }}')
        && str_contains($workflow, '${{ env.UPDATE_102_BOOTSTRAP_CHECKSUM }}'),
    'релизный artifact должен содержать отдельный bootstrap 1.0.2 и его SHA-256'
);
hostingPackageAssert(
    str_contains($workflow, "--exclude 'tests'")
        && str_contains($workflow, "--exclude 'tools'"),
    'customer release bundle must exclude tests and internal tooling'
);
hostingPackageAssert(
    str_contains($workflow, 'Customer bundle must not contain tests/ or internal tools/'),
    'release ZIP inspection must reject tests or internal tools'
);

hostingPackageAssert(
    substr_count($workflow, 'bin/update_retention.php') >= 3
        && substr_count($workflow, 'core/UpdateArtifactCleaner.php') >= 3,
    '1.0.4 release bundle must lint, require and inspect updater retention runtime'
);

hostingPackageAssert(
    !str_contains($workflow, 'gh release create')
        && !str_contains($workflow, 'gh release upload')
        && !str_contains($workflow, 'contents: write'),
    'hosting package workflow must not publish the ZIP directly'
);

foreach ([
    "--exclude '.env'",
    "--exclude 'vendor'",
    "--exclude 'tests'",
    "--exclude 'tools'",
    "--exclude '*.license-secret'",
    "--exclude '*.update-secret'",
    'Verify tag matches application version',
    'EXPECTED_TAG="v$(php -r',
] as $marker) {
    hostingPackageAssert(
        str_contains($workflow, $marker),
        "release package safety marker is missing: {$marker}"
    );
}

fwrite(STDOUT, "[OK] hosting package source contract\n");
