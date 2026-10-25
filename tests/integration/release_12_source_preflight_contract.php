<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/Release12SourcePreflight.php';

use Core\Release12SourcePreflight;

function release12Assert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('Контракт релиза 1.2: ' . $message);
}

$root = dirname(__DIR__, 2);
$sha = str_repeat('a', 40);
$blocked = Release12SourcePreflight::inspect($root, '1.1.0', 11000, 'stable',
    '2026-10-13', $sha, $sha);
release12Assert($blocked['status'] === 'blocked'
    && in_array('version_identity', $blocked['blockers'], true)
    && $blocked['release_ready'] === false,
    'рабочая ветка 1.2 не должна выдаваться за уже опубликованную');

$temp = sys_get_temp_dir() . '/release-12-source-' . bin2hex(random_bytes(8));
mkdir($temp . '/docs/releases', 0700, true);
mkdir($temp . '/database/migrations', 0700, true);
try {
    file_put_contents($temp . '/README.md', "# Notes\n\n**Версия:** " . chr(96) . "1.2.0" . chr(96));
    file_put_contents($temp . '/CHANGELOG.md', "## 1.2.0 — кандидат\nПроверка.");
    file_put_contents($temp . '/docs/releases/v1.2.0.md',
        '# Workspace Organizer 1.2.0' . "\n" . str_repeat('Релизная приёмка и ограничения. ', 10));
    file_put_contents($temp . '/composer.json', '{"require":{"php":">=8.3"}}');
    file_put_contents($temp . '/database/migrations/manifest.json',
        '{"schema":1,"product":"workspace-organizer","migrations":["test.sql"]}');
    file_put_contents($temp . '/database/migrations/test.sql', "-- Проверочная миграция.\n");

    $positive = Release12SourcePreflight::inspect($temp, '1.2.0', 12000, 'stable',
        '2026-11-01', $sha, $sha);
    release12Assert($positive['source_eligible'] === true
        && $positive['blockers'] === [] && $positive['release_ready'] === false,
        'корректная фикстура подтверждает только исходники, а не выпуск');

    file_put_contents($temp . '/README.md',
        "**Версия:** " . chr(96) . "1.1.0" . chr(96) . "\nВ следующих выпусках будет 1.2.0.");
    $staleReadme = Release12SourcePreflight::inspect($temp, '1.2.0', 12000, 'stable',
        '2026-11-01', $sha, $sha);
    release12Assert(in_array('readme', $staleReadme['blockers'], true),
        'упоминание 1.2.0 вне поля версии README не должно проходить проверку');
    file_put_contents($temp . '/README.md',
        "# Notes\n\n**Версия:** " . chr(96) . "1.2.0" . chr(96));

    $wrong = Release12SourcePreflight::inspect($temp, '1.2.0', 12000, 'stable',
        '2026-11-01', $sha, str_repeat('b', 40));
    release12Assert(in_array('exact_sha', $wrong['blockers'], true),
        'проверка на чужом SHA запрещена');

    unlink($temp . '/database/migrations/test.sql');
    $missing = Release12SourcePreflight::inspect($temp, '1.2.0', 12000, 'stable',
        '2026-11-01', $sha, $sha);
    release12Assert(in_array('migration_manifest', $missing['blockers'], true),
        'отсутствующий файл миграции должен блокировать выпуск');

    $script = (string) file_get_contents($root . '/bin/release_12_source_preflight.php');
    $workflow = (string) file_get_contents($root . '/.github/workflows/release-12-source-preflight.yml');
    release12Assert(str_contains($script, 'GITHUB_SHA')
        && str_contains($workflow, 'workflow_dispatch')
        && str_contains($workflow, 'expected_sha'),
        'точный SHA требуется в ручной проверке кандидата');
} finally {
    foreach ([
        'README.md', 'CHANGELOG.md', 'composer.json',
        'docs/releases/v1.2.0.md', 'database/migrations/manifest.json',
        'database/migrations/test.sql'
    ] as $path) {
        @unlink($temp . '/' . $path);
    }
    @rmdir($temp . '/docs/releases');
    @rmdir($temp . '/docs');
    @rmdir($temp . '/database/migrations');
    @rmdir($temp . '/database');
    @rmdir($temp);
}

echo "1.2: точный SHA, идентичность, миграции и запрет ложной готовности проверены\n";
