<?php

declare(strict_types=1);

/**
 * Исключительно одноразовый браузерный стенд GitHub Actions.
 * Рабочую БД и пользователей не изменяет.
 */
if (getenv('E2E_ENABLE_WORK_PERMITS') !== '1') {
    throw new RuntimeException('Тестовые данные нарядов можно создать только в специальном CI-стенде');
}
$password = getenv('E2E_PASSWORD');
if (!is_string($password) || strlen($password) < 16) {
    throw new RuntimeException('Нет безопасного пароля для испытаний');
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DBHOST'), getenv('DBPORT'), getenv('DBNAME')),
    getenv('DBUSER'), getenv('DBPASS'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$users = [
    ['wp-manager-one', 'wp-manager-one@example.test', 'Управляющий', 'Первый', 'superadmin', 1],
    ['wp-manager-two', 'wp-manager-two@example.test', 'Управляющий', 'Второй', 'superadmin', 1],
    ['wp-worker', 'wp-worker@example.test', 'Работник', 'РЭС', 'user', 888],
];
foreach ($users as $index => [$username, $email, $first, $last, $role, $legacyRole]) {
    $insert = $pdo->prepare(
        'INSERT INTO users
         (uid,username,email,password_hash,firstname,lastname,role,is_active,account_status,created_at,updated_at)
         VALUES (:uid,:name,:mail,:hash,:first,:last,:role,1,\'active\',NOW(),NOW())'
    );
    $insert->execute([
        ':uid' => sprintf('b7f60000-0000-4000-8000-%012d', $index + 1),
        ':name' => $username,
        ':mail' => $email,
        ':hash' => password_hash($password, PASSWORD_ARGON2ID),
        ':first' => $first,
        ':last' => $last,
        ':role' => $legacyRole,
    ]);
    $id = (int) $pdo->lastInsertId();
    $link = $pdo->prepare(
        'INSERT IGNORE INTO user_roles (user_id,role_id)
         SELECT :user_id,id FROM roles WHERE code=:role LIMIT 1'
    );
    $link->execute([':user_id' => $id, ':role' => $role]);
    if ($pdo->query('SELECT COUNT(*) FROM user_roles WHERE user_id=' . $id)->fetchColumn() < 1) {
        throw new RuntimeException('Роль не зарегистрирована: ' . $role);
    }
}
$pdo->exec(
    "INSERT INTO module_lifecycle
     (module_id,installed_version,manifest_hash,configured_state,effective_state)
     VALUES ('work-permits','1.2.0'," . $pdo->quote(str_repeat('0', 64)) . ",'enabled','enabled')"
);
$pdo->exec(
    "INSERT INTO work_permit_organizations(name) VALUES ('Северные электросети')"
);
$org = (int) $pdo->lastInsertId();
$dept = $pdo->prepare('INSERT INTO work_permit_departments(organization_id,name) VALUES (?,?)');
$dept->execute([$org, 'Северный РЭС']);
$departmentId = (int) $pdo->lastInsertId();
$site = $pdo->prepare('INSERT INTO work_permit_facilities(organization_id,name) VALUES (?,?)');
$site->execute([$org, 'ПС Болдыревка']);
$person = $pdo->prepare(
    'INSERT INTO work_permit_employees (organization_id,department_id,name,electrical_group)
     VALUES (?,?,?,?)'
);
$person->execute([$org, $departmentId, 'Иванов Иван Иванович', 'IV']);
$person->execute([$org, $departmentId, 'Петров Пётр Петрович', 'V']);
fwrite(STDOUT, "Тестовая лицензируемая структура нарядов подготовлена\n");
