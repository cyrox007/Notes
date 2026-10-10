<?php

declare(strict_types=1);

// Standalone repair: run from outside the live application, using PHP CLI.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$options = getopt('', ['root:', 'yes', 'help']);
if (isset($options['help']) || !isset($options['root'], $options['yes'])) {
    echo "php repair-updater-stage.php --root=/path/to/notes --yes\n";
    echo "Repairs staging identity and DEFAULT_GENERATED backups on 1.0.13/14/15.\n";
    exit(isset($options['help']) ? 0 : 2);
}
$root = realpath((string) $options['root']);
if ($root === false || is_link((string) $options['root'])) {
    throw new RuntimeException('Invalid application root');
}
$versionPath = $root . '/core/Version.php';
$version = (string) file_get_contents($versionPath);
if (preg_match("/public const VERSION = '(1\.0\.(?:13|14|15))';/", $version, $match) !== 1) {
    throw new RuntimeException('Only versions 1.0.13, 1.0.14 and 1.0.15 are supported');
}
$rules = [
    'core/UpdatePackageStager.php' => [
        "\$stageName = sprintf('%d-%s', \$targetVersionCode, substr(\$verified['sha256'], 0, 16));",
        "\$signedIdentity = hash('sha256', \$manifestBytes . \"\\0\" . trim(\$signatureToken));\n"
            . "        \$stageName = sprintf('%d-%s-%s', \$targetVersionCode,\n"
            . "            substr(\$verified['sha256'], 0, 16), substr(\$signedIdentity, 0, 16));",
        "\$signedIdentity = hash('sha256', \$manifestBytes . \"\\0\" . trim(\$signatureToken));",
    ],
    'core/UpdateBackupManager.php' => [
        "if (str_contains(\$extra, 'GENERATED')) {",
        "if (preg_match('/\\b(?:VIRTUAL|STORED)\\s+GENERATED\\b/', \$extra) === 1) {",
        "if (preg_match('/\\b(?:VIRTUAL|STORED)\\s+GENERATED\\b/', \$extra) === 1) {",
    ],
];
$pending = [];
foreach ($rules as $relative => [$before, $after, $marker]) {
    $path = $root . '/' . $relative;
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Unsafe or missing file: ' . $relative);
    }
    $bytes = file_get_contents($path);
    if (!is_string($bytes)) {
        throw new RuntimeException('Cannot read ' . $relative);
    }
    if (str_contains($bytes, $marker) && !str_contains($bytes, $before)) {
        continue;
    }
    if (substr_count($bytes, $before) !== 1) {
        throw new RuntimeException('Unexpected source; no changes made: ' . $relative);
    }
    $pending[$relative] = [$bytes, str_replace($before, $after, $bytes)];
}
if ($pending === []) {
    echo "[OK] Both repairs already installed; version unchanged.\n";
    exit;
}
$backup = dirname($root) . '/notes-updater-repair-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
if (!mkdir($backup, 0700)) {
    throw new RuntimeException('Cannot create repair backup outside application');
}
$written = [];
try {
    // Validate both replacements and save both originals before modifying live files.
    foreach ($pending as $relative => [$original, $replacement]) {
        $name = basename($relative);
        if (file_put_contents($backup . '/' . $name, $original, LOCK_EX) !== strlen($original)
            || file_put_contents($backup . '/patched-' . $name, $replacement, LOCK_EX) !== strlen($replacement)) {
            throw new RuntimeException('Cannot save repair files');
        }
        $process = proc_open([PHP_BINARY, '-l', $backup . '/patched-' . $name],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('PHP lint unavailable');
        }
        fclose($pipes[0]);
        $lint = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('PHP lint failed: ' . $lint);
        }
    }
    foreach ($pending as $relative => [$original, $replacement]) {
        $path = $root . '/' . $relative;
        if (!hash_equals(hash('sha256', $original), (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Application file changed during repair');
        }
        $temp = $path . '.repair-' . bin2hex(random_bytes(6));
        if (file_put_contents($temp, $replacement, LOCK_EX) !== strlen($replacement)) {
            throw new RuntimeException('Cannot prepare replacement');
        }
        // rename replaces atomically on supported filesystems; never unlink the live file first.
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new RuntimeException('Cannot atomically replace ' . $relative);
        }
        $written[] = $relative;
        if (!hash_equals(hash('sha256', $replacement), (string) hash_file('sha256', $path))) {
            throw new RuntimeException('Replacement verification failed');
        }
    }
} catch (Throwable $error) {
    foreach (array_reverse($written) as $relative) {
        $restore = $root . '/' . $relative . '.restore-' . bin2hex(random_bytes(6));
        if (!copy($backup . '/' . basename($relative), $restore)
            || !@rename($restore, $root . '/' . $relative)) {
            throw new RuntimeException('Repair failed; restore original files from ' . $backup, 0, $error);
        }
    }
    throw $error;
}
echo '[OK] Repairs installed. Application version ' . $match[1] . " unchanged; database untouched.\n";
echo 'Original updater files: ' . $backup . "\n";
