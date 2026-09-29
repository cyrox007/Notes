<?php

declare(strict_types=1);

/**
 * Статический аудит использования ядра.
 *
 * Скрипт ничего не удаляет. Он строит список классов, глобальных функций и
 * приватных методов ядра, у которых не найдено текстовых ссылок в рабочем коде.
 * Результат является списком кандидатов для ручной проверки, а не основанием
 * для автоматического удаления: динамическая загрузка и строковые контракты
 * должны подтверждаться отдельно.
 */

$root = dirname(__DIR__, 2);
$files = collectPhpFiles($root);
$production = [];
$tests = [];

foreach ($files as $relative => $source) {
    if (str_starts_with($relative, 'tests/')) {
        $tests[$relative] = $source;
        continue;
    }

    $production[$relative] = $source;
}

$coreFiles = array_filter(
    $production,
    static fn (string $source, string $relative): bool => $relative === 'core.php' || str_starts_with($relative, 'core/'),
    ARRAY_FILTER_USE_BOTH
);

$coreDeclarations = collectDeclarations($coreFiles);
$allDeclarations = collectDeclarations($production);

$classCandidates = [];
foreach ($coreDeclarations['classes'] as $class) {
    $productionRefs = countExternalReferences($class['short'], $class['file'], $production);
    $testRefs = countExternalReferences($class['short'], $class['file'], $tests);
    $ownSource = $coreFiles[$class['file']] ?? '';
    $internalRefs = max(0, countOccurrences($class['short'], $ownSource) - 1);

    if ($productionRefs !== 0 || $internalRefs !== 0) {
        continue;
    }

    $classCandidates[] = [
        'symbol' => $class['fqcn'],
        'file' => $class['file'],
        'internal_refs' => $internalRefs,
        'production_refs' => $productionRefs,
        'test_refs' => $testRefs,
    ];
}

$functionCandidates = [];
foreach ($coreDeclarations['functions'] as $function) {
    $productionRefs = countExternalReferences($function['short'], $function['file'], $production);
    $testRefs = countExternalReferences($function['short'], $function['file'], $tests);
    $ownSource = $coreFiles[$function['file']] ?? '';
    $internalRefs = max(0, countOccurrences($function['short'], $ownSource) - 1);

    if ($productionRefs !== 0 || $internalRefs !== 0) {
        continue;
    }

    $functionCandidates[] = [
        'symbol' => $function['fqcn'],
        'file' => $function['file'],
        'internal_refs' => $internalRefs,
        'production_refs' => $productionRefs,
        'test_refs' => $testRefs,
    ];
}

$privateMethodCandidates = [];
foreach ($coreDeclarations['private_methods'] as $method) {
    $source = $coreFiles[$method['file']] ?? '';
    $occurrences = countOccurrences($method['short'], $source);

    if ($occurrences > 1) {
        continue;
    }

    $privateMethodCandidates[] = [
        'symbol' => $method['class'] . '::' . $method['short'],
        'file' => $method['file'],
        'occurrences_in_file' => $occurrences,
    ];
}

$report = [
    'php_runtime' => PHP_VERSION,
    'minimum_supported_php' => '8.1',
    'files' => [
        'php_total' => count($files),
        'production_php' => count($production),
        'test_php' => count($tests),
        'core_php' => count($coreFiles),
    ],
    'declarations' => [
        'core_classes' => count($coreDeclarations['classes']),
        'core_functions' => count($coreDeclarations['functions']),
        'core_private_methods' => count($coreDeclarations['private_methods']),
    ],
    'candidates' => [
        'classes_without_product_references' => $classCandidates,
        'functions_without_product_references' => $functionCandidates,
        'private_methods_with_declaration_only' => $privateMethodCandidates,
    ],
    'duplicates' => [
        'classes' => duplicateDeclarations($allDeclarations['classes']),
        'functions' => duplicateDeclarations($allDeclarations['functions']),
    ],
];

if (in_array('--json', $argv, true)) {
    echo json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ), PHP_EOL;
    exit(0);
}

echo "Статический аудит ядра на PHP " . PHP_VERSION . PHP_EOL;
echo "Минимально поддерживаемая версия: PHP 8.1" . PHP_EOL;
echo "Файлов ядра: " . $report['files']['core_php'] . PHP_EOL;
echo "Кандидатов-классов без продуктовых ссылок: " . count($classCandidates) . PHP_EOL;
echo "Кандидатов-функций без продуктовых ссылок: " . count($functionCandidates) . PHP_EOL;
echo "Кандидатов-приватных методов: " . count($privateMethodCandidates) . PHP_EOL;
echo "Дубликатов FQCN: " . count($report['duplicates']['classes']) . PHP_EOL;
echo "Дубликатов глобальных функций: " . count($report['duplicates']['functions']) . PHP_EOL;

/**
 * @return array<string,string>
 */
function collectPhpFiles(string $root): array
{
    $excludedSegments = [
        '.git',
        'vendor',
        'node_modules',
        'cache',
        'compile',
        'uploads',
        'notes-private-storage',
    ];

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }

        $absolute = $file->getPathname();
        $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
        if (hasExcludedSegment($relative, $excludedSegments)) {
            continue;
        }

        $source = file_get_contents($absolute);
        if (!is_string($source)) {
            throw new RuntimeException('Не удалось прочитать PHP-файл: ' . $relative);
        }

        $files[$relative] = $source;
    }

    ksort($files, SORT_STRING);
    return $files;
}

/**
 * @param list<string> $segments
 */
function hasExcludedSegment(string $relative, array $segments): bool
{
    $parts = explode('/', $relative);
    foreach ($segments as $segment) {
        if (in_array($segment, $parts, true)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string,string> $files
 * @return array{
 *   classes:list<array{fqcn:string,short:string,file:string}>,
 *   functions:list<array{fqcn:string,short:string,file:string}>,
 *   private_methods:list<array{class:string,short:string,file:string}>
 * }
 */
function collectDeclarations(array $files): array
{
    $result = [
        'classes' => [],
        'functions' => [],
        'private_methods' => [],
    ];

    foreach ($files as $file => $source) {
        $parsed = parseDeclarations($source, $file);
        foreach (array_keys($result) as $key) {
            array_push($result[$key], ...$parsed[$key]);
        }
    }

    return $result;
}

/**
 * @return array{
 *   classes:list<array{fqcn:string,short:string,file:string}>,
 *   functions:list<array{fqcn:string,short:string,file:string}>,
 *   private_methods:list<array{class:string,short:string,file:string}>
 * }
 */
function parseDeclarations(string $source, string $file): array
{
    $tokens = token_get_all($source);
    $namespace = firstNamespace($tokens);
    $classes = [];
    $functions = [];
    $privateMethods = [];
    $classRanges = [];

    $classTokens = [T_CLASS, T_INTERFACE, T_TRAIT];
    if (defined('T_ENUM')) {
        $classTokens[] = T_ENUM;
    }

    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || !in_array($token[0], $classTokens, true)) {
            continue;
        }

        $previousId = previousSignificantTokenId($tokens, $index);
        if ($previousId === T_NEW || $previousId === T_DOUBLE_COLON) {
            continue;
        }

        $name = nextNamedToken($tokens, $index + 1);
        if ($name === null) {
            continue;
        }

        $openBrace = nextSymbolIndex($tokens, $index + 1, '{');
        if ($openBrace === null) {
            continue;
        }

        $closeBrace = matchingBraceIndex($tokens, $openBrace);
        if ($closeBrace === null) {
            continue;
        }

        $fqcn = qualify($namespace, $name);
        $classes[] = ['fqcn' => $fqcn, 'short' => $name, 'file' => $file];
        $classRanges[] = [
            'fqcn' => $fqcn,
            'open' => $openBrace,
            'close' => $closeBrace,
        ];
    }

    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (!is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        $name = nextNamedToken($tokens, $index + 1);
        if ($name === null) {
            continue;
        }

        $activeClass = containingClass($classRanges, $index);
        if ($activeClass === null) {
            $functions[] = [
                'fqcn' => qualify($namespace, $name),
                'short' => $name,
                'file' => $file,
            ];
            continue;
        }

        if (str_starts_with($name, '__') || !functionIsPrivate($tokens, $index)) {
            continue;
        }

        $privateMethods[] = [
            'class' => $activeClass,
            'short' => $name,
            'file' => $file,
        ];
    }

    return [
        'classes' => $classes,
        'functions' => $functions,
        'private_methods' => $privateMethods,
    ];
}

/**
 * @param array<int,mixed> $tokens
 */
function firstNamespace(array $tokens): string
{
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if (is_array($token) && $token[0] === T_NAMESPACE) {
            return readNamespace($tokens, $index + 1);
        }
    }

    return '';
}

/**
 * @param array<int,mixed> $tokens
 */
function nextSymbolIndex(array $tokens, int $start, string $symbol): ?int
{
    $count = count($tokens);
    for ($index = $start; $index < $count; $index++) {
        if ($tokens[$index] === $symbol) {
            return $index;
        }

        if ($tokens[$index] === ';') {
            return null;
        }
    }

    return null;
}

/**
 * @param array<int,mixed> $tokens
 */
function matchingBraceIndex(array $tokens, int $openIndex): ?int
{
    $depth = 0;
    $count = count($tokens);

    for ($index = $openIndex; $index < $count; $index++) {
        $token = $tokens[$index];

        if ($token === '{') {
            $depth++;
            continue;
        }

        if (
            is_array($token)
            && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)
        ) {
            $depth++;
            continue;
        }

        if ($token !== '}') {
            continue;
        }

        $depth--;
        if ($depth === 0) {
            return $index;
        }
    }

    return null;
}

/**
 * @param list<array{fqcn:string,open:int,close:int}> $ranges
 */
function containingClass(array $ranges, int $tokenIndex): ?string
{
    $selected = null;
    $selectedSpan = null;

    foreach ($ranges as $range) {
        if ($tokenIndex <= $range['open'] || $tokenIndex >= $range['close']) {
            continue;
        }

        $span = $range['close'] - $range['open'];
        if ($selectedSpan !== null && $span >= $selectedSpan) {
            continue;
        }

        $selected = $range['fqcn'];
        $selectedSpan = $span;
    }

    return $selected;
}

/**
 * @param array<int,mixed> $tokens
 */
function readNamespace(array $tokens, int $start): string
{
    $namespace = '';
    $count = count($tokens);

    for ($index = $start; $index < $count; $index++) {
        $token = $tokens[$index];
        if (is_string($token)) {
            if ($token === ';' || $token === '{') {
                break;
            }
            continue;
        }

        $id = $token[0];
        if (
            $id === T_STRING
            || $id === T_NS_SEPARATOR
            || (defined('T_NAME_QUALIFIED') && $id === T_NAME_QUALIFIED)
        ) {
            $namespace .= $token[1];
        }
    }

    return trim($namespace, '\\');
}

/**
 * @param array<int,mixed> $tokens
 */
function nextNamedToken(array $tokens, int $start): ?string
{
    $count = count($tokens);
    for ($index = $start; $index < $count; $index++) {
        $token = $tokens[$index];

        if (is_array($token) && $token[0] === T_STRING) {
            return $token[1];
        }

        if (is_string($token) && ($token === '(' || $token === '{' || $token === ';')) {
            return null;
        }
    }

    return null;
}

/**
 * @param array<int,mixed> $tokens
 */
function previousSignificantTokenId(array $tokens, int $index): ?int
{
    for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
        $token = $tokens[$cursor];
        if (is_string($token)) {
            if (trim($token) !== '') {
                return null;
            }
            continue;
        }

        if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $token[0];
    }

    return null;
}

/**
 * @param array<int,mixed> $tokens
 */
function functionIsPrivate(array $tokens, int $functionIndex): bool
{
    for ($index = $functionIndex - 1; $index >= 0; $index--) {
        $token = $tokens[$index];

        if (is_string($token)) {
            if ($token === ';' || $token === '{' || $token === '}') {
                return false;
            }
            continue;
        }

        if ($token[0] === T_PRIVATE) {
            return true;
        }

        if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STATIC], true)) {
            continue;
        }

        if (in_array($token[0], [T_PUBLIC, T_PROTECTED], true)) {
            return false;
        }
    }

    return false;
}

function qualify(string $namespace, string $name): string
{
    return $namespace === '' ? $name : $namespace . '\\' . $name;
}

/**
 * @param array<string,string> $files
 */
function countExternalReferences(string $symbol, string $declarationFile, array $files): int
{
    $count = 0;
    foreach ($files as $file => $source) {
        if ($file === $declarationFile) {
            continue;
        }

        $count += countOccurrences($symbol, $source);
    }

    return $count;
}

function countOccurrences(string $symbol, string $source): int
{
    $pattern = '/(?<![A-Za-z0-9_])' . preg_quote($symbol, '/') . '(?![A-Za-z0-9_])/';
    $matched = preg_match_all($pattern, $source, $matches);
    return $matched === false ? 0 : $matched;
}

/**
 * @param list<array{fqcn:string,short:string,file:string}> $declarations
 * @return list<array{symbol:string,files:list<string>}>
 */
function duplicateDeclarations(array $declarations): array
{
    $grouped = [];
    foreach ($declarations as $declaration) {
        $grouped[$declaration['fqcn']][] = $declaration['file'];
    }

    $duplicates = [];
    foreach ($grouped as $symbol => $files) {
        $uniqueFiles = array_values(array_unique($files));
        if (count($uniqueFiles) < 2) {
            continue;
        }

        $duplicates[] = ['symbol' => $symbol, 'files' => $uniqueFiles];
    }

    usort(
        $duplicates,
        static fn (array $left, array $right): int => strcmp($left['symbol'], $right['symbol'])
    );

    return $duplicates;
}
