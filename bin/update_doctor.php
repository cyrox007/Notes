<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/core/Environment.php';
if (is_file($root . '/.env')) {
    \Core\Environment::load($root . '/.env');
}
require_once $root . '/core/UpdateManifestVerifier.php';
require_once $root . '/core/UpdateDownloadCredentials.php';
require_once $root . '/core/UpdateReadiness.php';

$options = getopt('', ['json', 'help']);
if (isset($options['help'])) {
    echo "Workspace Organizer updater readiness doctor\n\n";
    echo "  php bin/update_doctor.php [--json]\n\n";
    echo "Read-only: no network requests, downloads, maintenance transitions, DB mutations or filesystem changes are performed.\n";
    exit(0);
}

try {
    $result = (new \Core\UpdateReadiness($root))->inspect();
    if (isset($options['json'])) {
        echo json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } else {
        echo 'Workspace Organizer updater readiness' . PHP_EOL;
        foreach ($result['checks'] as $name => $check) {
            echo sprintf("  [%s] %s%s\n", $check['ok'] ? 'OK' : 'FAIL', $name, $check['message'] !== '' ? ' — ' . $check['message'] : '');
        }
        echo 'Remote check: ' . ($result['ready_for_check'] ? 'READY' : 'NOT READY') . PHP_EOL;
        echo 'Live apply:   ' . ($result['ready_for_apply'] ? 'READY' : 'NOT READY') . PHP_EOL;
    }
    exit($result['ready_for_apply'] ? 0 : 3);
} catch (Throwable $e) {
    if (isset($options['json'])) {
        echo json_encode([
            'ready_for_check' => false,
            'ready_for_apply' => false,
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
