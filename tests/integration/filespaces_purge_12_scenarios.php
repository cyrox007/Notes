<?php

declare(strict_types=1);

// Сценарий использует изолированную базу профильного контракта Filespaces.
require_once $root . '/modules/files/services/FileSpacePurgeService.php';

use App\Services\Files\FileSpacePurgeService;

$purgeHome = sys_get_temp_dir() . '/fsp_purge_' . bin2hex(random_bytes(8));
$purgeFiles = $purgeHome . '/file_spaces/global';
filespaceCheck(mkdir($purgeFiles, 0700, true), 'создан каталог тестового карантина');
$globalId = (int) $pdo->query("SELECT id FROM file_spaces WHERE uid='global'")->fetchColumn();

$rootUid = bin2hex(random_bytes(16));
$rootInsert = $pdo->prepare(
    'INSERT INTO file_space_nodes
       (uid,space_id,parent_id,node_type,name,created_by,is_deleted,deleted_at)
     VALUES (:uid,:space,NULL,"folder","Старый архив",:author,1,
             DATE_SUB(NOW(), INTERVAL 45 DAY))'
);
$rootInsert->execute([':uid' => $rootUid, ':space' => $globalId, ':author' => $member]);
$purgeRootId = (int) $pdo->lastInsertId();
$key = bin2hex(random_bytes(24)) . '.txt';
$payload = 'Оригинальное содержимое для проверки аварийной очистки';
file_put_contents($purgeFiles . '/' . $key, $payload);

$childInsert = $pdo->prepare(
    'INSERT INTO file_space_nodes
       (uid,space_id,parent_id,node_type,name,created_by,mime_type,
        byte_size,storage_key)
     VALUES (:uid,:space,:parent,"file","Вложенный.txt",:author,"text/plain",
             :bytes,:storage_key)'
);
$childInsert->execute([
    ':uid' => bin2hex(random_bytes(16)), ':space' => $globalId,
    ':parent' => $purgeRootId, ':author' => $member,
    ':bytes' => strlen($payload), ':storage_key' => $key,
]);
$childId = (int) $pdo->lastInsertId();

$recentUid = bin2hex(random_bytes(16));
$rootInsert->execute([':uid' => $recentUid, ':space' => $globalId, ':author' => $member]);
$recentId = (int) $pdo->lastInsertId();
$pdo->prepare('UPDATE file_space_nodes SET deleted_at=NOW() WHERE id=:id')
    ->execute([':id' => $recentId]);

$purge = new FileSpacePurgeService($pdo, $purgeHome);
$preview = $purge->candidates(10);
filespaceCheck(count(array_filter($preview, static fn (array $item): bool =>
    (int) $item['id'] === $purgeRootId)) === 1,
    'истёкший сроком корень попадает в предварительный просмотр');
filespaceCheck(count(array_filter($preview, static fn (array $item): bool =>
    (int) $item['id'] === $recentId)) === 0,
    'объект с неистёкшим сроком не очищается');
filespaceCheck(is_file($purgeFiles . '/' . $key),
    'предварительный просмотр не изменяет содержимое');

// Даже повреждённая БД с общим физическим ключом не должна стирать чужие байты.
$duplicate = $pdo->prepare(
    'INSERT INTO file_space_nodes
       (uid,space_id,parent_id,node_type,name,created_by,
        mime_type,byte_size,storage_key)
     VALUES (:uid,:space,NULL,"file","Сохранить.txt",:author,
             "text/plain",:bytes,:storage_key)'
);
$duplicate->execute([
    ':uid' => bin2hex(random_bytes(16)), ':space' => $globalId,
    ':author' => $reader, ':bytes' => strlen($payload), ':storage_key' => $key,
]);
$duplicateId = (int) $pdo->lastInsertId();
$sharedKeyBlocked = false;
try {
    $purge->run(1);
} catch (\RuntimeException $error) {
    $sharedKeyBlocked = str_contains($error->getMessage(), 'другим объектом');
}
filespaceCheck($sharedKeyBlocked && file_get_contents($purgeFiles . '/' . $key) === $payload,
    'общий физический ключ блокирует безвозвратное удаление');
$pdo->prepare('DELETE FROM file_space_nodes WHERE id=:id')->execute([':id' => $duplicateId]);

$simulateFailure = new FileSpacePurgeService(
    $pdo, $purgeHome,
    static function (string $uid): void {
        throw new \RuntimeException('Тест: имитация отказа после карантина ' . $uid);
    }
);
$failedSafely = false;
try {
    $simulateFailure->run(1);
} catch (\RuntimeException $error) {
    $failedSafely = str_contains($error->getMessage(), 'имитация отказа');
}
filespaceCheck($failedSafely, 'смоделирован отказ после перемещения данных');
filespaceCheck(file_get_contents($purgeFiles . '/' . $key) === $payload,
    'откат вернул физический файл');
filespaceCheck((int) $pdo->query(
    'SELECT COUNT(*) FROM file_space_nodes WHERE id=' . $purgeRootId
)->fetchColumn() === 1, 'после отказа запись корзины осталась для восстановления');

$preparingUid = bin2hex(random_bytes(16));
$prepare = $pdo->prepare(
    "INSERT INTO file_space_purge_batches
       (uid,space_id,root_node_id,root_uid,status)
     VALUES (:uid,:space,:root,:root_uid,'preparing')"
);
$prepare->execute([
    ':uid' => $preparingUid, ':space' => $globalId,
    ':root' => $purgeRootId, ':root_uid' => $rootUid,
]);
$quarantine = $purgeFiles . '/.purge/' . $preparingUid;
filespaceCheck(is_dir(dirname($quarantine)) || mkdir(dirname($quarantine), 0700, true),
    'создан общий карантин');
filespaceCheck(mkdir($quarantine, 0700), 'создан карантин незавершённой операции');
filespaceCheck(rename($purgeFiles . '/' . $key, $quarantine . '/' . $key),
    'смоделирован сбой процесса после физического перемещения');
filespaceCheck(!is_file($purgeFiles . '/' . $key), 'исходный файл временно отсутствует');

$result = $purge->run(1);
filespaceCheck($result['processed'] === 1 && $result['files'] === 1
    && $result['bytes'] === strlen($payload),
    'после восстановления незавершённой операции корень безопасно очищен');
filespaceCheck((int) $pdo->query(
    'SELECT COUNT(*) FROM file_space_nodes WHERE id IN (' . $purgeRootId . ',' . $childId . ')'
)->fetchColumn() === 0, 'корень и дочерняя запись удалены в одном проходе');
filespaceCheck(!file_exists($purgeFiles . '/' . $key),
    'после подтверждённого удаления физические байты стёрты');
filespaceCheck((int) $pdo->query(
    'SELECT COUNT(*) FROM file_space_nodes WHERE id=' . $recentId
)->fetchColumn() === 1, 'новый удалённый объект не затронут');

$states = $pdo->query('SELECT status FROM file_space_purge_batches')->fetchAll(PDO::FETCH_COLUMN);
filespaceCheck(in_array('rolled_back', $states, true)
    && in_array('finished', $states, true),
    'журнал отличает восстановленную аварию от завершённой очистки');
$purgeEvents = (int) $pdo->query(
    "SELECT COUNT(*) FROM file_space_events WHERE event_code='purge.commit'"
)->fetchColumn();
filespaceCheck($purgeEvents === 1, 'аудит записывает ровно одно завершение очистки');

$again = $purge->run(1);
filespaceCheck($again['processed'] === 0,
    'повтор запуска не стирает данные повторно');
@rmdir($purgeFiles . '/.purge');
@rmdir($purgeFiles);
@rmdir($purgeHome . '/file_spaces');
@rmdir($purgeHome);
echo "Контракт карантина и срока хранения Files 1.2 выполнен\n";
