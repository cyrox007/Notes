<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/core/Version.php';

use Core\Version;

$options = getopt('', [
    'strict',
    'json',
    'branch-protection-confirmed',
    'ci-green',
    'release-evidence-green',
    'backup-restore-current',
    'beta4-drill-green',
    'p0p1-clear',
    'trust-canaries-green',
    'artifact-signed',
    'help',
]);

if (isset($options['help'])) {
    echo "Usage: php bin/release_acceptance.php [--json] [--strict <operator attestations>]\n";
    echo "Non-strict mode reports source failures plus pending external release gates.\n";
    echo "Strict mode exits non-zero until every production/manual gate is explicitly confirmed.\n";
    exit(0);
}

$strict = isset($options['strict']);
$json = isset($options['json']);

$checks = [];
$pending = [];
$failures = [];

$record = static function (string $name, bool $ok, string $details = '') use (&$checks, &$failures): void {
    $checks[$name] = ['ok' => $ok, 'details' => $details];
    if (!$ok) {
        $failures[] = $name;
    }
};

$record(
    'release_identity',
    Version::VERSION === '1.0.0'
        && Version::VERSION_CODE === 10000
        && Version::STATUS === 'stable',
    Version::VERSION . ' / ' . Version::VERSION_CODE . ' / ' . Version::STATUS
);

$governancePath = $root . '/.github/release-governance.json';
$governance = is_file($governancePath)
    ? json_decode((string) file_get_contents($governancePath), true)
    : null;
$governanceOk = is_array($governance)
    && ($governance['protected_branch'] ?? null) === 'master'
    && ($governance['stabilization_branch'] ?? null) === '1.0'
    && ($governance['stabilization_required_checks'] ?? null) === ['release-gate'];
$record('governance_source_contract', $governanceOk, 'master + 1.0 / release-gate');

foreach ([
    'README.md',
    'CHANGELOG.md',
    'docs/releases/v1.0.0.md',
    'docs/RELEASE_ACCEPTANCE.md',
    'docs/RELEASE_GOVERNANCE.md',
    'docs/PRODUCTION_TRUST_CEREMONY.md',
    'docs/OPERATIONS.md',
] as $relative) {
    $record(
        'document:' . $relative,
        is_file($root . '/' . $relative),
        $relative
    );
}

$privateMatches = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_contains($path, '/.git/')) {
        continue;
    }
    $name = $file->getFilename();
    if (
        str_ends_with($name, '.license-secret')
        || str_ends_with($name, '.update-secret')
        || preg_match('/^workspace-(?:license|update)-.*\.secret$/D', $name) === 1
    ) {
        $privateMatches[] = ltrim(str_replace(str_replace('\\', '/', $root), '', $path), '/');
    }
}
$record(
    'private_signing_material_absent',
    $privateMatches === [],
    $privateMatches === [] ? 'no forbidden private signing files' : implode(', ', $privateMatches)
);

$decodePublic = static function (string $token): ?string {
    if ($token === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) {
        return null;
    }
    $padding = (4 - (strlen($token) % 4)) % 4;
    $raw = base64_decode(strtr($token, '-_', '+/') . str_repeat('=', $padding), true);
    return is_string($raw) && strlen($raw) === 32 ? $raw : null;
};

$licenseRegistry = require $root . '/config/license_trusted_keys.php';
$updateRegistry = require $root . '/config/update_trusted_keys.php';
$record('license_registry_type', is_array($licenseRegistry), 'public keys only');
$record('update_registry_type', is_array($updateRegistry), 'public keys only');

$trustRootsReady = is_array($licenseRegistry)
    && is_array($updateRegistry)
    && $licenseRegistry !== []
    && $updateRegistry !== [];
$licenseFingerprints = [];
$updateFingerprints = [];

if (is_array($licenseRegistry)) {
    foreach ($licenseRegistry as $id => $token) {
        $raw = is_string($token) ? $decodePublic($token) : null;
        if (!is_string($id) || $raw === null) {
            $record('license_public_key_format', false, 'invalid key id/public key');
            break;
        }
        $licenseFingerprints[$id] = hash('sha256', $raw);
    }
}
if (is_array($updateRegistry)) {
    foreach ($updateRegistry as $id => $token) {
        $raw = is_string($token) ? $decodePublic($token) : null;
        if (!is_string($id) || $raw === null) {
            $record('update_public_key_format', false, 'invalid key id/public key');
            break;
        }
        $updateFingerprints[$id] = hash('sha256', $raw);
    }
}

if ($trustRootsReady) {
    $record(
        'production_trust_domains_independent',
        array_intersect(array_keys($licenseFingerprints), array_keys($updateFingerprints)) === []
            && array_intersect(array_values($licenseFingerprints), array_values($updateFingerprints)) === [],
        'independent ids and Ed25519 key material'
    );
} else {
    $pending[] = 'production_public_trust_roots';
}

$releaseEvidenceFiles = [
    '.github/workflows/release-evidence.yml',
    'tests/e2e/release-browser-evidence.mjs',
    'tests/e2e/release-load-soak.mjs',
    'tests/integration/release_evidence_contract.php',
    'docs/RELEASE_EVIDENCE.md',
];
$releaseEvidenceHarness = true;
foreach ($releaseEvidenceFiles as $relative) {
    if (!is_file($root . '/' . $relative)) {
        $releaseEvidenceHarness = false;
        break;
    }
}
if (!$releaseEvidenceHarness) {
    $pending[] = 'release_evidence_harness';
}

$manualGates = [
    'branch_protection' => 'branch-protection-confirmed',
    'exact_head_ci' => 'ci-green',
    'cross_browser_load_evidence' => 'release-evidence-green',
    'backup_restore' => 'backup-restore-current',
    'beta4_upgrade_rollback' => 'beta4-drill-green',
    'p0_p1_acceptance' => 'p0p1-clear',
    'production_trust_canaries' => 'trust-canaries-green',
    'immutable_artifact_signed' => 'artifact-signed',
];

$attestations = [];
foreach ($manualGates as $gate => $flag) {
    $attestations[$gate] = isset($options[$flag]);
    if (!$attestations[$gate]) {
        $pending[] = $gate;
    }
}

$pending = array_values(array_unique($pending));
sort($pending);
$status = $failures !== [] ? 'fail' : ($pending === [] ? 'ready' : 'pending');

$result = [
    'status' => $status,
    'strict' => $strict,
    'version' => Version::VERSION,
    'version_code' => Version::VERSION_CODE,
    'checks' => $checks,
    'source_failures' => array_values(array_unique($failures)),
    'pending_gates' => $pending,
    'operator_attestations' => $attestations,
    'production_public_trust_roots_present' => $trustRootsReady,
    'release_evidence_harness_present' => $releaseEvidenceHarness,
];

if ($json) {
    echo json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;
} else {
    echo 'Workspace Organizer ' . Version::VERSION . ' release acceptance: ' . strtoupper($status) . PHP_EOL;
    foreach ($checks as $name => $check) {
        echo sprintf("  [%s] %s%s\n", $check['ok'] ? 'OK' : 'FAIL', $name, $check['details'] !== '' ? ' — ' . $check['details'] : '');
    }
    if ($pending !== []) {
        echo "Pending release gates:\n";
        foreach ($pending as $gate) {
            echo '  - ' . $gate . PHP_EOL;
        }
    }
}

if ($failures !== []) {
    exit(1);
}
if ($strict && $pending !== []) {
    exit(3);
}
exit(0);
