<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function releaseEvidenceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[ОШИБКА] контракт release evidence: {$message}\n");
        exit(1);
    }
}

function releaseEvidenceText(string $root, string $path): string
{
    $full = $root . '/' . $path;
    releaseEvidenceAssert(is_file($full), "отсутствует файл {$path}");
    $text = file_get_contents($full);
    releaseEvidenceAssert(is_string($text), "не удалось прочитать {$path}");
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
    releaseEvidenceAssert(str_contains($browser, $marker), "browser evidence не содержит marker {$marker}");
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
    releaseEvidenceAssert(str_contains($load, $marker), "load/soak evidence не содержит marker {$marker}");
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
    releaseEvidenceAssert(str_contains($workflow, $marker), "workflow release evidence не содержит marker {$marker}");
}

$docs = releaseEvidenceText($root, 'docs/RELEASE_EVIDENCE.md');
foreach ([
    'пять независимых аутентифицированных PHP sessions',
    '600 запросов с concurrency 12',
    '45 секунд с concurrency 4',
    'максимальная p95 latency: 2500 мс',
    'нет открытых P0/P1 дефектов с риском потери данных',
    'нет открытых P0/P1 дефектов безопасности',
    'не подменяют human beta evidence',
] as $marker) {
    releaseEvidenceAssert(str_contains($docs, $marker), "документация release evidence не содержит marker {$marker}");
}

$releaseGate = releaseEvidenceText($root, '.github/workflows/release-gate.yml');
releaseEvidenceAssert(
    str_contains($releaseGate, 'php tests/integration/release_evidence_contract.php'),
    'Stable release gate не запускает release evidence contract'
);

fwrite(STDOUT, "[OK] контракт browser/mobile/load/soak и release evidence выполнен\n");
