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
require_once $root . '/core/UpdateProcessRunner.php';
require_once $root . '/app/services/MaintenanceModeService.php';

use App\Services\MaintenanceModeService;
use Core\UpdateProcessRunner;
use RuntimeException;
use Throwable;

$options = getopt('', [
    'transaction:',
    'feed-url:',
    'channel:',
    'stage-root:',
    'state-root:',
    'backup-root:',
    'candidate-root:',
    'recover',
    'yes',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Workspace Organizer end-to-end signed updater operator flow\n\n";
    echo "Install the next signed update:\n";
    echo "  php bin/update_run.php --yes [--transaction=update-...] [--json]\n\n";
    echo "Recover an interrupted live-update transaction:\n";
    echo "  php bin/update_run.php --recover --transaction=update-... --yes [--json]\n\n";
    echo "Optional overrides: --feed-url, --channel, --stage-root, --state-root, --backup-root, --candidate-root.\n";
    echo "Normal mode stages the signed package, enters maintenance, creates verified code+DB rollback backup,\n";
    echo "builds an external release candidate, then invokes the existing transactional live apply boundary.\n";
    echo "The --yes flag is mandatory because this command can switch live code and run migrations.\n";
    exit(0);
}

$json = isset($options['json']);

/** @return never */
function updateRunFail(string $message, string $code = 'update_run_failed', int $exitCode = 1, array $details = []): never
{
    global $json;
    if ($json) {
        echo json_encode([
            'status' => 'fail',
            'code' => $code,
            'message' => $message,
        ] + $details, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, "[FAIL] {$message}\n");
    }
    exit($exitCode);
}

/** @return array<string,mixed> */
function updateRunJsonCommand(
    UpdateProcessRunner $runner,
    array $command,
    string $cwd,
    int $timeout,
    string $label
): array {
    $result = $runner->run($command, $cwd, $timeout);
    $payload = null;
    try {
        $decoded = json_decode(trim($result['stdout']), true, 64, JSON_THROW_ON_ERROR);
        if (is_array($decoded) && !array_is_list($decoded)) {
            $payload = $decoded;
        }
    } catch (Throwable) {
        $payload = null;
    }

    if ($result['code'] !== 0) {
        $message = is_array($payload) && is_string($payload['message'] ?? null)
            ? $payload['message']
            : $runner->failureDetails($result);
        $code = is_array($payload) && is_string($payload['code'] ?? null)
            ? $payload['code']
            : 'subprocess_failed';
        throw new RuntimeException($label . ': ' . $message, $result['code'] > 0 ? $result['code'] : 1);
    }
    if (!is_array($payload)) {
        throw new RuntimeException($label . ': command returned invalid JSON');
    }
    return $payload;
}

/** @return list<string> */
function updateRunBaseCommand(string $script): array
{
    global $root;
    return [PHP_BINARY, $root . '/bin/' . $script];
}

function updateRunAppendOption(array &$command, array $options, string $name): void
{
    $value = trim((string) ($options[$name] ?? ''));
    if ($value !== '') {
        $command[] = '--' . $name . '=' . $value;
    }
}

if (!isset($options['yes'])) {
    updateRunFail(
        'Refusing destructive updater flow without explicit --yes confirmation.',
        'confirmation_required',
        2
    );
}

$recover = isset($options['recover']);
$transactionId = trim((string) ($options['transaction'] ?? ''));
if ($recover) {
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
        updateRunFail('Recovery requires a valid --transaction id.', 'invalid_transaction', 2);
    }
} elseif ($transactionId === '') {
    $transactionId = 'update-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
} elseif (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
    updateRunFail('Invalid --transaction id.', 'invalid_transaction', 2);
}

$runner = new UpdateProcessRunner();

if ($recover) {
    try {
        $command = updateRunBaseCommand('update_apply.php');
        $command[] = '--transaction=' . $transactionId;
        $command[] = '--recover';
        $command[] = '--json';
        updateRunAppendOption($command, $options, 'state-root');
        updateRunAppendOption($command, $options, 'backup-root');

        $recovered = updateRunJsonCommand($runner, $command, $root, 1200, 'recovery');
        if ($json) {
            echo json_encode([
                'status' => 'recovered',
                'transaction_id' => $transactionId,
                'result' => $recovered,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        } else {
            echo "[OK] Updater recovery completed\n";
            echo "Transaction: {$transactionId}\n";
            echo 'State:       ' . (string) ($recovered['status'] ?? 'recovered') . PHP_EOL;
        }
        exit(0);
    } catch (Throwable $e) {
        updateRunFail($e->getMessage(), 'recovery_failed', max(1, (int) $e->getCode()));
    }
}

$maintenanceEntered = false;
$applyInvoked = false;
$stateRoot = trim((string) ($options['state-root'] ?? ''));

try {
    $stageCommand = updateRunBaseCommand('update_remote.php');
    $stageCommand[] = '--json';
    foreach (['feed-url', 'channel', 'stage-root'] as $option) {
        updateRunAppendOption($stageCommand, $options, $option);
    }
    $staged = updateRunJsonCommand($runner, $stageCommand, $root, 900, 'remote staging');
    if (($staged['status'] ?? null) !== 'staged') {
        throw new RuntimeException('Remote updater did not return a verified staged package');
    }
    $stageDir = trim((string) ($staged['stage_dir'] ?? ''));
    if ($stageDir === '') {
        throw new RuntimeException('Verified stage path is missing');
    }

    $maintenance = new MaintenanceModeService($stateRoot !== '' ? $stateRoot : null, $root);
    $maintenance->enter($transactionId, 'Обновление Workspace Organizer');
    $maintenanceEntered = true;

    $backupCommand = updateRunBaseCommand('update_backup.php');
    $backupCommand[] = '--transaction=' . $transactionId;
    $backupCommand[] = '--stage-dir=' . $stageDir;
    $backupCommand[] = '--json';
    foreach (['state-root', 'backup-root'] as $option) {
        updateRunAppendOption($backupCommand, $options, $option);
    }
    $backup = updateRunJsonCommand($runner, $backupCommand, $root, 1200, 'rollback backup');
    if (($backup['status'] ?? null) !== 'backup_verified') {
        throw new RuntimeException('Updater rollback backup did not reach backup_verified');
    }

    $candidateCommand = updateRunBaseCommand('update_candidate.php');
    $candidateCommand[] = '--transaction=' . $transactionId;
    $candidateCommand[] = '--json';
    foreach (['state-root', 'candidate-root'] as $option) {
        updateRunAppendOption($candidateCommand, $options, $option);
    }
    $candidate = updateRunJsonCommand($runner, $candidateCommand, $root, 900, 'release candidate');
    if (($candidate['status'] ?? null) !== 'candidate_verified') {
        throw new RuntimeException('Updater release candidate did not reach candidate_verified');
    }
    $candidateDir = trim((string) ($candidate['candidate_dir'] ?? ''));
    if ($candidateDir === '') {
        throw new RuntimeException('Verified candidate path is missing');
    }

    $applyCommand = updateRunBaseCommand('update_apply.php');
    $applyCommand[] = '--transaction=' . $transactionId;
    $applyCommand[] = '--candidate-dir=' . $candidateDir;
    $applyCommand[] = '--apply';
    $applyCommand[] = '--json';
    foreach (['state-root', 'backup-root'] as $option) {
        updateRunAppendOption($applyCommand, $options, $option);
    }

    // After this point only UpdateApplyCommand owns rollback/recovery and
    // maintenance release. The wrapper must never force-open writes on failure.
    $applyInvoked = true;
    $applied = updateRunJsonCommand($runner, $applyCommand, $root, 1800, 'live apply');

    if ($json) {
        echo json_encode([
            'status' => 'committed',
            'transaction_id' => $transactionId,
            'target_version' => $staged['target_version'] ?? null,
            'package_sha256' => $staged['package_sha256'] ?? null,
            'apply' => $applied,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        echo "[OK] Signed update committed\n";
        echo "Transaction: {$transactionId}\n";
        echo 'Version:     ' . (string) ($applied['installed_version'] ?? ($staged['target_version'] ?? 'unknown')) . PHP_EOL;
        echo "Rollback checkpoint and transaction journal were verified before live mutation.\n";
    }
    exit(0);
} catch (Throwable $e) {
    if ($maintenanceEntered && !$applyInvoked) {
        try {
            $maintenance = new MaintenanceModeService($stateRoot !== '' ? $stateRoot : null, $root);
            $maintenance->leave($transactionId);
        } catch (Throwable $leaveError) {
            updateRunFail(
                $e->getMessage() . '; additionally failed to release pre-mutation maintenance: ' . $leaveError->getMessage(),
                'preapply_cleanup_failed',
                max(1, (int) $e->getCode()),
                ['transaction_id' => $transactionId, 'maintenance_may_be_active' => true]
            );
        }
    }

    updateRunFail(
        $e->getMessage(),
        $applyInvoked ? 'apply_or_rollback_failed' : 'preapply_failed',
        max(1, (int) $e->getCode()),
        [
            'transaction_id' => $transactionId,
            'apply_invoked' => $applyInvoked,
            'recovery_command' => $applyInvoked
                ? 'php bin/update_run.php --recover --transaction=' . $transactionId . ' --yes --json'
                : null,
        ]
    );
}
