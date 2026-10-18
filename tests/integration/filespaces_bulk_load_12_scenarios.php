<?php

declare(strict_types=1);

// Проверка массового ACL и производительности на изолированной базе MySQL.
$groupOne = $adminFiles->createGroup($admin, 'Нагрузка и права А', 'fsp-bulk-group-one');
$groupTwo = $adminFiles->createGroup($admin, 'Нагрузка и права Б', 'fsp-bulk-group-two');
$groupA = (int) $groupOne['group_id'];
$groupB = (int) $groupTwo['group_id'];
$adminFiles->changeGroup($admin, $groupA, 'add', $member, 1, 'fsp-bulk-member-a');
$adminFiles->changeGroup($admin, $groupB, 'add', $reader, 1, 'fsp-bulk-member-b');

$getRootVersion = static fn (): int => (int) $pdo->query(
    "SELECT version FROM file_spaces WHERE uid='global'"
)->fetchColumn();

$beforeVersion = $getRootVersion();
$beforeAudit = (int) $pdo->query(
    "SELECT COUNT(*) FROM file_space_events WHERE event_code='acl.bulk'"
)->fetchColumn();
$batchRule = $folders->setRulesBulk(
    $admin, null, 'group', [$groupB,$groupA,$groupA],
    ['history','download','history'], 'allow',
    $beforeVersion, 'fsp-bulk-grant-1'
);
filespaceCheck($batchRule['success'] && $batchRule['version'] === $beforeVersion + 1
    && $batchRule['rules_count'] === 4,
    'четыре уникальных правила сохранены одним увеличением версии');
filespaceCheck($access->decision($member, 'global', null, 'history')['allowed']
    && $access->decision($reader, 'global', null, 'history')['allowed'],
    'групповые права немедленно действуют для двух участников');

$replayed = $folders->setRulesBulk(
    $admin, null, 'group', [$groupA,$groupB],
    ['download','history'], 'allow',
    $beforeVersion, 'fsp-bulk-grant-1'
);
filespaceCheck($replayed['replayed'] && $replayed['version'] === $beforeVersion + 1
    && $getRootVersion() === $beforeVersion + 1,
    'повтор команды с переставленными элементами идемпотентен');

$conflict = $folders->setRulesBulk(
    $admin, null, 'group', [$groupA,$groupB],
    ['upload'], 'deny',
    $beforeVersion, 'fsp-bulk-stale'
);
filespaceCheck(($conflict['error'] ?? '') === 'version_conflict',
    'вторая вкладка получает конфликт без частичной записи');
$badSubject = false;
try {
    $folders->setRulesBulk(
        $admin, null, 'group', [$groupA,987654321],
        ['upload'], 'allow', $getRootVersion(), 'fsp-bulk-invalid'
    );
} catch (\InvalidArgumentException) {
    $badSubject = true;
}
filespaceCheck($badSubject && !$access->decision($member, 'global', null, 'upload')['allowed'],
    'неизвестная группа откатывает всю массовую операцию');
filespaceCheck((int) $pdo->query(
    "SELECT COUNT(*) FROM file_space_events WHERE event_code='acl.bulk'"
)->fetchColumn() === $beforeAudit + 1,
    'массовая операция имеет одну запись аудита');

$tooMany = false;
try {
    $folders->setRulesBulk(
        $admin, null, 'group', range(1, 26),
        ['view'], 'allow', $getRootVersion(), 'fsp-bulk-oversized'
    );
} catch (\InvalidArgumentException) {
    $tooMany = true;
}
filespaceCheck($tooMany, 'операция с числом получателей выше лимита отклонена');

$revoke = $folders->setRulesBulk(
    $admin, null, 'group', [$groupB,$groupA],
    ['download','history'], 'remove',
    $getRootVersion(), 'fsp-bulk-revoke-1'
);
filespaceCheck($revoke['success']
    && !$access->decision($member, 'global', null, 'history')['allowed'],
    'массовый отзыв группового права применяется сразу');

$spaceIdForLoad = (int) $pdo->query(
    "SELECT id FROM file_spaces WHERE uid='global'"
)->fetchColumn();
$insert = $pdo->prepare(
    'INSERT INTO file_space_nodes
       (uid,space_id,parent_id,node_type,name,created_by,inherit_acl)
     VALUES (:uid,:space,NULL,"folder",:name,:author,1)'
);
$pdo->beginTransaction();
for ($i = 0; $i < 2400; $i++) {
    $insert->execute([
        ':uid' => bin2hex(random_bytes(16)),
        ':space' => $spaceIdForLoad,
        ':name' => sprintf('80-нагрузка-%05d', $i),
        ':author' => $admin,
    ]);
}
$pdo->commit();

$midQuery = $pdo->prepare(
    'SELECT id FROM file_space_nodes WHERE space_id=:space AND name=:name'
);
$midQuery->execute([':space' => $spaceIdForLoad, ':name' => '80-нагрузка-01200']);
$midId = (int) $midQuery->fetchColumn();
filespaceCheck($midId > 0, 'выполнена подготовка каталога из 2400 объектов');
$cursor = rtrim(strtr(base64_encode(json_encode([
    'n' => '80-нагрузка-01200', 'i' => $midId,
    'p' => null, 'v' => $getRootVersion(),
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

$queriesBefore = $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC);
$start = microtime(true);
$middle = $folders->browse($reader, null, $cursor);
$elapsed = microtime(true) - $start;
$queriesAfter = $pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC);
$queries = (int) $queriesAfter['Value'] - (int) $queriesBefore['Value'];
filespaceCheck(count($middle['items']) === 40,
    'средняя страница большого каталога содержит ровно 40 объектов');
filespaceCheck($queries < 80,
    'расчёт прав страницы не создаёт сотни запросов на каждый объект');
filespaceCheck($elapsed < 30,
    'одна страница большого каталога обрабатывается в разумное время');
filespaceCheck(str_starts_with($middle['items'][0]['name'], '80-нагрузка-'),
    'курсор открывает нужный сегмент большого каталога');

foreach (array_slice($middle['items'], 0, 5) as $item) {
    $direct = $access->decision($reader, 'global', (int) $item['id'], 'download');
    filespaceCheck($direct['allowed'] === $item['can_download'],
        'пакетная проверка ACL совпадает с индивидуальным решением');
}
$nextPage = $folders->browse($reader, null, $middle['next_cursor']);
filespaceCheck(count($nextPage['items']) === 40
    && $nextPage['items'][0]['id'] !== $middle['items'][0]['id'],
    'следующая страница не повторяет первый объект');
echo "Контракт массовых ACL и каталога из 2400 объектов Files 1.2 выполнен\n";
