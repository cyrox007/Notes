<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/Release12SourcePreflight.php';
require_once dirname(__DIR__) . '/core/Version.php';

use Core\Release12SourcePreflight;
use Core\Version;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$options = getopt('', ['expected-sha:', 'json', 'help']);
if (isset($options['help'])) {
    echo "Использование: php bin/release_12_source_preflight.php --expected-sha=<40 hex> [--json]\n";
    echo "Проверяет исходники точного SHA, не подтверждает готовность выпуска.\n";
    exit(0);
}

$expected = $options['expected-sha'] ?? null;
$actual = getenv('GITHUB_SHA');
$result = Release12SourcePreflight::inspect(
    dirname(__DIR__),
    Version::VERSION,
    Version::VERSION_CODE,
    Version::STATUS,
    Version::RELEASE_DATE,
    is_string($actual) ? $actual : null,
    is_string($expected) ? $expected : null
);

if (isset($options['json'])) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} else {
    echo $result['source_eligible']
        ? "Контракт исходников подтверждён; выпуск ещё не принят.\n"
        : 'Релиз-кандидат 1.2 заблокирован: ' . implode(', ', $result['blockers']) . PHP_EOL;
}
exit($result['source_eligible'] ? 0 : 2);
