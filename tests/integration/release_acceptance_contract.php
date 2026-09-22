<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function releaseAcceptanceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] release acceptance contract: {$message}\n");
        exit(1);
    }
}

function releaseAcceptanceText(string $root, string $path): string
{
    $full = $root . '/' . $path;
    releaseAcceptanceAssert(is_file($full), "missing file {$path}");
    $text = file_get_contents($full);
    releaseAcceptanceAssert(is_string($text), "cannot read {$path}");
    return $text;
}

$readme = releaseAcceptanceText($root, 'README.md');
releaseAcceptanceAssert(
    !str_contains($readme, 'Пока остаётся'),
    'README still contains the obsolete CSP debt statement'
);
releaseAcceptanceAssert(
    str_contains($readme, '## 1.0 release readiness'),
    'README lacks current release-readiness section'
);
foreach ([
    'nonce-based CSP без',
    'explicit retention/permanent-purge contract',
    'structured security observability',
    'GitHub branch protection/ruleset',
    'production license/update Ed25519 keypairs',
    'cross-browser/mobile + load/soak release evidence',
] as $marker) {
    releaseAcceptanceAssert(str_contains($readme, $marker), "README readiness missing {$marker}");
}

$doc = releaseAcceptanceText($root, 'docs/RELEASE_ACCEPTANCE.md');
foreach ([
    'Gate A — repository/source contract',
    'Gate B — repository governance',
    'Gate C — production trust roots',
    'Gate D — exact-head automated evidence',
    'Gate E — operational acceptance',
    'Gate F — defect and human acceptance',
    'visual acceptance',
    'OSPanel 5.2.2',
    'Gate G — immutable artifact and signing',
    'Gate H — final merge and tag',
    'v1.0.2',
    'no open P0/P1 data-loss defects',
    'no open P0/P1 security defects',
    'build-only',
    'must not create or update a public GitHub Release',
] as $marker) {
    releaseAcceptanceAssert(str_contains($doc, $marker), "release acceptance runbook missing {$marker}");
}

$preflight = releaseAcceptanceText($root, 'bin/release_acceptance.php');
foreach ([
    "'strict'",
    "'branch-protection-confirmed'",
    "'ci-green'",
    "'release-evidence-green'",
    "'backup-restore-current'",
    "'operational-acceptance-green'",
    "'visual-acceptance-green'",
    "'ospanel-acceptance-green'",
    "'one-zero-one-drill-green'",
    "'p0p1-clear'",
    "'trust-canaries-green'",
    "'artifact-signed'",
    'production_public_trust_roots',
    'release_evidence_harness',
    'private_signing_material_absent',
    "Version::VERSION === '1.0.2'",
    "Version::STATUS === 'stable'",
    'exit(3)',
] as $marker) {
    releaseAcceptanceAssert(str_contains($preflight, $marker), "release preflight missing {$marker}");
}

$governance = json_decode(
    releaseAcceptanceText($root, '.github/release-governance.json'),
    true,
    32,
    JSON_THROW_ON_ERROR
);
releaseAcceptanceAssert(($governance['stabilization_branch'] ?? null) === '1.0', '1.0 stabilization governance drifted');
releaseAcceptanceAssert(
    ($governance['stabilization_required_checks'] ?? null) === ['release-gate'],
    '1.0 required release gate drifted'
);

$releaseGate = releaseAcceptanceText($root, '.github/workflows/release-gate.yml');
releaseAcceptanceAssert(
    str_contains($releaseGate, 'php tests/integration/release_acceptance_contract.php'),
    'Stable release gate does not execute release acceptance contract'
);
releaseAcceptanceAssert(
    str_contains($releaseGate, 'php bin/release_acceptance.php --json'),
    'Stable release gate does not execute non-strict release preflight'
);

fwrite(STDOUT, "[OK] final 1.0.2 release acceptance contract\n");
