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

$options = getopt('', [
    'transaction:',
    'feed-url:',
    'channel:',
    'stage-root:',
    'state-root:',
    'backup-root:',
    'candidate-root:',
    'expected-version-code:',
    'expected-package-sha256:',
    'recover',
    'yes',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Workspace Organizer — сквозной сценарий установки подписанного обновления\n\n";
    echo "Установить следующее подписанное обновление:\n";
    echo "  php bin/update_run.php --yes [--transaction=update-...] [--json]\n\n";
    echo "Восстановить прерванную транзакцию обновления:\n";
    echo "  php bin/update_run.php --recover --transaction=update-... --yes [--json]\n\n";
    echo "Дополнительные параметры: --feed-url, --channel, --stage-root, --state-root, --backup-root, --candidate-root.\n";
    echo "Для веб-интерфейса доступны привязки подтверждённого релиза: --expected-version-code и --expected-package-sha256.\n";
    echo "Обычный режим проверяет и подготавливает пакет, включает режим обслуживания, создаёт проверенную резервную копию,\n";
    echo "формирует внешний кандидат релиза и передаёт управление существующему транзакционному применению.\n";
    echo "Флаг --yes обязателен, потому что команда может переключать рабочий код и запускать миграции.\n";
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
        fwrite(STDERR, "[ОШИБКА] {$message}\n");
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
        throw new RuntimeException($label . ': команда вернула некорректный JSON');
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

/**
 * Пытается автоматически восстановить транзакцию после ошибки применения.
 *
 * Восстановление возобновляемое по журналу, поэтому повторные попытки безопасны:
 * каждая продолжает уже зафиксированное состояние, а не начинает откат заново.
 *
 * @return array<string,mixed>
 */
function updateRunAutomaticRecover(
    UpdateProcessRunner $runner,
    array $options,
    string $transactionId,
    string $root,
    int $attempts = 3
): array {
    $errors = [];

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        try {
            $command = updateRunBaseCommand('update_apply.php');
            $command[] = '--transaction=' . $transactionId;
            $command[] = '--recover';
            $command[] = '--json';
            updateRunAppendOption($command, $options, 'state-root');
            updateRunAppendOption($command, $options, 'backup-root');

            $result = updateRunJsonCommand(
                $runner,
                $command,
                $root,
                1200,
                'автоматическое восстановление'
            );
            $result['automatic_recovery_attempt'] = $attempt;
            return $result;
        } catch (Throwable $recoveryError) {
            $errors[] = 'попытка ' . $attempt . ': ' . $recoveryError->getMessage();
            if ($attempt < $attempts) {
                sleep(2);
            }
        }
    }

    throw new RuntimeException(
        'Автоматическое восстановление не завершилось после '
        . $attempts
        . ' попыток: '
        . implode('; ', $errors)
    );
}

if (!isset($options['yes'])) {
    updateRunFail(
        'Установка обновления требует явного подтверждения --yes.',
        'confirmation_required',
        2
    );
}

$recover = isset($options['recover']);
$transactionId = trim((string) ($options['transaction'] ?? ''));
if ($recover) {
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
        updateRunFail('Для восстановления нужен корректный --transaction.', 'invalid_transaction', 2);
    }
} elseif ($transactionId === '') {
    $transactionId = 'update-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
} elseif (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', $transactionId) !== 1) {
    updateRunFail('Некорректный идентификатор транзакции.', 'invalid_transaction', 2);
}

$expectedVersionRaw = trim((string) ($options['expected-version-code'] ?? ''));
$expectedSha256 = strtolower(trim((string) ($options['expected-package-sha256'] ?? '')));
$hasExpectedVersion = $expectedVersionRaw !== '';
$hasExpectedSha = $expectedSha256 !== '';

if ($hasExpectedVersion !== $hasExpectedSha) {
    updateRunFail(
        'Привязка релиза требует одновременно --expected-version-code и --expected-package-sha256.',
        'invalid_review_binding',
        2
    );
}

$expectedVersionCode = null;
if ($hasExpectedVersion) {
    if (preg_match('/^[1-9][0-9]*$/', $expectedVersionRaw) !== 1
        || preg_match('/^[0-9a-f]{64}$/', $expectedSha256) !== 1) {
        updateRunFail('Некорректная привязка подтверждённого релиза.', 'invalid_review_binding', 2);
    }
    $expectedVersionCode = (int) $expectedVersionRaw;
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

        $recovered = updateRunJsonCommand($runner, $command, $root, 1200, 'восстановление');
        if ($json) {
            echo json_encode([
                'status' => 'recovered',
                'transaction_id' => $transactionId,
                'result' => $recovered,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        } else {
            echo "[OK] Восстановление обновлятора завершено\n";
            echo "Транзакция: {$transactionId}\n";
            echo 'Состояние:   ' . (string) ($recovered['status'] ?? 'recovered') . PHP_EOL;
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
    $doctorCommand = updateRunBaseCommand('update_doctor.php');
    $doctorCommand[] = '--json';
    $readiness = updateRunJsonCommand($runner, $doctorCommand, $root, 30, 'проверка готовности');
    if (empty($readiness['ready_for_apply'])) {
        throw new RuntimeException('Проверка готовности не разрешила установку обновления');
    }

    $stageCommand = updateRunBaseCommand('update_remote.php');
    $stageCommand[] = '--json';
    foreach (['feed-url', 'channel', 'stage-root'] as $option) {
        updateRunAppendOption($stageCommand, $options, $option);
    }
    $staged = updateRunJsonCommand($runner, $stageCommand, $root, 900, 'подготовка пакета');
    if (($staged['status'] ?? null) !== 'staged') {
        throw new RuntimeException('Обновлятор не вернул проверенный подготовленный пакет');
    }
    $stageDir = trim((string) ($staged['stage_dir'] ?? ''));
    if ($stageDir === '') {
        throw new RuntimeException('Не найден путь к проверенному подготовленному пакету');
    }

    if ($expectedVersionCode !== null) {
        $stagedVersionCode = (int) ($staged['target_version_code'] ?? 0);
        $stagedSha256 = strtolower(trim((string) ($staged['package_sha256'] ?? '')));
        if ($stagedVersionCode !== $expectedVersionCode || !hash_equals($expectedSha256, $stagedSha256)) {
            throw new RuntimeException(
                'Подписанный канал изменился после подтверждения обновления. Повторите проверку в админ-панели.'
            );
        }
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
    $backup = updateRunJsonCommand($runner, $backupCommand, $root, 1200, 'резервная копия');
    if (($backup['status'] ?? null) !== 'backup_verified') {
        throw new RuntimeException('Резервная копия обновления не прошла проверку');
    }

    $candidateCommand = updateRunBaseCommand('update_candidate.php');
    $candidateCommand[] = '--transaction=' . $transactionId;
    $candidateCommand[] = '--json';
    foreach (['state-root', 'candidate-root'] as $option) {
        updateRunAppendOption($candidateCommand, $options, $option);
    }
    $candidate = updateRunJsonCommand($runner, $candidateCommand, $root, 900, 'кандидат релиза');
    if (($candidate['status'] ?? null) !== 'candidate_verified') {
        throw new RuntimeException('Кандидат релиза не прошёл проверку');
    }
    $candidateDir = trim((string) ($candidate['candidate_dir'] ?? ''));
    if ($candidateDir === '') {
        throw new RuntimeException('Не найден путь к проверенному кандидату релиза');
    }

    $applyCommand = updateRunBaseCommand('update_apply.php');
    $applyCommand[] = '--transaction=' . $transactionId;
    $applyCommand[] = '--candidate-dir=' . $candidateDir;
    $applyCommand[] = '--apply';
    $applyCommand[] = '--json';
    foreach (['state-root', 'backup-root'] as $option) {
        updateRunAppendOption($applyCommand, $options, $option);
    }

    // После этой точки откат, восстановление и снятие режима обслуживания принадлежат
    // только UpdateApplyCommand. Обёртка не должна самовольно открывать запись при ошибке.
    $applyInvoked = true;
    $applied = updateRunJsonCommand($runner, $applyCommand, $root, 1800, 'применение обновления');

    if ($json) {
        echo json_encode([
            'status' => 'committed',
            'transaction_id' => $transactionId,
            'target_version' => $staged['target_version'] ?? null,
            'target_version_code' => $staged['target_version_code'] ?? null,
            'package_sha256' => $staged['package_sha256'] ?? null,
            'apply' => $applied,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        echo "[OK] Подписанное обновление установлено\n";
        echo "Транзакция: {$transactionId}\n";
        echo 'Версия:      ' . (string) ($applied['installed_version'] ?? ($staged['target_version'] ?? 'неизвестно')) . PHP_EOL;
        echo "Резервная точка и журнал транзакции проверены до изменения рабочих файлов.\n";
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

    if ($applyInvoked) {
        try {
            $recovery = updateRunAutomaticRecover(
                $runner,
                $options,
                $transactionId,
                $root
            );

            updateRunFail(
                'Установка обновления завершилась ошибкой, исходная версия автоматически восстановлена.',
                'apply_failed_recovered',
                max(1, (int) $e->getCode()),
                [
                    'transaction_id' => $transactionId,
                    'apply_invoked' => true,
                    'automatic_recovery' => true,
                    'recovery_status' => (string) ($recovery['status'] ?? 'recovered'),
                    'recovery_attempt' => (int) ($recovery['automatic_recovery_attempt'] ?? 1),
                    'apply_error' => $e->getMessage(),
                    'maintenance_may_be_active' => false,
                ]
            );
        } catch (Throwable $recoveryError) {
            updateRunFail(
                'Установка обновления завершилась ошибкой, автоматическое восстановление не удалось завершить: '
                    . $recoveryError->getMessage(),
                'automatic_recovery_failed',
                max(1, (int) $recoveryError->getCode()),
                [
                    'transaction_id' => $transactionId,
                    'apply_invoked' => true,
                    'automatic_recovery' => false,
                    'apply_error' => $e->getMessage(),
                    'maintenance_may_be_active' => true,
                ]
            );
        }
    }

    updateRunFail(
        $e->getMessage(),
        'preapply_failed',
        max(1, (int) $e->getCode()),
        [
            'transaction_id' => $transactionId,
            'apply_invoked' => false,
            'automatic_recovery' => false,
        ]
    );
}
