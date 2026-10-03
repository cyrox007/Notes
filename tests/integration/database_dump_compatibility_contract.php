<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/DatabaseDumpCompatibility.php';

use Core\DatabaseDumpCompatibility;

function dumpCompatibilityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] совместимость дампа БД: {$message}\n");
        exit(1);
    }
}

$broken = <<<'SQL'
ALTER TABLE `notes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uid` (`uid`),
  ADD KEY `idx_user_notes` (`user_id`, `is_deleted`, DESC),
  ADD KEY `idx_updated_notes` (`user_id`,DESC),
  ADD KEY `idx_notes_profile_public` (`user_id`,`is_profile_public`,`is_deleted`, DESC);
SQL;

$fixed = DatabaseDumpCompatibility::repairSql($broken);
dumpCompatibilityAssert(!str_contains($fixed, ', DESC)'), 'bare DESC остался в исправленном SQL');
dumpCompatibilityAssert(
    str_contains($fixed, 'ADD KEY `idx_user_notes` (`user_id`, `is_deleted`, `created_note`)'),
    'не восстановлена created_note в idx_user_notes'
);
dumpCompatibilityAssert(
    str_contains($fixed, 'ADD KEY `idx_updated_notes` (`user_id`, `updated_note`)'),
    'не восстановлена updated_note в idx_updated_notes'
);
dumpCompatibilityAssert(
    str_contains($fixed, 'ADD KEY `idx_notes_profile_public` (`user_id`, `is_profile_public`, `is_deleted`, `updated_note`)'),
    'не восстановлена updated_note в idx_notes_profile_public'
);

$schema = (string) file_get_contents($root . '/database/notes_schema.sql');
foreach (['idx_user_notes', 'idx_updated_notes', 'idx_notes_profile_public'] as $indexName) {
    dumpCompatibilityAssert(
        preg_match('/INDEX `?' . preg_quote($indexName, '/') . '`?[^\n]*\bDESC\b/i', $schema) !== 1,
        "каноническая схема всё ещё содержит descending-индекс {$indexName}"
    );
}

$orderedIndexPattern = '/\b(?:KEY|INDEX)\b[^;]*\b(?:ASC|DESC)\b/i';
$canonicalSchemas = glob($root . '/database/*_schema.sql') ?: [];
dumpCompatibilityAssert($canonicalSchemas !== [], 'не найдены канонические схемы БД');
foreach ($canonicalSchemas as $schemaPath) {
    $sql = (string) file_get_contents($schemaPath);
    dumpCompatibilityAssert(
        preg_match($orderedIndexPattern, $sql) !== 1,
        'ordered index part остался в канонической схеме: ' . basename($schemaPath)
    );
}

$historicalOrderedIndexFiles = [];
foreach (glob($root . '/database/migrations/*.sql') ?: [] as $migrationPath) {
    $sql = (string) file_get_contents($migrationPath);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    if (preg_match($orderedIndexPattern, $sql) === 1) {
        $historicalOrderedIndexFiles[] = basename($migrationPath);
    }
}
sort($historicalOrderedIndexFiles, SORT_STRING);
dumpCompatibilityAssert(
    $historicalOrderedIndexFiles === ['20260914_profile_publication.sql'],
    'обнаружены новые миграции с ASC/DESC в определениях индексов: '
        . implode(', ', $historicalOrderedIndexFiles)
);
dumpCompatibilityAssert(
    str_contains(
        (string) file_get_contents($root . '/database/migrations/20260914_profile_publication.sql'),
        '`updated_note` DESC'
    ),
    'неизменяемая историческая миграция профиля неожиданно изменилась'
);

$migrationName = '20261002_notes_dump_compatibility.sql';
$manifest = json_decode(
    (string) file_get_contents($root . '/database/migrations/manifest.json'),
    true,
    32,
    JSON_THROW_ON_ERROR
);
dumpCompatibilityAssert(
    in_array($migrationName, $manifest['migrations'] ?? [], true),
    'миграция совместимости дампа отсутствует в manifest'
);

$module = json_decode(
    (string) file_get_contents($root . '/modules/notes/module.json'),
    true,
    32,
    JSON_THROW_ON_ERROR
);
dumpCompatibilityAssert(
    in_array('database/migrations/' . $migrationName, $module['database']['migrations'] ?? [], true),
    'модуль Notes не владеет миграцией совместимости дампа'
);

$temp = sys_get_temp_dir() . '/notes-dump-compat-' . bin2hex(random_bytes(5));
dumpCompatibilityAssert(mkdir($temp, 0700, true), 'не удалось создать временный каталог');
$input = $temp . '/broken.sql';
$output = $temp . '/fixed.sql';
try {
    file_put_contents($input, $broken . "\nINSERT INTO `notes` (`id`,`notename`) VALUES (1,'данные не менять');\n");
    $result = DatabaseDumpCompatibility::repairPhpMyAdminDump($input, $output);
    dumpCompatibilityAssert(($result['replacements'] ?? 0) === 3, 'ожидались три исправления индексов');
    $outputBytes = (string) file_get_contents($output);
    dumpCompatibilityAssert(str_contains($outputBytes, "VALUES (1,'данные не менять')"), 'данные дампа были изменены');
    dumpCompatibilityAssert(!str_contains($outputBytes, ', DESC)'), 'файловое исправление оставило bare DESC');
} finally {
    @unlink($input);
    @unlink($output);
    @rmdir($temp);
}

fwrite(STDOUT, "[OK] повреждённый phpMyAdmin дамп исправляется без изменения данных\n");
