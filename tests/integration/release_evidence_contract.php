<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function releaseEvidenceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] release evidence contract: {$message}\n");
        exit(1);
    }
}

function releaseEvidenceText(string $root, string $path): string
{
    $full = $root . '/' . $path;
    releaseEvidenceAssert(is_file($full), "missing file {$path}");
    $text = file_get_contents($full);
    releaseEvidenceAssert(is_string($text), "cannot read {$path}");
    return $text;
}

$browser = releaseEvidenceText($root, 'tests/e2e/release-browser-evidence.mjs');
foreach ([
    "import { chromium, firefox, webkit } from 'playwright'",
    "'chromium-desktop'",
    "'firefox-desktop'",
    "'webkit-desktop'",
    "'chromium-mobile-390'",
    "'webkit-mobile-412'",
    "hasTouch: true",
    "isMobile: true",
    "sidebar--open",
    "aria-expanded",
    "scrollWidth",
    "PHPSESSID=",
    "uniqueSessions.length !== scenarios.length",
    "authenticated_sessions: uniqueSessions.length",
] as $marker) {
    releaseEvidenceAssert(str_contains($browser, $marker), "browser evidence missing {$marker}");
}

$load = releaseEvidenceText($root, 'tests/e2e/release-load-soak.mjs');
foreach ([
    "'600'",
    "'12'",
    "'45'",
    "'4'",
    "'2500'",
    "allowed_errors: 0",
    "redirect: 'manual'",
    "auth_redirect",
    "PHPSESSID=",
    "sessionCookies.length < 2",
    "sessionCookies[index % sessionCookies.length]",
    "authenticated_sessions: sessionCookies.length",
    "process.exit(1)",
] as $marker) {
    releaseEvidenceAssert(str_contains($load, $marker), "load/soak evidence missing {$marker}");
}

$workflow = releaseEvidenceText($root, '.github/workflows/release-evidence.yml');
foreach ([
    "npx playwright install --with-deps chromium firefox webkit",
    "node tests/e2e/release-browser-evidence.mjs",
    "node tests/e2e/release-load-soak.mjs",
    "php bin/healthcheck.php --json",
    "actions/upload-artifact@v4",
    "release-browser-evidence.json",
    "release-load-evidence.json",
    "release-health.json",
    "Fatal error|Uncaught|Parse error",
    "PHP_CLI_SERVER_WORKERS=8",
    "BASE_PATH=/workspace",
] as $marker) {
    releaseEvidenceAssert(str_contains($workflow, $marker), "release evidence workflow missing {$marker}");
}

$docs = releaseEvidenceText($root, 'docs/RELEASE_EVIDENCE.md');
foreach ([
    'five independent authenticated PHP sessions',
    '600 requests with concurrency 12',
    '45 seconds with concurrency 4',
    'maximum p95 latency: 2500 ms',
    'no open P0/P1 data-loss defects',
    'no open P0/P1 security defects',
    'does not fabricate human beta evidence',
] as $marker) {
    releaseEvidenceAssert(str_contains($docs, $marker), "release evidence docs missing {$marker}");
}

$releaseGate = releaseEvidenceText($root, '.github/workflows/release-gate.yml');
releaseEvidenceAssert(
    str_contains($releaseGate, 'php tests/integration/release_evidence_contract.php'),
    'Stable release gate does not run release evidence contract'
);

fwrite(STDOUT, "[OK] release browser, mobile, load/soak and acceptance evidence contract\n");
