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
    $code = maskNonCodeText($source);

    foreach ($deprecatedPatterns as $key => $definition) {
        foreach (matchOffsets($definition['pattern'], $code) as $offset) {
            $deprecated[] = [
                'kind' => $key,
                'description' => $definition['description'],
                'file' => $relative,
                'line' => sourceLine($source, $offset),
            ];
        }
    }

    foreach (dollarBraceInterpolationOffsets($source) as $offset) {
        $deprecated[] = [
            'kind' => 'dollar_brace_interpolation',
            'description' => 'Устаревшая интерполяция ${var}',
            'file' => $relative,
            'line' => sourceLine($source, $offset),
        ];
    }

    if (containsClassLikeDeclaration($code) && !str_contains($code, 'declare(strict_types=1)')) {
        $strictTypesMissing[] = $relative;
    }

    foreach (untypedPropertyMatches($code) as $match) {
        $untypedProperties[] = [
            'file' => $relative,
            'line' => sourceLine($source, $match['offset']),
            'property' => $match['property'],
        ];
    }

    $declared = declaredPropertyNames($code);
    foreach (assignedThisPropertyNames($code) as $assignment) {
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
echo 'Файлов с классами без strict_types: ' . $report['summary']['strict_types_missing'] . PHP_EOL;
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

/**
 * Убирает из анализа комментарии и текстовые литералы, сохраняя длину исходника.
 * Это не даёт словам из документации, регулярных выражений и сообщений становиться
 * ложными срабатываниями, а исходные смещения строк остаются точными.
 */
function maskNonCodeText(string $source): string
{
    $masked = '';
    foreach (token_get_all($source) as $token) {
        if (is_string($token)) {
            $masked .= $token;
            continue;
        }

        [$id, $text] = $token;
        if (in_array($id, [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
            $masked .= maskTextPreservingLines($text);
            continue;
        }

        $masked .= $text;
    }

    return $masked;
}

function maskTextPreservingLines(string $text): string
{
    $result = '';
    $length = strlen($text);
    for ($index = 0; $index < $length; $index++) {
        $char = $text[$index];
        $result .= ($char === "\n" || $char === "\r") ? $char : ' ';
    }

    return $result;
}

/** @return list<int> */
function dollarBraceInterpolationOffsets(string $source): array
{
    $offsets = [];
    $offset = 0;

    foreach (token_get_all($source) as $token) {
        if (is_string($token)) {
            $offset += strlen($token);
            continue;
        }

        [$id, $text] = $token;
        if ($id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $offsets[] = $offset;
        }
        $offset += strlen($text);
    }

    return $offsets;
}

/** @return list<int> */
function matchOffsets(string $pattern, string $source): array
{
    $matched = @preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
    if ($matched === false) {
        throw invalidAuditPattern($pattern);
    }
    if ($matched === 0) {
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
    $pattern = '/\b(?:class|interface|trait|enum)\s+[A-Za-z_][A-Za-z0-9_]*/';
    $matched = @preg_match($pattern, $source);
    if ($matched === false) {
        throw invalidAuditPattern($pattern);
    }

    return $matched === 1;
}

/** @return list<array{property:string,offset:int}> */
function untypedPropertyMatches(string $source): array
{
    $pattern = '/\b(?:public|protected|private|var)\s+(?:static\s+)?\$([A-Za-z_][A-Za-z0-9_]*)/';
    $count = @preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
    if ($count === false) {
        throw invalidAuditPattern($pattern);
    }
    if ($count === 0) {
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
    $pattern = '/\b(?:public|protected|private|var)\s+(?:(?:static|readonly)\s+)*(?:(?:[()?A-Za-z_\\\\][()A-Za-z0-9_\\\\|&? ]*)\s+)?\$([A-Za-z_][A-Za-z0-9_]*)/';
    $count = @preg_match_all($pattern, $source, $matches);
    if ($count === false) {
        throw invalidAuditPattern($pattern);
    }
    if ($count === 0) {
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
    $count = @preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
    if ($count === false) {
        throw invalidAuditPattern($pattern);
    }
    if ($count === 0) {
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

function invalidAuditPattern(string $pattern): RuntimeException
{
    return new RuntimeException(sprintf(
        'Некорректное регулярное выражение аудита %s: %s',
        $pattern,
        preg_last_error_msg()
    ));
}
