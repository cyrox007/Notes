<?php

declare(strict_types=1);

// Проверка ограниченной выдачи большого каталога после создания профильной БД.
$spaceIdForPage = (int) $pdo->query(
    "SELECT id FROM file_spaces WHERE uid='global'"
)->fetchColumn();
$insertPageNode = $pdo->prepare(
    'INSERT INTO file_space_nodes
      (uid,space_id,parent_id,node_type,name,created_by,inherit_acl)
     VALUES (:uid,:space,NULL,"folder",:name,:author,:inherit)'
);
for ($i = 0; $i < 250; $i++) {
    $insertPageNode->execute([
        ':uid' => bin2hex(random_bytes(16)),
        ':space' => $spaceIdForPage,
        ':name' => sprintf('00-закрытая-%04d', $i),
        ':author' => $admin,
        ':inherit' => 0,
    ]);
}
for ($i = 0; $i < 75; $i++) {
    $insertPageNode->execute([
        ':uid' => bin2hex(random_bytes(16)),
        ':space' => $spaceIdForPage,
        ':name' => sprintf('99-доступная-%04d', $i),
        ':author' => $admin,
        ':inherit' => 1,
    ]);
}
$seen = [];
$next = null;
$pages = 0;
do {
    $page = $folders->browse($reader, null, $next);
    filespaceCheck(count($page['items']) <= 40, 'выдача не превышает лимит страницы');
    foreach ($page['items'] as $node) {
        $name = (string) $node['name'];
        filespaceCheck(!str_starts_with($name, '00-закрытая-'),
            'закрытые папки не просачиваются в результаты');
        filespaceCheck(!isset($seen[$node['id']]), 'объект не повторяется между страницами');
        $seen[$node['id']] = $name;
    }
    $next = $page['next_cursor'] ?? null;
    $pages++;
    filespaceCheck($pages <= 12, 'навигация каталога завершается за конечное число страниц');
} while ($next !== null);

$accessible = array_filter($seen, static fn (string $name): bool =>
    str_starts_with($name, '99-доступная-'));
filespaceCheck(count($accessible) === 75,
    'файлы после первых 200 скрытых объектов доступны через навигацию');
filespaceCheck($pages >= 2, 'большой каталог выдаётся отдельными страницами');

$invalidCursor = false;
try {
    $folders->browse($reader, null, 'bad!!!');
} catch (\InvalidArgumentException) {
    $invalidCursor = true;
}
filespaceCheck($invalidCursor, 'ошибочный курсор отклонён сервером');

$first = $folders->browse($reader, null);
filespaceCheck(is_string($first['next_cursor']) && $first['next_cursor'] !== '',
    'первая страница содержит указатель продолжения');
$pdo->prepare('UPDATE file_spaces SET version=version+1 WHERE id=:id')
    ->execute([':id' => $spaceIdForPage]);
$versionConflict = false;
try {
    $folders->browse($reader, null, $first['next_cursor']);
} catch (\DomainException $error) {
    $versionConflict = $error->getCode() === 409;
}
filespaceCheck($versionConflict, 'старый курсор не используется после изменения каталога');
echo "Контракт постраничной выдачи общих файлов Files 1.2 выполнен\n";
