<?php

declare(strict_types=1);

/**
 * Карта полного рефакторинга линии 1.1 под PHP 8.2.
 *
 * Скрипт не меняет исходники автоматически. Его задача — находить конструкции,
 * которые нужно разобрать вручную, и выдавать воспроизводимый отчёт для CI.
 */

$root = dirname(__DIR__, 2);
$files = collectProductionPhpFiles($root);

$deprecatedPatterns = [
    'allow_dynamic_properties' => [
        'pattern' => '/#\[\s*\\?AllowDynamicProperties\s*\]/',
        'description' => 'Атрибут AllowDynamicProperties',
    ],
    'utf8_encode' => [
        'pattern' => '/\butf8_encode\s*\(/i',
        'description' => 'Устаревшая функция utf8_encode()',
    ],
    'utf8_decode' => [
        'pattern' => '/\butf8_decode\s*\(/i',
        'description' => 'Устаревшая функция utf8_decode()',
    ],
    'filter_sanitize_string' => [
        'pattern' => '/\bFILTER_SANITIZE_STRING\b/',
        'description' => 'Устаревший FILTER_SANITIZE_STRING',
    ],
    'dollar_brace_interpolation' => [
        'pattern' => '/\$\{[A-Za-z_][A-Za-z0-9_]*\}/',
        'description' => 'Устаревшая интерполяция ${var}',
    ],
    'strftime' => [
        'pattern' => '/\b(?:gm)?strftime\s*\(/i',
        'description' => 'Устаревшая функция strftime()/gmstrftime()',
    ],
];

$deprecated = [];
$strictTypesMissing = [];
$untypedProperties = [];
$dynamicPropertyCandidates = [];

foreach ($files as $relative => $source) {
    foreach ($deprecatedPatterns as $key => $definition) {
        foreach (matchOffsets($definition['pattern'], $source) as $offset) {
            $deprecated[] = [
                'kind' => $key,
                'description' => $definition['description'],
                'file' => $relative,
                'line' => sourceLine($source, $offset),
            ];
        }
    }

    if (containsClassLikeDeclaration($source) && !str_contains($source, 'declare(strict_types=1)')) {
        $strictTypesMissing[] = $relative;
    }

    foreach (untypedPropertyMatches($source) as $match) {
        $untypedProperties[] = [
            'file' => $relative,
            'line' => sourceLine($source, $match['offset']),
            'property' => $match['property'],
        ];
    }

    $declared = declaredPropertyNames($source);
    foreach (assignedThisPropertyNames($source) as $assignment) {
        if (isset($declared[$assignment['property']])) {
            continue;
        }

        $dynamicPropertyCandidates[] = [
            'file' => $relative,
            'line' => sourceLine($source, $assignment['offset']),
            'property' => $assignment['property'],
        ];
    }
}

$report = [
    'php_runtime' => PHP_VERSION,
    'minimum_supported_php' => '8.2',
    'files_scanned' => count($files),
    'deprecated' => $deprecated,
    'strict_types_missing' => array_values($strictTypesMissing),
    'untyped_properties' => $untypedProperties,
    'dynamic_property_candidates' => $dynamicPropertyCandidates,
    'summary' => [
        'deprecated' => count($deprecated),
        'strict_types_missing' => count($strictTypesMissing),
        'untyped_properties' => count($untypedProperties),
        'dynamic_property_candidates' => count($dynamicPropertyCandidates),
    ],
];

if (in_array('--json', $argv, true)) {
    echo json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ), PHP_EOL;
    exit(0);
}

echo 'Аудит рефакторинга PHP 8.2' . PHP_EOL;
echo 'Проверено PHP-файлов: ' . $report['files_scanned'] . PHP_EOL;
echo 'Устаревших конструкций: ' . $report['summary']['deprecated'] . PHP_EOL;
echo 'Class-like файлов без strict_types: ' . $report['summary']['strict_types_missing'] . PHP_EOL;
echo 'Нетипизированных свойств: ' . $report['summary']['untyped_properties'] . PHP_EOL;
echo 'Кандидатов на динамические свойства: ' . $report['summary']['dynamic_property_candidates'] . PHP_EOL;

/** @return array<string,string> */
function collectProductionPhpFiles(string $root): array
{
    $excluded = [
        '.git',
        'vendor',
        'node_modules',
        'cache',
        'compile',
        'uploads',
        'tests',
        'notes-private-storage',
    ];

    $result = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $absolute = $file->getPathname();
        $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
        if (hasExcludedSegment($relative, $excluded)) {
            continue;
        }

        $source = file_get_contents($absolute);
        if (!is_string($source)) {
            throw new RuntimeException('Не удалось прочитать PHP-файл: ' . $relative);
        }

        $result[$relative] = $source;
    }

    ksort($result, SORT_STRING);
    return $result;
}

/** @param list<string> $excluded */
function hasExcludedSegment(string $relative, array $excluded): bool
{
    $segments = explode('/', $relative);
    foreach ($excluded as $segment) {
        if (in_array($segment, $segments, true)) {
            return true;
        }
    }

    return false;
}

/** @return list<int> */
function matchOffsets(string $pattern, string $source): array
{
    $matched = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
    if ($matched === false || $matched === 0) {
        return [];
    }

    return array_map(
        static fn (array $match): int => (int) $match[1],
        $matches[0]
    );
}

function sourceLine(string $source, int $offset): int
{
    return substr_count(substr($source, 0, max(0, $offset)), "\n") + 1;
}

function containsClassLikeDeclaration(string $source): bool
{
    return preg_match('/\b(?:class|interface|trait|enum)\s+[A-Za-z_][A-Za-z0-9_]*/', $source) === 1;
}

/** @return list<array{property:string,offset:int}> */
function untypedPropertyMatches(string $source): array
{
    $pattern = '/\b(?:public|protected|private|var)\s+(?:static\s+)?\$([A-Za-z_][A-Za-z0-9_]*)/';
    $count = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
    if ($count === false || $count === 0) {
        return [];
    }

    $result = [];
    foreach ($matches[1] as $index => $property) {
        $result[] = [
            'property' => (string) $property[0],
            'offset' => (int) $matches[0][$index][1],
        ];
    }

    return $result;
}

/** @return array<string,true> */
function declaredPropertyNames(string $source): array
{
    $pattern = '/\b(?:public|protected|private|var)\s+(?:static\s+)?(?:readonly\s+)?(?:[?\\A-Za-z_][\\A-Za-z0-9_|&?]*\s+)?\$([A-Za-z_][A-Za-z0-9_]*)/';
    $count = preg_match_all($pattern, $source, $matches);
    if ($count === false || $count === 0) {
        return [];
    }

    $result = [];
    foreach ($matches[1] as $property) {
        $result[(string) $property] = true;
    }

    return $result;
}

/** @return list<array{property:string,offset:int}> */
function assignedThisPropertyNames(string $source): array
{
    $pattern = '/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*=/';
    $count = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
    if ($count === false || $count === 0) {
        return [];
    }

    $result = [];
    foreach ($matches[1] as $index => $property) {
        $result[] = [
            'property' => (string) $property[0],
            'offset' => (int) $matches[0][$index][1],
        ];
    }

    return $result;
}
