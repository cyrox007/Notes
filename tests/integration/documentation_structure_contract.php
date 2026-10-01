<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function documentationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[ОШИБКА] документация: {$message}\n");
        exit(1);
    }
}

function documentationText(string $root, string $path): string
{
    $full = $root . '/' . $path;
    documentationAssert(is_file($full), "отсутствует {$path}");
    $content = file_get_contents($full);
    documentationAssert(is_string($content), "не удалось прочитать {$path}");
    return $content;
}

$index = documentationText($root, 'docs/README.md');
foreach ([
    'ROADMAP.md',
    'PRODUCT_BACKLOG.md',
    'SYSTEM_REQUIREMENTS.md',
    'MODULE_PLATFORM.md',
    'RBAC.md',
    'plans/1.1-calendar.md',
    'plans/1.2-defects.md',
    'plans/1.3-work-permits.md',
    'plans/1.4-media.md',
    'plans/1.5-code-explorer.md',
    'plans/1.6-office.md',
    'ARCHIVE.md',
] as $marker) {
    documentationAssert(str_contains($index, $marker), "docs/README.md не содержит {$marker}");
}

$roadmap = documentationText($root, 'docs/ROADMAP.md');
documentationAssert(str_contains($roadmap, 'v1.0.14'), 'ROADMAP не фиксирует текущий baseline 1.0.14');
documentationAssert(str_contains($roadmap, '1.1.0 — Календарь и ежедневник'), 'ROADMAP не содержит следующую продуктовую линию 1.1.0');
documentationAssert(!str_contains($roadmap, '### 1.0.12'), 'ROADMAP снова содержит исторический релиз 1.0.12 как активный раздел');
documentationAssert(!str_contains($roadmap, '### 1.0.2'), 'ROADMAP снова содержит исторический релиз 1.0.2 как активный раздел');
documentationAssert(!str_contains($roadmap, '### 2FA/TOTP'), 'ROADMAP снова содержит закрытый 2FA как будущую задачу');

$core = documentationText($root, 'docs/CORE.md');
documentationAssert(str_contains($core, '1.0.14'), 'CORE.md не привязан к текущей стабильной линии');
documentationAssert(str_contains($core, 'RuntimeAutoloader'), 'CORE.md не описывает текущий внутренний autoload');
documentationAssert(!str_contains($core, '0.11.0-alpha'), 'CORE.md снова описывает 0.11 alpha');
documentationAssert(!str_contains($core, 'подключает Composer autoload'), 'CORE.md снова описывает старый Composer bootstrap');

$requirements = documentationText($root, 'docs/SYSTEM_REQUIREMENTS.md');
foreach (['PHP 8.1+', 'MySQL 8.0+', 'MariaDB 10.5+', 'не зависит от Composer'] as $marker) {
    documentationAssert(str_contains($requirements, $marker), "SYSTEM_REQUIREMENTS.md не содержит {$marker}");
}

foreach ([
    'docs/MODULE_PLATFORM_0.14.md' => 'MODULE_PLATFORM.md',
    'docs/SYSTEM_REQUIREMENTS_0.14.md' => 'SYSTEM_REQUIREMENTS.md',
    'docs/RBAC_0.14.md' => 'RBAC.md',
] as $legacy => $canonical) {
    documentationAssert(
        str_contains(documentationText($root, $legacy), $canonical),
        "{$legacy} не ведёт на {$canonical}"
    );
}

foreach ([
    'docs/BETA_HARDENING_0.14.md',
    'docs/BRANCH_PLAN_0.12.md',
    'docs/CORE_SECURITY_AUDIT_0.14.md',
    'docs/DB_ARCHITECTURE_AUDIT_0.12.md',
    'docs/PRODUCT_UX_0.13.md',
    'docs/RELEASE_0.12_CANDIDATE.md',
    'docs/UPDATER_1.0.6_REWORK.md',
    'docs/UPDATER_BETA4_BOOTSTRAP.md',
    'docs/USABLE_BASELINE_0.12.md',
    'docs/USABLE_BASELINE_0.12_STATUS.md',
] as $historical) {
    documentationAssert(
        str_contains(documentationText($root, $historical), '> **Архив.**'),
        "{$historical} не помечен как исторический документ"
    );
}

$readme = documentationText($root, 'README.md');
documentationAssert(str_contains($readme, 'docs/README.md'), 'корневой README не ведёт в индекс документации');
documentationAssert(!str_contains($readme, 'Для `v1.0.7`'), 'README снова содержит старый релизный план 1.0.7');

fwrite(STDOUT, "[OK] структура актуальной и исторической документации согласована\n");
