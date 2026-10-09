<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/UpdateStepCheckpoint.php';
require_once dirname(__DIR__, 2) . '/core/UpdateStepBudget.php';

use Core\UpdateStepCheckpoint;
use Core\UpdateStepBudget;
use Core\UpdateStepPending;

function checkpointAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$directory = sys_get_temp_dir() . '/notes-checkpoint-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$path = $directory . '/progress.json';
try {
    $checkpoint = new UpdateStepCheckpoint($path, 'операция-1');
    checkpointAssert($checkpoint->read() === [], 'Новая операция уже содержит прогресс');
    $checkpoint->write(['cursor' => 1]);
    $checkpoint->write(['cursor' => 2]);
    checkpointAssert((new UpdateStepCheckpoint($path, 'операция-1'))->read() === ['cursor' => 2],
        'Новый запрос не прочитал последний завершённый шаг');
    $rejected = false;
    try { (new UpdateStepCheckpoint($path, 'операция-2'))->read(); }
    catch (RuntimeException) { $rejected = true; }
    checkpointAssert($rejected, 'Принят курсор другой операции');
    $record = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    $record['state']['cursor'] = 100;
    file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));
    $rejected = false;
    try { $checkpoint->read(); }
    catch (RuntimeException) { $rejected = true; }
    checkpointAssert($rejected, 'Принята повреждённая контрольная точка');
    $paused = false;
    try { (new UpdateStepBudget(1))->checkpoint('switch'); }
    catch (UpdateStepPending $pause) {
        $paused = $pause->result('транзакция')['status'] === 'in_progress';
    }
    checkpointAssert($paused, 'Лимит шага не вернул продолжение операции');
    echo "[OK] Контрольная точка: повторная запись, чтение новым запросом, чужая операция и повреждение\n";
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    rmdir($directory);
}
