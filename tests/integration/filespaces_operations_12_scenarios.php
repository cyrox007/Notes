<?php

declare(strict_types=1);

// Продолжение изолированного контракта Filespaces с теми же пользователями и БД.
require_once $root . '/modules/files/services/FileSpaceObjectService.php';

use App\Services\Files\FileSpaceObjectService;

$objects = new FileSpaceObjectService($pdo, $access);
$spaceId = (int) $space['id'];
$getVersion = static function (?int $id = null) use ($pdo, $spaceId): int {
    if ($id === null) {
        return (int) $pdo->query('SELECT version FROM file_spaces WHERE id=' . $spaceId)->fetchColumn();
    }
    return (int) $pdo->query('SELECT version FROM file_space_nodes WHERE id=' . $id)->fetchColumn();
};
$allowed = static function (?int $folder, string $subject, int $subjectId, string $right)
    use ($grant, $getVersion): void {
    $grant($folder, $subject, $subjectId, $right, 'allow', $getVersion($folder),
        'fsp-objects-rule-' . bin2hex(random_bytes(8)));
};
$do = static function (int $actor, int $id, string $action, string $command, array $data = [])
    use ($objects, $getVersion, $pdo): array {
    $stmt = $pdo->prepare('SELECT parent_id FROM file_space_nodes WHERE id=:id');
    $stmt->execute([':id' => $id]);
    $parent = $stmt->fetchColumn();
    $parent = $parent === null ? null : (int) $parent;
    return $objects->mutate($actor, $id, $action, $getVersion($id),
        $getVersion($parent), $command, $data);
};
$originalRoot = sys_get_temp_dir() . '/fsp_operations_' . bin2hex(random_bytes(8));
$physical = $originalRoot . '/file_spaces/global';
filespaceCheck(mkdir($physical, 0700, true), 'создан временный приватный каталог объектов');
$originalKey = bin2hex(random_bytes(24)) . '.txt';
file_put_contents($physical . '/' . $originalKey, 'Прежнее содержимое документа');

$insert = $pdo->prepare(
    'INSERT INTO file_space_nodes
       (uid,space_id,parent_id,node_type,name,created_by,updated_by,
        mime_type,byte_size,storage_key)
     VALUES (:uid,:space,NULL,"file","Тестовый.txt",:owner,:editor,
             "text/plain",:size,:storage_key)'
);
$insert->execute([
    ':uid' => bin2hex(random_bytes(16)), ':space' => $spaceId,
    ':owner' => $member, ':editor' => $member, ':size' => filesize($physical . '/' . $originalKey),
    ':storage_key' => $originalKey,
]);
$managedId = (int) $pdo->lastInsertId();
filespaceCheck($managedId > 0, 'общий файл существует для проверок');

$deniedRename = false;
try {
    $do($reader, $managedId, 'rename', 'fsp-obj-denied-rename', ['name' => 'Чужое.txt']);
} catch (\DomainException) {
    $deniedRename = true;
}
filespaceCheck($deniedRename, 'пользователь без rename_others не меняет чужой файл');
$allowed(null, 'user', $member, 'rename_own');
$allowed(null, 'user', $reader, 'rename_others');

$renamed = $do($member, $managedId, 'rename', 'fsp-obj-rename-1', ['name' => 'Первое.txt']);
filespaceCheck($renamed['version'] === 2 && $renamed['parent_version'] === $getVersion(),
    'переименование своего файла обновило версии объекта и каталога');
$renameRepeat = $objects->mutate(
    $member, $managedId, 'rename', 1, $renamed['parent_version'] - 1,
    'fsp-obj-rename-1', ['name' => 'Первое.txt']
);
filespaceCheck($renameRepeat['replayed'] === true && $renameRepeat['version'] === 2,
    'повтор переименования возвращает прежний результат');
$staleRename = $objects->mutate(
    $reader, $managedId, 'rename', 1, $renamed['parent_version'],
    'fsp-obj-rename-stale', ['name' => 'Конфликт.txt']
);
filespaceCheck($staleRename['error'] === 'version_conflict'
    && $staleRename['current_version'] === 2,
    'две вкладки не перезаписывают переименование');

$othersRename = $do($reader, $managedId, 'rename', 'fsp-obj-rename-other', ['name' => 'Общий.txt']);
filespaceCheck($othersRename['version'] === 3, 'разрешённое rename_others работает');

$allowed(null, 'user', $member, 'move_own');
$allowed($folderId, 'user', $member, 'upload');
$move = $objects->mutate(
    $member, $managedId, 'move', $getVersion($managedId), $getVersion(),
    'fsp-obj-move-1', ['parent_id' => $folderId, 'expected_destination_version' => $getVersion($folderId)]
);
filespaceCheck($move['success'] === true && $move['changed'] === true,
    'файл перенесён в разрешённую папку');
$parentOfMoved = (int) $pdo->query('SELECT parent_id FROM file_space_nodes WHERE id=' . $managedId)->fetchColumn();
filespaceCheck($parentOfMoved === $folderId, 'у файла изменился родительский каталог');

$deniedOthers = false;
try {
    $do($reader, $managedId, 'delete', 'fsp-obj-denied-delete');
} catch (\DomainException) {
    $deniedOthers = true;
}
filespaceCheck($deniedOthers, 'чужая папка и отсутствие delete_others запрещают удаление');
$allowed($folderId, 'user', $member, 'delete_own');
$deleted = $do($member, $managedId, 'delete', 'fsp-obj-delete-1');
filespaceCheck($deleted['success'] === true, 'удаление в корзину прошло');
$deleteAgain = $objects->mutate(
    $member, $managedId, 'delete', $deleted['version'] - 1,
    $deleted['parent_version'] - 1, 'fsp-obj-delete-1'
);
filespaceCheck($deleteAgain['replayed'] === true, 'повтор удаления идемпотентен');

$storedService = new \App\Services\Files\FileSpaceTransferService(
    $pdo, $access, new \Core\MutationCommandStore(), $originalRoot,
    static fn (string $source, string $dest): bool => copy($source, $dest)
);
$hidden = false;
try {
    $storedService->download($admin, $managedId);
} catch (\DomainException) {
    $hidden = true;
}
filespaceCheck($hidden, 'даже администратор не скачивает удалённый объект');
$allowed($folderId, 'user', $member, 'restore');
$restored = $do($member, $managedId, 'restore', 'fsp-obj-restore-1');
filespaceCheck($restored['success'] === true, 'удалённый файл восстановлен');
$restoreReplay = $objects->mutate(
    $member, $managedId, 'restore', $restored['version'] - 1,
    $restored['parent_version'] - 1, 'fsp-obj-restore-1'
);
filespaceCheck($restoreReplay['replayed'] === true, 'восстановление не повторяется вторично');

$allowed($folderId, 'user', $member, 'replace_own');
$replacementTmp = $originalRoot . '/replacement.txt';
file_put_contents($replacementTmp, 'Новое содержимое общего документа');
$replacement = [
    'name' => 'Документ.txt', 'tmp_name' => $replacementTmp,
    'error' => UPLOAD_ERR_OK, 'size' => filesize($replacementTmp),
];
$oldKeyPath = $storedService->download($admin, $managedId)['path'];
$beforeReplace = $getVersion($managedId);
$beforeReplaceParent = $getVersion($folderId);
$replaced = $storedService->replace(
    $member, $managedId, $replacement, $beforeReplace, $beforeReplaceParent,
    'fsp-obj-replace-1', 1048576
);
filespaceCheck($replaced['version'] === $beforeReplace + 1,
    'замена содержимого увеличила версию файла');
filespaceCheck(file_get_contents($storedService->download($admin, $managedId)['path'])
    === file_get_contents($replacementTmp),
    'после замены скачивается новое содержимое');
filespaceCheck(!is_file($oldKeyPath), 'старая копия удалена только после фиксации замены');
$replaceAgain = $storedService->replace(
    $member, $managedId, $replacement, $beforeReplace, $beforeReplaceParent,
    'fsp-obj-replace-1', 1048576
);
filespaceCheck($replaceAgain['replayed'] === true, 'повтор замены содержимого безопасен');

$cycleChild = $folders->createFolder(
    $admin, $folderId, 'Вложенная', $getVersion($folderId), 'fsp-obj-child-1'
);
$blockedCycle = false;
try {
    $objects->mutate($admin, $folderId, 'move', $getVersion($folderId),
        $getVersion(), 'fsp-obj-cycle-1',
        ['parent_id' => $cycleChild['folder_id'], 'expected_destination_version' => $getVersion($cycleChild['folder_id'])]);
} catch (\DomainException $error) {
    $blockedCycle = $error->getCode() === 409;
}
filespaceCheck($blockedCycle, 'нельзя переместить папку внутрь своего потомка');

$folderDeleted = $do($admin, $folderId, 'delete', 'fsp-obj-trash-tree-1');
$hiddenChild = false;
try {
    $storedService->download($admin, $managedId);
} catch (\DomainException) {
    $hiddenChild = true;
}
filespaceCheck($hiddenChild, 'удалённая папка закрывает прямое скачивание дочернего файла');
$folderRestored = $do($admin, $folderId, 'restore', 'fsp-obj-restore-tree-1');
filespaceCheck($folderRestored['success'] === true
    && file_get_contents($storedService->download($admin, $managedId)['path'])
        === file_get_contents($replacementTmp),
    'восстановление папки возвращает содержимое без копирования файла');

$events = (int) $pdo->query(
    "SELECT COUNT(*) FROM file_space_events WHERE event_code LIKE 'node.%' OR event_code='file.replace'"
)->fetchColumn();
filespaceCheck($events >= 7, 'аудит зафиксировал изменения файлов и папок');

$last = $storedService->download($admin, $managedId)['path'];
@unlink($last);
@unlink($replacementTmp);
@rmdir($physical);
@rmdir($originalRoot . '/file_spaces');
@rmdir($originalRoot);
echo "Контракт изменения объектов и корзины Files 1.2 выполнен\n";
