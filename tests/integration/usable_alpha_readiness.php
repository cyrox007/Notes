<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$errors = [];

$require = static function (string $relative, string $reason) use ($root, &$errors): void {
    if (!is_file($root . '/' . $relative)) {
        $errors[] = "missing {$relative} — {$reason}";
    }
};

// Product Browser E2E closure.
$require('.github/workflows/profile-browser-lifecycle.yml', 'Profile edit/avatar lifecycle is not integrated');
$require('.github/workflows/admin-browser-lifecycle.yml', 'Admin status/quota lifecycle is not integrated');
$require('.github/workflows/fault-injection-browser.yml', 'browser-visible DB/storage fault injection is not integrated');

// Usability closure.
$require('assets/js/feedback.js', 'shared toast/inline/confirmation layer is not integrated');
$require('assets/js/notes-draft.js', 'Notes dirty-state/local draft protection is not integrated');
$require('assets/js/usability-actions.js', 'inline Tasks/File Manager actions are not integrated');

// Findability closure.
$require('app/services/ListQuery.php', 'bounded q/page/limit/sort contract is not integrated');
$require('assets/js/findability.js', 'URL-preserving findability controls are not integrated');
$require('tests/integration/list_query_contract.php', 'findability query contract is not integrated');

// Governance closure.
$require('.github/release-governance.json', 'machine-readable merge governance is not integrated');
$require('docs/RELEASE_GOVERNANCE.md', 'branch-protection/review policy is not integrated');
$require('tests/integration/release_governance_contract.php', 'governance drift check is not integrated');

$roadmapPath = $root . '/docs/USABLE_BASELINE_0.12.md';
if (!is_file($roadmapPath)) {
    $errors[] = 'missing docs/USABLE_BASELINE_0.12.md';
} else {
    $roadmap = (string) file_get_contents($roadmapPath);
    $requiredSections = [
        '## P1 — Product browser E2E',
        '## P1 — Usability pass',
        '## P1 — Pagination / findability',
        '## P1 — Security / governance hardening',
    ];
    foreach ($requiredSections as $index => $heading) {
        $start = strpos($roadmap, $heading);
        if ($start === false) {
            $errors[] = "roadmap section missing: {$heading}";
            continue;
        }
        $nextStart = strlen($roadmap);
        foreach ($requiredSections as $otherHeading) {
            $candidate = strpos($roadmap, $otherHeading, $start + strlen($heading));
            if ($candidate !== false) {
                $nextStart = min($nextStart, $candidate);
            }
        }
        $p2 = strpos($roadmap, '## P2 —', $start + strlen($heading));
        if ($p2 !== false) {
            $nextStart = min($nextStart, $p2);
        }
        $definition = strpos($roadmap, '## Definition of Done', $start + strlen($heading));
        if ($definition !== false) {
            $nextStart = min($nextStart, $definition);
        }
        $section = substr($roadmap, $start, $nextStart - $start);
        if (str_contains($section, '- [ ]')) {
            $errors[] = "roadmap still has unchecked items in {$heading}";
        }
    }
}

$versionPath = $root . '/core/Version.php';
if (is_file($versionPath)) {
    $version = (string) file_get_contents($versionPath);
    if (!str_contains($version, "public const VERSION = '0.12.0-alpha';")) {
        $errors[] = 'final release commit must set Core\\Version::VERSION to 0.12.0-alpha';
    }
    if (!str_contains($version, 'public const VERSION_CODE = 1200;')) {
        $errors[] = 'final release commit must set VERSION_CODE to 1200';
    }
    if (!str_contains($version, "public const RELEASE_DATE = '2026-09-14';")) {
        $errors[] = 'final release commit must set RELEASE_DATE to 2026-09-14';
    }
} else {
    $errors[] = 'missing core/Version.php';
}

$readme = is_file($root . '/README.md') ? (string) file_get_contents($root . '/README.md') : '';
if (!str_contains($readme, '**Версия:** `0.12.0-alpha`')) {
    $errors[] = 'README.md must advertise 0.12.0-alpha';
}

$changelog = is_file($root . '/CHANGELOG.md') ? (string) file_get_contents($root . '/CHANGELOG.md') : '';
if (!str_contains($changelog, '## 0.12.0-alpha — 2026-09-14')) {
    $errors[] = 'CHANGELOG.md must contain the 0.12.0-alpha release section';
}

if ($errors !== []) {
    fwrite(STDERR, "Workspace 0.12 usable-alpha readiness: BLOCKED\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Workspace 0.12 usable-alpha readiness: OK\n");
