<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$htaccess = file_get_contents($root . '/.htaccess');
if (!is_string($htaccess)) {
    fwrite(STDERR, "[FAIL] deployment surface contract: .htaccess is unreadable\n");
    exit(1);
}

foreach (['config', 'tests', 'tools'] as $directory) {
    if (preg_match('/RewriteRule \^\(\?:[^\n]*\b' . preg_quote($directory, '/') . '\b[^\n]*\)\(\?:\/\|\$\) - \[F,L,NC\]/', $htaccess) !== 1) {
        fwrite(STDERR, "[FAIL] deployment surface contract: {$directory}/ is not denied by Apache\n");
        exit(1);
    }
}

if (!str_contains($htaccess, 'core\\.php')) {
    fwrite(STDERR, "[FAIL] deployment surface contract: direct core.php access is not denied\n");
    exit(1);
}

fwrite(STDOUT, "[OK] deployment surface excludes internal source/tooling paths\n");
