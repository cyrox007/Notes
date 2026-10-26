<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/UpdateMigrationPostcheck.php';

use Core\UpdateMigrationPostcheck;

function expectMigrationPostcheck(string $status, bool $allowed, string $label): void
{
    $accepted = false;
    try {
        UpdateMigrationPostcheck::assertComplete($status);
        $accepted = true;
    } catch (RuntimeException) {
        $accepted = false;
    }
    if ($accepted !== $allowed) {
        throw new RuntimeException('Проверка миграций: неверный результат для ' . $label);
    }
}

expectMigrationPostcheck("APPLIED foo.sql\nИтого: 64 применено, 0 ожидает\nКонтракт схемы: OK\n", true, 'полной схемы');
expectMigrationPostcheck("APPLIED foo.sql\nSummary: 64 applied, 0 pending\nSchema contract: OK\n", true, 'англоязычной совместимости');
expectMigrationPostcheck("APPLIED foo.sql\nИтого: 64 применено, 5 ожидает\n", false, 'пяти ожидающих миграций');
expectMigrationPostcheck("Summary: 64 applied, 1 pending\n", false, 'одной ожидающей миграции');
expectMigrationPostcheck("Итого: 64 применено, 0 ожидает\n", false, 'неподтверждённого контракта');
expectMigrationPostcheck("0\n", false, 'обрезанного или неизвестного вывода');
expectMigrationPostcheck("", false, 'пустого вывода');

$apply = (string) file_get_contents(dirname(__DIR__, 2) . '/core/UpdateApplyCommand.php');
if (!str_contains($apply, "UpdateMigrationPostcheck::assertComplete(\$migrationStatus['stdout'])")) {
    throw new RuntimeException('Обновлятор не проверяет полноту миграций до commit');
}

echo "Запрет ложного commit при неполных миграциях подтверждён\n";
