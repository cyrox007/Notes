<?php

declare(strict_types=1);

// Сквозная приёмка операторской копии: настоящая MySQL и приватные файлы.
$root = dirname(__DIR__, 2);
require_once $root . '/core/Environment.php';
require_once $root . '/core/UpdatePath.php';
require_once $root . '/modules/admin/services/AdminBackupService.php';
require_once $root . '/app/services/MaintenanceModeService.php';

use App\Services\AdminBackupService;
use Core\UpdatePath;

$prefix = (string) (getenv('DBPREFIX') ?: '');
$dbname = (string) getenv('DBNAME');
if ($dbname === '') {
    throw new RuntimeException('Для проверки требуется отдельная БД');
}
$db = new mysqli(
    (string) getenv('DBHOST'),
    (string) getenv('DBUSER'),
    (string) getenv('DBPASS'),
    $dbname,
    (int) getenv('DBPORT')
);
$db->set_charset('utf8mb4');
$table = $prefix . 'notes';
$quoted = chr(96) . $table . chr(96);
$db->query('CREATE TABLE ' . $quoted
    . ' (id INT NOT NULL PRIMARY KEY, value_text VARCHAR(200) NOT NULL) ENGINE=InnoDB');
$db->query("INSERT INTO {$quoted}(id,value_text) VALUES(1,'исходное состояние')");
if ($prefix !== '') {
    $db->query('CREATE TABLE foreign_unrelated (id INT PRIMARY KEY) ENGINE=InnoDB');
    $db->query('INSERT INTO foreign_unrelated(id) VALUES(55)');
}

$private = sys_get_temp_dir() . '/notes-operator-backup-' . bin2hex(random_bytes(6));
if (!mkdir($private . '/user-data', 0700, true)) {
    throw new RuntimeException('Не удалось создать временное хранилище');
}
putenv('UPDATE_STATE_PATH=' . $private . '/updates');
file_put_contents($private . '/user-data/document.txt', "Содержимое вложения №1\n");

try {
    $service = new AdminBackupService($root, $private);
    $backup = $service->create();
    if (($backup['manifest']['files'][0]['path'] ?? '') !== 'user-data/document.txt') {
        throw new RuntimeException('Отсутствует вложение в резервной копии');
    }
    $verified = $service->verify($backup['id']);
    if (count($verified['manifest']['files']) !== 1 || count($service->list()) !== 1) {
        throw new RuntimeException('Неверное содержимое реестра резервных копий');
    }

    // Путь передаётся строго по идентификатору, а не произвольной строкой.
    try {
        $service->verify('../../.env');
        throw new RuntimeException('Произвольный путь не был отклонён');
    } catch (RuntimeException $expected) {
        if (str_contains($expected->getMessage(), 'Произвольный путь')) throw $expected;
    }

    // Проверяем контроль целостности и отказ от повреждённого снимка.
    $bad = $service->create();
    file_put_contents(
        $private . '/admin-backups/' . $bad['id'] . '/private/user-data/document.txt',
        'повреждено'
    );
    try {
        $service->verify($bad['id']);
        throw new RuntimeException('Повреждённая копия принята');
    } catch (RuntimeException $expected) {
        if (str_contains($expected->getMessage(), 'Повреждённая копия принята')) throw $expected;
    }

    $db->query("UPDATE {$quoted} SET value_text='изменённое состояние' WHERE id=1");
    file_put_contents($private . '/user-data/document.txt', 'Изменённое вложение');
    file_put_contents($private . '/user-data/extra.txt', 'Лишний файл');

    $result = $service->restore($backup['id']);
    if ($result['id'] !== $backup['id']) throw new RuntimeException('Восстановлена не та копия');
    $row = $db->query("SELECT value_text FROM {$quoted} WHERE id=1")->fetch_assoc();
    if (($row['value_text'] ?? '') !== 'исходное состояние') {
        throw new RuntimeException('Строка в MySQL не восстановлена');
    }
    if (file_get_contents($private . '/user-data/document.txt') !== "Содержимое вложения №1\n") {
        throw new RuntimeException('Вложение не восстановлено');
    }
    if (file_exists($private . '/user-data/extra.txt')) {
        throw new RuntimeException('Лишние файлы не удалены при восстановлении');
    }
    if ($prefix !== '') {
        if ((int) $db->query('SELECT COUNT(*) AS c FROM foreign_unrelated')->fetch_assoc()['c'] !== 1) {
            throw new RuntimeException('Восстановление затронуло чужие таблицы');
        }
    }
    if (!is_file($private . '/admin-backups/' . $result['safety_id'] . '/operator.json')) {
        throw new RuntimeException('Не сохранена защитная копия до восстановления');
    }
    if ((new App\Services\MaintenanceModeService($private . '/updates', $root))->state()['active']) {
        throw new RuntimeException('Режим обслуживания не снят после успеха');
    }
    echo "[OK] Резервная копия, повреждение, восстановление MySQL и файлов; префикс: "
        . ($prefix === '' ? 'нет' : $prefix) . "\n";
} finally {
    $db->close();
    UpdatePath::removeTree($private);
}
