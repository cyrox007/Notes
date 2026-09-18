<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/SecurityHeaders.php';

use Core\SecurityHeaders;

function cspAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] CSP contract: {$message}\n");
        exit(1);
    }
}

$nonce = SecurityHeaders::nonce();
cspAssert(preg_match('/^[A-Za-z0-9+\/]{24}$/D', $nonce) === 1, 'nonce must contain 144 bits encoded as standard base64');

$policy = SecurityHeaders::policy();
cspAssert(!str_contains($policy, "'unsafe-inline'"), 'policy must not contain unsafe-inline');
cspAssert(str_contains($policy, "script-src 'self' 'nonce-{$nonce}'"), 'script-src nonce is missing');
cspAssert(str_contains($policy, "style-src 'self' 'nonce-{$nonce}'"), 'style-src nonce is missing');
cspAssert(str_contains($policy, "script-src-attr 'none'"), 'script event attributes are not explicitly forbidden');
cspAssert(str_contains($policy, "style-src-attr 'none'"), 'style attributes are not explicitly forbidden');
cspAssert(str_contains($policy, "object-src 'none'"), 'object-src must remain disabled');
cspAssert(str_contains($policy, "base-uri 'self'"), 'base-uri restriction is missing');

$htaccess = (string) file_get_contents($root . '/.htaccess');
cspAssert(!str_contains($htaccess, 'unsafe-inline'), '.htaccess still permits unsafe-inline');
cspAssert(
    !preg_match('/Header\s+always\s+set\s+Content-Security-Policy/i', $htaccess),
    '.htaccess must not override the per-request nonce policy'
);

$index = (string) file_get_contents($root . '/index.php');
$installer = (string) file_get_contents($root . '/install.php');
cspAssert(str_contains($index, '\\Core\\SecurityHeaders::apply();'), 'index.php does not apply the Core CSP');
cspAssert(str_contains($installer, '\\Core\\SecurityHeaders::apply();'), 'install.php does not apply the Core CSP');

$paths = [
    $root . '/index.php',
    $root . '/install.php',
    $root . '/app/middlewares/EnforceMaintenanceMode.php',
];

$viewRoots = [
    $root . '/app/views',
    $root . '/modules',
];
foreach ($viewRoots as $viewRoot) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($viewRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
            continue;
        }
        $path = $file->getPathname();
        if (!str_ends_with($path, '.php')) {
            continue;
        }
        if ($viewRoot === $root . '/modules' && !str_contains(str_replace('\\', '/', $path), '/views/')) {
            continue;
        }
        $paths[] = $path;
    }
}

$paths = array_values(array_unique($paths));
sort($paths);

foreach ($paths as $path) {
    $source = file_get_contents($path);
    cspAssert(is_string($source), 'cannot read ' . $path);
    $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');

    cspAssert(
        preg_match('/\sstyle\s*=\s*["\']/i', $source) !== 1,
        "{$relative} contains an inline style attribute"
    );
    cspAssert(
        preg_match('/\son[a-z0-9_-]+\s*=\s*["\']/i', $source) !== 1,
        "{$relative} contains an inline event handler"
    );
    cspAssert(
        preg_match('/javascript\s*:/i', $source) !== 1,
        "{$relative} contains a javascript: URL"
    );

    preg_match_all('/<(script|style)\b([^>]*)>/i', $source, $tags, PREG_SET_ORDER);
    foreach ($tags as $tag) {
        $kind = strtolower((string) $tag[1]);
        $attributes = (string) $tag[2];
        if ($kind === 'script' && preg_match('/\bsrc\s*=/i', $attributes) === 1) {
            continue;
        }
        cspAssert(
            preg_match('/\bnonce\s*=/i', $attributes) === 1,
            "{$relative} contains inline {$kind} without a CSP nonce"
        );
    }
}

$jsPaths = [
    $root . '/assets/js/usability-actions.js',
    $root . '/modules/tasks/assets/tasks-page.js',
    $root . '/modules/notes/assets/notes-list.js',
];
foreach ($jsPaths as $path) {
    $source = (string) file_get_contents($path);
    $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    cspAssert(!preg_match('/setAttribute\(\s*["\']style["\']/i', $source), "{$relative} sets a style attribute");
    cspAssert(!preg_match('/\.style\.cssText\s*=/i', $source), "{$relative} sets cssText");
}

fwrite(STDOUT, "[OK] CSP uses per-request nonces and forbids inline attributes\n");
