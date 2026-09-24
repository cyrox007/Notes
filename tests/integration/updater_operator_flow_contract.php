<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/bin/update_run.php';
$source = is_file($path) ? (string) file_get_contents($path) : '';

function updateRunContract(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

updateRunContract($source !== '', 'bin/update_run.php is missing');
updateRunContract(str_contains($source, "PHP_SAPI !== 'cli'"), 'операторский сценарий должен оставаться CLI-only');
updateRunContract(str_contains($source, "'yes'"), 'операторский сценарий потерял явное подтверждение установки');
updateRunContract(str_contains($source, 'confirmation_required'), 'операторский сценарий не закрывается безопасно без --yes');
updateRunContract(str_contains($source, 'UpdateProcessRunner'), 'операторский сценарий должен использовать ограниченный argv process runner');
updateRunContract(str_contains($source, "update_doctor.php"), 'операторский сценарий потерял предварительную проверку готовности');
updateRunContract(str_contains($source, "update_remote.php"), 'операторский сценарий потерял подготовку подписанного пакета');
updateRunContract(str_contains($source, "update_backup.php"), 'операторский сценарий потерял rollback backup');
updateRunContract(str_contains($source, "update_candidate.php"), 'операторский сценарий потерял проверенный кандидат релиза');
updateRunContract(str_contains($source, "update_apply.php"), 'операторский сценарий потерял транзакционный live apply');
updateRunContract(str_contains($source, 'MaintenanceModeService'), 'операторский сценарий потерял управление maintenance');
updateRunContract(str_contains($source, '$applyInvoked = true;'), 'операторский сценарий потерял границу начала изменения рабочих файлов');
updateRunContract(str_contains($source, "'expected-version-code:'"), 'операторский сценарий потерял expected-version-code');
updateRunContract(str_contains($source, "'expected-package-sha256:'"), 'операторский сценарий потерял expected-package-sha256');
updateRunContract(
    str_contains($source, 'Подписанный канал изменился после подтверждения обновления'),
    'операторский сценарий не проверяет повторно подтверждённый релиз до maintenance'
);
updateRunContract(
    str_contains($source, 'if ($maintenanceEntered && !$applyInvoked)'),
    'pre-mutation maintenance cleanup boundary is missing'
);
updateRunContract(
    str_contains($source, 'Wrapper не должен самовольно открывать запись при ошибке'),
    'wrapper потерял fail-closed инвариант владения apply'
);
foreach (['shell_exec(', 'passthru(', 'system('] as $forbidden) {
    updateRunContract(!str_contains($source, $forbidden), 'операторский сценарий использует запрещённый shell-вызов: ' . $forbidden);
}

$help = [];
$exit = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' --help', $help, $exit);
updateRunContract($exit === 0, 'update_run --help failed');
$helpText = implode("\n", $help);
updateRunContract(str_contains($helpText, 'сквозной сценарий установки подписанного обновления'), 'изменилась справка update_run');
updateRunContract(str_contains($helpText, '--yes'), 'справка update_run должна документировать подтверждение');
updateRunContract(
    str_contains($helpText, '--expected-version-code') && str_contains($helpText, '--expected-package-sha256'),
    'справка update_run потеряла привязку web-установки к подтверждённому релизу'
);

echo "[OK] updater operator flow contract\n";
