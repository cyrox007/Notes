<?php

declare(strict_types=1);

require_once __DIR__ . '/../../core/Environment.php';

use Core\Environment;

function envContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$prefix = 'WO_ENV_' . strtoupper(bin2hex(random_bytes(4))) . '_';
$keys = [
    'PLAIN', 'EMPTY', 'DOUBLE', 'ESCAPED', 'SINGLE', 'INLINE', 'HASH',
    'EXPORTED', 'BASE', 'EXPANDED', 'LITERAL_DOLLAR', 'PRESET', 'BOM',
];

foreach ($keys as $suffix) {
    $key = $prefix . $suffix;
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}

$temp = tempnam(sys_get_temp_dir(), 'wo-env-');
envContractAssert(is_string($temp) && $temp !== '', 'temporary .env file could not be created');

$plain = $prefix . 'PLAIN';
$empty = $prefix . 'EMPTY';
$double = $prefix . 'DOUBLE';
$escaped = $prefix . 'ESCAPED';
$single = $prefix . 'SINGLE';
$inline = $prefix . 'INLINE';
$hash = $prefix . 'HASH';
$exported = $prefix . 'EXPORTED';
$base = $prefix . 'BASE';
$expanded = $prefix . 'EXPANDED';
$literalDollar = $prefix . 'LITERAL_DOLLAR';
$preset = $prefix . 'PRESET';
$bom = $prefix . 'BOM';

$contents = "\xEF\xBB\xBF# Workspace Organizer environment contract\n"
    . "{$plain}=hello\n"
    . "{$empty}=\n"
    . "{$double}=\"hello world\"\n"
    . "{$escaped}=\"line\\nnext\\tcol\\\\slash\\\"quote\"\n"
    . "{$single}='literal # value'\n"
    . "{$inline}=value   # trailing comment\n"
    . "{$hash}=value#hash\n"
    . "export {$exported}=\"yes\"\n"
    . "{$base}=alpha\n"
    . "{$expanded}=\"\${{$base}}-beta\"\n"
    . "{$literalDollar}=\"\\\${{$base}}\"\n"
    . "{$preset}=from-file\n"
    . "{$bom}=ok\n";

file_put_contents($temp, $contents, LOCK_EX);

putenv($preset . '=from-process');
$_ENV[$preset] = 'from-process';
$_SERVER[$preset] = 'from-process';

Environment::load($temp);

envContractAssert(getenv($plain) === 'hello', 'plain value was not loaded');
envContractAssert(getenv($empty) === '', 'empty value was not preserved');
envContractAssert(getenv($double) === 'hello world', 'double-quoted value was not decoded');
envContractAssert(getenv($escaped) === "line\nnext\tcol\\slash\"quote", 'double-quoted escapes were not decoded');
envContractAssert(getenv($single) === 'literal # value', 'single-quoted value/comment boundary is wrong');
envContractAssert(getenv($inline) === 'value', 'inline comment was not removed');
envContractAssert(getenv($hash) === 'value#hash', 'literal hash in unquoted value was corrupted');
envContractAssert(getenv($exported) === 'yes', 'export KEY=VALUE syntax was not loaded');
envContractAssert(getenv($expanded) === 'alpha-beta', 'variable expansion failed');
envContractAssert(getenv($literalDollar) === '${' . $base . '}', 'escaped variable reference was expanded instead of preserved');
envContractAssert(getenv($preset) === 'from-process', 'existing process environment was overwritten');
envContractAssert(($_ENV[$plain] ?? null) === 'hello', '$_ENV was not populated');
envContractAssert(($_SERVER[$plain] ?? null) === 'hello', '$_SERVER was not populated');
envContractAssert(getenv($bom) === 'ok', 'UTF-8 BOM was not handled');

$invalid = $temp . '.invalid';
file_put_contents($invalid, "BROKEN LINE\n", LOCK_EX);
$thrown = false;
try {
    Environment::load($invalid);
} catch (RuntimeException $e) {
    $thrown = str_contains($e->getMessage(), 'line 1');
}
envContractAssert($thrown, 'invalid .env syntax did not fail closed with line information');

$missing = $temp . '.missing';
$thrown = false;
try {
    Environment::load($missing);
} catch (RuntimeException) {
    $thrown = true;
}
envContractAssert($thrown, 'missing .env did not fail closed');

@unlink($temp);
@unlink($invalid);
foreach ($keys as $suffix) {
    $key = $prefix . $suffix;
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}

echo "[OK] internal environment loader contract\n";
