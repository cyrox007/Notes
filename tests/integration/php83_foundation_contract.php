<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function requireMarker(string $path, string $marker, string $message): void
{
    $content = file_get_contents($path);
    if ($content === false || !str_contains($content, $marker)) {
        fwrite(STDERR, "PHP 8.3 foundation contract failed: {$message}\n");
        exit(1);
    }
}

if (PHP_VERSION_ID < 80300) {
    fwrite(STDERR, 'PHP 8.3 foundation contract requires PHP 8.3+; running ' . PHP_VERSION . PHP_EOL);
    exit(1);
}

requireMarker($root . '/composer.json', '"php": ">=8.3"', 'Composer must define PHP 8.3 as the 1.2 runtime floor');
requireMarker($root . '/docs/SYSTEM_REQUIREMENTS.md', 'Технический минимум разрабатываемой линии 1.2: **PHP 8.3.x**', 'system requirements must state the 1.2 minimum');
requireMarker($root . '/docs/ROADMAP.md', 'PHP 8.3.x', 'roadmap must keep PHP 8.3.x in the 1.2 scope');
requireMarker($root . '/docs/plans/1.2-filespaces.md', 'FileSpace', 'Files 1.2 spaces plan must be present');
requireMarker($root . '/docs/plans/1.2-filespaces.md', 'изменять чужие объекты', 'Files ACL must distinguish own and foreign objects');
requireMarker($root . '/docs/plans/1.2-filespaces.md', 'наследует', 'Files ACL inheritance must be documented');

echo 'PHP 8.3 / Filespaces foundation contract OK on ' . PHP_VERSION . PHP_EOL;
