<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/UpdatePath.php';
$temporary = sys_get_temp_dir() . '/notes-full-repair-test-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);

function repairAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function repairRun(string $root, string $app): array
{
    $process = proc_open([PHP_BINARY, $root . '/tools/release/repair-updater-full.php', '--yes', '--root=' . $app],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    repairAssert(is_resource($process), 'Cannot start repair');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output];
}

try {
    foreach ([13, 14, 15] as $patch) {
        $app = $temporary . '/app-' . $patch;
        mkdir($app . '/core', 0700, true);
        mkdir($app . '/app/services', 0700, true);
        $version = "<?php\nclass Version { public const VERSION = '1.0.{$patch}'; public const VERSION_CODE = 100{$patch}; }\n";
        file_put_contents($app . '/core/Version.php', $version);
        file_put_contents($app . '/.env', "REPAIR_SENTINEL=unchanged\n");
        mkdir($app . '/modules/admin/services', 0700, true);
        file_put_contents($app . '/modules/admin/services/AdminUpdateService.php',
            '<?php class AdminUpdateService { private function applyWebSynchronously(int $actorId, int $code, string $sha): array { return []; } }');

        foreach (['core/Environment.php', 'core/HostingCompatibility.php', 'app/services/MaintenanceModeService.php'] as $relative) {
            copy($root . '/' . $relative, $app . '/' . $relative);
        }
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            [$code, $output] = repairRun($root, $app);
            repairAssert($code === 0, 'Full repair failed: ' . $output);
            repairAssert(file_get_contents($app . '/core/Version.php') === $version, 'Version changed');
            repairAssert(file_get_contents($app . '/.env') === "REPAIR_SENTINEL=unchanged\n", '.env changed');
        }
        foreach (['bin/update_web_entry.php', 'core/UpdateWebRuntimeLauncher.php', 'core/UpdateExternalRuntime.php',
            'core/UpdateBackupManager.php', 'core/UpdatePackageStager.php', 'core/DatabaseOwnership.php',
            'assets/js/update-web-runner.js', 'core/UpdateReadiness.php', 'core/UpdateTransactionStateMachine.php', 'bin/migrate.php'] as $relative) {
            repairAssert(hash_file('sha256', $app . '/' . $relative) === hash_file('sha256', $root . '/' . $relative),
                'Missing or changed updater dependency: ' . $relative);
        }
        $service = file_get_contents($app . '/modules/admin/services/AdminUpdateService.php');
        repairAssert(substr_count($service, 'updater-repair-windows-sync-guard') === 1, 'Synchronous guard missing or duplicated');
        repairAssert(!is_dir($app . '/database'), 'Repair copied migrations');
    }

    $blocked = $temporary . '/blocked';
    mkdir($blocked . '/core', 0700, true);
    file_put_contents($blocked . '/core/Version.php', "<?php\nclass Version { public const VERSION = '1.0.13'; public const VERSION_CODE = 10013; }\n");
    mkdir($blocked . '/core/UpdateApplyCommand.php');
    [$code] = repairRun($root, $blocked);
    repairAssert($code !== 0, 'Unsafe target accepted');
    repairAssert(!file_exists($blocked . '/bin/update_web_entry.php'), 'Failed repair left newly created files');

    echo "[OK] Full updater repair: versions 1.0.13/14/15, immutable .env/version, repeat apply, dependency payload and failed install rollback\n";
} finally {
    Core\UpdatePath::removeTree($temporary);
}
