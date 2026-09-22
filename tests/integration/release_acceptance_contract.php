<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function releaseAcceptanceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[ОШИБКА] контракт release acceptance: {$message}\n");
        exit(1);
    }
}

function releaseAcceptanceText(string $root, string $path): string
{
    $full = $root . '/' . $path;
    releaseAcceptanceAssert(is_file($full), "отсутствует файл {$path}");
    $text = file_get_contents($full);
    releaseAcceptanceAssert(is_string($text), "не удалось прочитать {$path}");
    return $text;
}

$readme = releaseAcceptanceText($root, 'README.md');
releaseAcceptanceAssert(
    !str_contains($readme, 'Пока остаётся'),
    'README всё ещё содержит устаревшее описание CSP debt'
);
releaseAcceptanceAssert(
    str_contains($readme, '## 1.0 release readiness'),
    'README не содержит актуальный раздел release readiness'
);
foreach ([
    'nonce-based CSP без',
    'explicit retention/permanent-purge contract',
    'structured security observability',
    'GitHub branch protection/ruleset',
    'production license/update Ed25519 keypairs',
    'cross-browser/mobile + load/soak release evidence',
] as $marker) {
    releaseAcceptanceAssert(str_contains($readme, $marker), "README release readiness не содержит marker {$marker}");
}

$doc = releaseAcceptanceText($root, 'docs/RELEASE_ACCEPTANCE.md');
foreach ([
    'Gate A — контракт репозитория и исходников',
    'Gate B — управление репозиторием',
    'Gate C — production trust roots',
    'Gate D — автоматические доказательства exact-head',
    'Gate E — эксплуатационная приёмка',
    'Gate F — дефекты и ручная приёмка',
    'visual acceptance',
    'OSPanel 5.2.2',
    'Gate G — неизменяемый артефакт и подпись',
    'Gate H — финальный merge и tag',
    'v1.0.2',
    'нет открытых P0/P1 дефектов с риском потери данных',
    'нет открытых P0/P1 дефектов безопасности',
    'работает только как сборка',
    'не должен создавать или обновлять публичный GitHub Release',
] as $marker) {
    releaseAcceptanceAssert(str_contains($doc, $marker), "runbook release acceptance не содержит marker {$marker}");
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
    releaseAcceptanceAssert(str_contains($preflight, $marker), "release preflight не содержит marker {$marker}");
}

$governance = json_decode(
    releaseAcceptanceText($root, '.github/release-governance.json'),
    true,
    32,
    JSON_THROW_ON_ERROR
);
releaseAcceptanceAssert(($governance['stabilization_branch'] ?? null) === '1.0', 'ветка стабилизации должна быть 1.0');
$requiredChecks = $governance['required_checks'] ?? null;
releaseAcceptanceAssert(is_array($requiredChecks) && $requiredChecks !== [], 'финальный набор required checks отсутствует');
releaseAcceptanceAssert(
    ($governance['stabilization_required_checks'] ?? null) === $requiredChecks,
    '1.0 должна требовать тот же набор checks, что и master'
);
releaseAcceptanceAssert(
    ($governance['release_candidate_required_checks'] ?? null) === $requiredChecks,
    'release candidate check set расходится с branch protection policy'
);
foreach ([
    'one-zero-one-upgrade-rollback',
    'browser-wss-e2e',
    'release-evidence',
    'windows-contract (8.1)',
    'windows-contract (8.3)',
    'messenger-realtime-fallback',
] as $requiredCheck) {
    releaseAcceptanceAssert(
        in_array($requiredCheck, $requiredChecks, true),
        "в обязательном наборе отсутствует {$requiredCheck}"
    );
}

$releaseGate = releaseAcceptanceText($root, '.github/workflows/release-gate.yml');
releaseAcceptanceAssert(
    str_contains($releaseGate, 'php tests/integration/release_acceptance_contract.php'),
    'Stable release gate не запускает release acceptance contract'
);
releaseAcceptanceAssert(
    str_contains($releaseGate, 'php bin/release_acceptance.php --json'),
    'Stable release gate не запускает non-strict release preflight'
);

fwrite(STDOUT, "[OK] финальный контракт release acceptance 1.0.2 выполнен\n");
