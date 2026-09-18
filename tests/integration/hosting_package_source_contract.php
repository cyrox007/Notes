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

foreach ([
    "--exclude '.env'",
    "--exclude 'vendor'",
    "--exclude 'tools/vendor-license'",
    "--exclude 'tools/vendor-update'",
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
