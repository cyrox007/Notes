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
envContractAssert(is_string($temp) && $temp !== '', 'не удалось создать временный файл .env');

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

envContractAssert(getenv($plain) === 'hello', 'обычное значение не загружено');
envContractAssert(getenv($empty) === '', 'пустое значение не сохранено');
envContractAssert(getenv($double) === 'hello world', 'значение в двойных кавычках не декодировано');
envContractAssert(getenv($escaped) === "line\nnext\tcol\\slash\"quote", 'экранированные последовательности в двойных кавычках не декодированы');
envContractAssert(getenv($single) === 'literal # value', 'нарушена граница значения и комментария в одинарных кавычках');
envContractAssert(getenv($inline) === 'value', 'встроенный комментарий не удалён');
envContractAssert(getenv($hash) === 'value#hash', 'символ # в значении без кавычек повреждён');
envContractAssert(getenv($exported) === 'yes', 'синтаксис export KEY=VALUE не загружен');
envContractAssert(getenv($expanded) === 'alpha-beta', 'подстановка переменной не сработала');
envContractAssert(getenv($literalDollar) === '${' . $base . '}', 'экранированная ссылка на переменную была ошибочно раскрыта');
envContractAssert(getenv($preset) === 'from-process', 'существующее окружение процесса было перезаписано');
envContractAssert(($_ENV[$plain] ?? null) === 'hello', 'массив $_ENV не заполнен');
envContractAssert(($_SERVER[$plain] ?? null) === 'hello', 'массив $_SERVER не заполнен');
envContractAssert(getenv($bom) === 'ok', 'UTF-8 BOM не обработан');

$invalid = $temp . '.invalid';
file_put_contents($invalid, "BROKEN LINE\n", LOCK_EX);
$thrown = false;
try {
    Environment::load($invalid);
} catch (RuntimeException $e) {
    $thrown = str_contains($e->getMessage(), 'строка 1');
}
envContractAssert($thrown, 'некорректный .env не завершился fail-closed с номером строки');

$missing = $temp . '.missing';
$thrown = false;
try {
    Environment::load($missing);
} catch (RuntimeException) {
    $thrown = true;
}
envContractAssert($thrown, 'отсутствующий .env не завершился fail-closed');

@unlink($temp);
@unlink($invalid);
foreach ($keys as $suffix) {
    $key = $prefix . $suffix;
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}

echo "[OK] контракт внутреннего загрузчика окружения выполнен\n";
