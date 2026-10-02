<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Команда доступна только из CLI.\n");
    exit(2);
}
if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Для сборки offline-пакета требуется PHP extension zip.\n");
    exit(1);
}

$options = getopt('', ['manifest:', 'signature:', 'package:', 'output:', 'help']);
if (isset($options['help'])) {
    echo "Использование:\n";
    echo "  php tools/release/build-offline-update-bundle.php --manifest=manifest.json --signature=manifest.sig --package=release.zip --output=workspace-offline-update.zip\n";
    exit(0);
}

$manifest = (string) ($options['manifest'] ?? '');
$signature = (string) ($options['signature'] ?? '');
$package = (string) ($options['package'] ?? '');
$output = (string) ($options['output'] ?? '');

foreach (['manifest' => $manifest, 'signature' => $signature, 'package' => $package] as $label => $path) {
    if ($path === '' || !is_file($path) || is_link($path)) {
        fwrite(STDERR, "[FAIL] Файл {$label} недоступен: {$path}\n");
        exit(1);
    }
}
if ($output === '' || file_exists($output) || is_link($output)) {
    fwrite(STDERR, "[FAIL] Укажите новый выходной ZIP-файл.\n");
    exit(1);
}

$manifestBytes = file_get_contents($manifest);
$data = is_string($manifestBytes) ? json_decode($manifestBytes, true) : null;
$expectedName = is_array($data) ? (string) ($data['package']['filename'] ?? '') : '';
if ($expectedName === '' || basename($package) !== $expectedName) {
    fwrite(STDERR, "[FAIL] Имя ZIP релиза не совпадает с package.filename в manifest.\n");
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    fwrite(STDERR, "[FAIL] Не удалось создать offline-пакет.\n");
    exit(1);
}
try {
    if (!$zip->addFile($manifest, 'manifest.json')
        || !$zip->addFile($signature, 'manifest.sig')
        || !$zip->addFile($package, $expectedName)) {
        throw new RuntimeException('Не удалось добавить файлы в offline-пакет');
    }
} catch (Throwable $e) {
    $zip->close();
    @unlink($output);
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
$zip->close();

echo "[OK] Offline-пакет создан: {$output}\n";
