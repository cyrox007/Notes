<?php

declare(strict_types=1);

use App\Services\LicenseRuntimePolicy;
use App\Services\MaintenanceModeService;
use Core\UpdateArchiveInspector;

$root = dirname(__DIR__, 2);
require_once $root . '/app/services/MaintenanceModeService.php';
require_once $root . '/app/services/LicenseRuntimePolicy.php';
require_once $root . '/core/UpdateArchiveInspector.php';

function preflightAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function preflightRemoveTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            preflightRemoveTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** @param list<array<string,mixed>> $entries */
function preflightBuildZip(string $path, array $entries): void
{
    $body = '';
    $central = '';
    $count = 0;

    foreach ($entries as $spec) {
        $centralName = (string) ($spec['name'] ?? 'file.txt');
        $localName = (string) ($spec['local_name'] ?? $centralName);
        $data = (string) ($spec['data'] ?? 'x');
        $flags = (int) ($spec['flags'] ?? 0);
        $localFlags = (int) ($spec['local_flags'] ?? $flags);
        $method = (int) ($spec['method'] ?? 0);
        $localMethod = (int) ($spec['local_method'] ?? $method);
        $compressed = (int) ($spec['compressed'] ?? strlen($data));
        $uncompressed = (int) ($spec['uncompressed'] ?? strlen($data));
        $localCompressed = (int) ($spec['local_compressed'] ?? $compressed);
        $localUncompressed = (int) ($spec['local_uncompressed'] ?? $uncompressed);
        $crc = crc32($data);
        if ($crc < 0) {
            $crc += 4294967296;
        }
        $centralCrc = (int) ($spec['crc'] ?? $crc);
        $localCrc = (int) ($spec['local_crc'] ?? $centralCrc);
        $localExtra = (string) ($spec['local_extra'] ?? '');
        $centralExtra = (string) ($spec['central_extra'] ?? '');
        $versionMade = (int) ($spec['version_made'] ?? ((3 << 8) | 20));
        $external = (int) ($spec['external'] ?? (0100644 << 16));
        $offset = strlen($body);

        $body .= "PK\x03\x04" . pack(
            'vvvvvVVVvv',
            20,
            $localFlags,
            $localMethod,
            0,
            0,
            $localCrc,
            $localCompressed,
            $localUncompressed,
            strlen($localName),
            strlen($localExtra)
        ) . $localName . $localExtra . $data;

        $central .= "PK\x01\x02" . pack(
            'vvvvvvVVVvvvvvVV',
            $versionMade,
            20,
            $flags,
            $method,
            0,
            0,
            $centralCrc,
            $compressed,
            $uncompressed,
            strlen($centralName),
            strlen($centralExtra),
            0,
            0,
            0,
            $external,
            $offset
        ) . $centralName . $centralExtra;
        $count++;
    }

    $centralOffset = strlen($body);
    $bytes = $body . $central . "PK\x05\x06" . pack(
        'vvvvVVv',
        0,
        0,
        $count,
        $count,
        strlen($central),
        $centralOffset,
        0
    );
    preflightAssert(file_put_contents($path, $bytes) === strlen($bytes), 'unable to write ZIP fixture');
}

function preflightExpectZipFailure(UpdateArchiveInspector $inspector, string $path, string $needle): void
{
    try {
        $inspector->inspect($path);
    } catch (Throwable $e) {
        preflightAssert(
            str_contains(strtolower($e->getMessage()), strtolower($needle)),
            "ZIP rejection did not mention expected reason '{$needle}': {$e->getMessage()}"
        );
        return;
    }
    preflightAssert(false, "unsafe ZIP unexpectedly passed: {$path}");
}

$temp = sys_get_temp_dir() . '/wo-update-preflight-' . bin2hex(random_bytes(6));
preflightAssert(mkdir($temp, 0700, true), 'unable to create updater preflight temp directory');

try {
    $stateRoot = $temp . '/state';
    $maintenance = new MaintenanceModeService($stateRoot, $root);
    $state = $maintenance->state();
    preflightAssert(!$state['active'] && $state['valid'], 'maintenance must start inactive');

    $transaction = 'update-contract-001';
    $entered = $maintenance->enter($transaction, 'Contract update');
    preflightAssert($entered['active'] && $entered['valid'], 'maintenance enter failed');
    preflightAssert($entered['transaction_id'] === $transaction, 'maintenance transaction id changed');
    preflightAssert(is_file((string) $entered['state_path']), 'maintenance marker was not created');

    $again = $maintenance->enter($transaction, 'Contract update');
    preflightAssert($again['started_at'] === $entered['started_at'], 'same transaction enter must be idempotent');

    $otherRejected = false;
    try {
        $maintenance->enter('update-contract-002', 'Other update');
    } catch (Throwable $e) {
        $otherRejected = str_contains($e->getMessage(), 'another');
    }
    preflightAssert($otherRejected, 'second maintenance owner was not rejected');

    $policy = new LicenseRuntimePolicy(
        static fn (): bool => false,
        static fn (): array => ['valid' => true],
        static fn (): array => $maintenance->state()
    );
    $policyState = $policy->state();
    preflightAssert($policyState['enforced'] && !$policyState['writable'], 'maintenance did not block runtime mutations');
    preflightAssert($policyState['code'] === 'maintenance_mode', 'maintenance runtime code is incorrect');

    $wrongLeaveRejected = false;
    try {
        $maintenance->leave('update-contract-wrong');
    } catch (Throwable $e) {
        $wrongLeaveRejected = str_contains($e->getMessage(), 'another');
    }
    preflightAssert($wrongLeaveRejected, 'wrong transaction was able to leave maintenance');

    $marker = (string) $entered['state_path'];
    preflightAssert(file_put_contents($marker, "{broken\n") !== false, 'unable to corrupt maintenance fixture');
    $corrupt = $maintenance->state();
    preflightAssert($corrupt['active'] && !$corrupt['valid'], 'corrupt maintenance marker must fail closed as active');

    $forceRequired = false;
    try {
        $maintenance->leave($transaction);
    } catch (Throwable $e) {
        $forceRequired = str_contains($e->getMessage(), '--force');
    }
    preflightAssert($forceRequired, 'corrupt maintenance marker did not require force recovery');
    $maintenance->leave('', true);
    preflightAssert(!$maintenance->state()['active'], 'forced maintenance recovery failed');

    $insideRejected = false;
    try {
        (new MaintenanceModeService($root . '/cache/update-state-contract', $root))->enter($transaction, 'Unsafe root');
    } catch (Throwable $e) {
        $insideRejected = str_contains($e->getMessage(), 'outside the live application tree');
    }
    preflightAssert($insideRejected, 'maintenance state inside application tree was accepted');
    preflightRemoveTree($root . '/cache/update-state-contract');

    $inspector = new UpdateArchiveInspector();
    $safe = $temp . '/safe.zip';
    preflightBuildZip($safe, [['name' => 'workspace/app.php', 'data' => '<?php echo 1;']]);
    $stats = $inspector->inspect($safe);
    preflightAssert($stats['entries'] === 1 && $stats['files'] === 1, 'safe ZIP stats are incorrect');

    $traversal = $temp . '/traversal.zip';
    preflightBuildZip($traversal, [['name' => '../escape.php', 'data' => 'x']]);
    preflightExpectZipFailure($inspector, $traversal, 'traversal');

    $absolute = $temp . '/absolute.zip';
    preflightBuildZip($absolute, [['name' => '/etc/passwd', 'data' => 'x']]);
    preflightExpectZipFailure($inspector, $absolute, 'absolute');

    $backslash = $temp . '/backslash.zip';
    preflightBuildZip($backslash, [['name' => 'app\\evil.php', 'data' => 'x']]);
    preflightExpectZipFailure($inspector, $backslash, 'absolute');

    $collision = $temp . '/collision.zip';
    preflightBuildZip($collision, [
        ['name' => 'app/File.php', 'data' => 'a'],
        ['name' => 'app/file.php', 'data' => 'b'],
    ]);
    preflightExpectZipFailure($inspector, $collision, 'colliding');

    $descriptor = $temp . '/descriptor.zip';
    preflightBuildZip($descriptor, [['name' => 'app/data.php', 'data' => 'x', 'flags' => 0x0008]]);
    preflightExpectZipFailure($inspector, $descriptor, 'data-descriptor');

    $symlink = $temp . '/symlink.zip';
    preflightBuildZip($symlink, [[
        'name' => 'app/link',
        'data' => 'target',
        'version_made' => ((3 << 8) | 20),
        'external' => (0120777 << 16),
    ]]);
    preflightExpectZipFailure($inspector, $symlink, 'symlink');

    $ratio = $temp . '/ratio.zip';
    preflightBuildZip($ratio, [[
        'name' => 'app/bomb.dat',
        'data' => 'x',
        'method' => 8,
        'compressed' => 1,
        'uncompressed' => 1000,
    ]]);
    preflightExpectZipFailure($inspector, $ratio, 'ratio');

    $localMismatch = $temp . '/local-mismatch.zip';
    preflightBuildZip($localMismatch, [[
        'name' => 'safe.php',
        'local_name' => 'evil.php',
        'data' => 'x',
    ]]);
    preflightExpectZipFailure($inspector, $localMismatch, 'names differ');

    $source = (string) file_get_contents($root . '/index.php');
    $maintenancePos = strpos($source, 'MaintenanceModeService');
    $corePos = strpos($source, "require_once SITEPATH . '/core.php'");
    preflightAssert(is_int($maintenancePos) && is_int($corePos) && $maintenancePos < $corePos, 'maintenance gate is not before core/database bootstrap');

    $updateSource = (string) file_get_contents($root . '/bin/update.php');
    preflightAssert(str_contains($updateSource, 'UpdateArchiveInspector'), 'update CLI does not invoke archive inspector');
    preflightAssert(!str_contains($updateSource, 'extractTo('), 'update preflight must not extract archives');

    echo "[OK] updater transaction preflight contract\n";
} finally {
    preflightRemoveTree($temp);
    preflightRemoveTree($root . '/cache/update-state-contract');
}
