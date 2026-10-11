<?php

declare(strict_types=1);

function updateRunAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function updateRunPosition(string $source, string $needle, string $message): int
{
    $position = strpos($source, $needle);
    updateRunAssert(is_int($position), $message);
    return $position;
}

$root = dirname(__DIR__, 2);
$updateRun = file_get_contents($root . '/bin/update_run.php');
$supportSource = file_get_contents($root . '/core/UpdateRunSupport.php');
updateRunAssert(is_string($updateRun), 'Не удалось прочитать bin/update_run.php');
updateRunAssert(is_string($supportSource), 'Не удалось прочитать core/UpdateRunSupport.php');

updateRunAssert(
    str_contains($updateRun, 'CliRuntime::assertCliHidden();')
        && str_contains($updateRun, 'CliRuntime::loadEnvironment($root);'),
    'Сквозной updater должен использовать общий скрытый CLI-вход'
);
updateRunAssert(
    str_contains($updateRun, 'new UpdateRunSupport($root, $runner)')
        && str_contains($updateRun, 'require_once $root . \'/core/UpdateRunSupport.php\';'),
    'Сквозной updater должен делегировать служебные операции UpdateRunSupport'
);

foreach ([
    'function updateRunJsonCommand(',
    'function updateRunBaseCommand(',
    'function updateRunAppendOption(',
    'function updateRunExternalApplyCommand(',
    'function updateRunRecordedExternalRuntime(',
    'function updateRunAutomaticRecover(',
] as $legacyHelper) {
    updateRunAssert(
        !str_contains($updateRun, $legacyHelper),
        'Служебная функция не должна возвращаться в bin/update_run.php: ' . $legacyHelper
    );
}

$doctor = updateRunPosition($updateRun, "baseCommand('update_doctor.php')", 'Updater должен начинать с проверки готовности');
$stage = updateRunPosition($updateRun, "baseCommand('update_remote.php')", 'Updater должен подготовить подписанный stage');
$journal = updateRunPosition($updateRun, '$journal->initialize(', 'Updater должен создать журнал до maintenance');
$maintenance = updateRunPosition($updateRun, '$maintenance->enter(', 'Updater должен включить maintenance после журнала');
$backup = updateRunPosition($updateRun, "baseCommand('update_backup.php')", 'Updater должен создать резервную точку');
$candidate = updateRunPosition($updateRun, "baseCommand('update_candidate.php')", 'Updater должен проверить внешний кандидат');
$externalRuntime = updateRunPosition($updateRun, 'new UpdateExternalRuntime($root))->prepare()', 'Updater должен подготовить внешний runtime');
$attachRuntime = updateRunPosition($updateRun, '->attachExternalRuntime(', 'Updater должен закрепить внешний runtime в журнале');
$destructiveBoundary = updateRunPosition($updateRun, '$applyInvoked = true;', 'Updater должен явно отмечать destructive boundary');
$apply = updateRunPosition($updateRun, '$applied = $support->runJsonCommand(', 'Updater должен применять обновление только через внешний runtime');

updateRunAssert(
    $doctor < $stage
        && $stage < $journal
        && $journal < $maintenance
        && $maintenance < $backup
        && $backup < $candidate
        && $candidate < $externalRuntime
        && $externalRuntime < $attachRuntime
        && $attachRuntime < $destructiveBoundary
        && $destructiveBoundary < $apply,
    'Сквозной updater должен сохранять порядок doctor -> stage -> journal -> maintenance -> backup -> candidate -> external runtime -> apply'
);

updateRunAssert(
    !str_contains($updateRun, 'UpdateLiveApplier')
        && !str_contains($updateRun, 'new UpdateApplyCommand('),
    'bin/update_run.php не должен сам менять рабочие файлы'
);
updateRunAssert(
    str_contains($updateRun, "'apply_rolled_back'")
        && str_contains($updateRun, "'apply_failed_before_mutation'")
        && str_contains($updateRun, "'automatic_recovery_failed'")
        && str_contains($updateRun, "'committed_recovery_verified'")
        && str_contains($updateRun, '$support->automaticRecover('),
    'Сквозной updater должен сохранять ветки проверенного отката и автоматического восстановления'
);
updateRunAssert(
    str_contains($updateRun, "'maintenance_may_be_active' => true")
        && str_contains($updateRun, "'maintenance_may_be_active' => false"),
    'Ошибки updater должны явно сообщать неопределённость состояния maintenance'
);

require_once $root . '/core/UpdateProcessRunner.php';
require_once $root . '/core/UpdateRunSupport.php';
$support = new ReflectionClass(\Core\UpdateRunSupport::class);
$subprocessException = new ReflectionClass(\Core\UpdateRunSubprocessException::class);
updateRunAssert($support->isFinal(), 'UpdateRunSupport должен оставаться final');
updateRunAssert($subprocessException->isFinal(), 'UpdateRunSubprocessException должен оставаться final');
foreach (['runJsonCommand', 'baseCommand', 'appendOption', 'externalApplyCommand', 'recordedExternalRuntime', 'automaticRecover'] as $method) {
    updateRunAssert($support->hasMethod($method), 'UpdateRunSupport должен содержать метод ' . $method);
}

updateRunAssert(
    str_contains($supportSource, "'subprocess_failed'")
        && str_contains($supportSource, 'int $attempts = 3')
        && str_contains($supportSource, "baseCommand('update_apply.php')")
        && str_contains($supportSource, 'sleep(2);'),
    'UpdateRunSupport должен сохранять fallback recovery и ограниченное число повторов'
);
updateRunAssert(
    !str_contains($supportSource, 'UpdateLiveApplier')
        && !str_contains($supportSource, '->enter(')
        && !str_contains($supportSource, '->leave('),
    'UpdateRunSupport не должен пересекать destructive/maintenance boundary'
);

updateRunAssert(
    !str_contains($updateRun, 'рабочего состояния\\\\n"')
        && !str_contains($updateRun, 'Транзакция: {$transactionId}\\\\n"'),
    'CLI-ответ committed recovery не должен печатать буквальный \\n'
);

fwrite(STDOUT, "[OK] сквозной updater сохраняет транзакционный порядок и внешний destructive runtime\n");
