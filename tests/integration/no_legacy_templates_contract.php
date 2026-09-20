<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$legacy = [];
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->isLink()) {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_contains($path, '/.git/') || str_contains($path, '/vendor/')) {
        continue;
    }
    if (strtolower($file->getExtension()) === 'tpl') {
        $legacy[] = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
    }
}
sort($legacy, SORT_STRING);
if ($legacy !== []) {
    fwrite(STDERR, "[FAIL] legacy template files remain:\n - " . implode("\n - ", $legacy) . "\n");
    exit(1);
}

foreach (['Smarty', 'smarty', 'render_smarty'] as $marker) {
    $core = (string) file_get_contents($root . '/core.php');
    if (str_contains($core, $marker)) {
        fwrite(STDERR, "[FAIL] core runtime still references legacy template engine marker {$marker}\n");
        exit(1);
    }
}

fwrite(STDOUT, "[OK] no legacy .tpl runtime remains\n");
