<?php

declare(strict_types=1);

// Запускается после сценариев создания FileSpace и проверки наследуемых прав.
require_once $root . '/modules/files/services/FileUploadTypes.php';
require_once $root . '/modules/files/services/FileSpaceTransferService.php';

use App\Services\Files\FileSpaceTransferService;

$privateRoot = sys_get_temp_dir() . '/filespaces_' . bin2hex(random_bytes(8));
filespaceCheck(mkdir($privateRoot, 0700, true), 'создан защищённый временный каталог');
$tempFile = $privateRoot . '/fixture.txt';
file_put_contents($tempFile, 'Файл для проверки общего хранилища 1.2');
$fixture = [
    'name' => 'Инструкция.txt',
    'tmp_name' => $tempFile,
    'error' => UPLOAD_ERR_OK,
    'size' => filesize($tempFile),
];
$transfers = new FileSpaceTransferService(
    $pdo,
    $access,
    new \Core\MutationCommandStore(),
    $privateRoot,
    static fn (string $source, string $destination): bool => copy($source, $destination)
);

filespaceCheck(!$access->decision($member, 'global', null, 'upload')['allowed'],
    'обычный участник не получает права загрузки по умолчанию');
$noUpload = false;
try {
    $transfers->upload($member, null, $fixture, 5, 'fsp-upload-denied-01', 1048576);
} catch (\DomainException $error) {
    $noUpload = $error->getCode() === 404;
}
filespaceCheck($noUpload, 'без upload файлы не попадают в общий корень');

$grantUpload = $grant(null, 'user', $member, 'upload', 'allow', 5, 'fsp-allow-upload-01');
filespaceCheck($grantUpload['version'] === 6, 'загрузка включена отдельным правом');
$grantDownload = $grant(null, 'everyone', 0, 'download', 'allow', 6, 'fsp-allow-download-01');
filespaceCheck($grantDownload['version'] === 7, 'скачивание включено отдельно от просмотра');

$uploaded = $transfers->upload(
    $member, null, $fixture, 7, 'fsp-file-upload-01', 1048576
);
$fileId = (int) ($uploaded['file_id'] ?? 0);
filespaceCheck($fileId > 0 && $uploaded['version'] === 8,
    'файл зарегистрирован и версия общего корня увеличена');
$repeated = $transfers->upload(
    $member, null, $fixture, 7, 'fsp-file-upload-01', 1048576
);
filespaceCheck($repeated['replayed'] === true && $repeated['file_id'] === $fileId,
    'повтор того же запроса не создаёт копию файла');
$duplicateCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM file_space_nodes WHERE node_type='file'"
)->fetchColumn();
filespaceCheck($duplicateCount === 1, 'для повторной команды существует ровно одна запись');

$stale = $transfers->upload(
    $member, null, ['name' => 'Другая.txt', 'tmp_name' => $tempFile,
        'error' => UPLOAD_ERR_OK, 'size' => filesize($tempFile)],
    7, 'fsp-file-upload-stale-01', 1048576
);
filespaceCheck($stale['error'] === 'version_conflict' && $stale['current_version'] === 8,
    'устаревшая вкладка не загружает файл в изменённый каталог');

$download = $transfers->download($reader, $fileId);
filespaceCheck($download['name'] === 'Инструкция.txt'
    && file_get_contents($download['path']) === file_get_contents($tempFile),
    'пользователь с правом download получает оригинальные байты из приватного каталога');
filespaceCheck(str_starts_with($download['path'], realpath($privateRoot) . DIRECTORY_SEPARATOR),
    'физический файл не находится в открытом каталоге сайта');

$unrelated = $pdo->prepare(
    'UPDATE file_space_nodes SET storage_key=:key WHERE id=:id'
);
$unrelated->execute([':key' => '../unsafe.txt', ':id' => $fileId]);
$badPathRejected = false;
try {
    $transfers->download($reader, $fileId);
} catch (\RuntimeException) {
    $badPathRejected = true;
}
filespaceCheck($badPathRejected, 'недопустимый ключ хранения не может прочитать соседний файл');
$unrelated->execute([':key' => basename($download['path']), ':id' => $fileId]);

$invalid = ['name' => 'script.php', 'tmp_name' => $tempFile,
    'error' => UPLOAD_ERR_OK, 'size' => filesize($tempFile)];
$unsupported = false;
try {
    $transfers->upload($member, null, $invalid, 8, 'fsp-file-php-01', 1048576);
} catch (\InvalidArgumentException) {
    $unsupported = true;
}
filespaceCheck($unsupported, 'в общий каталог нельзя загрузить исполняемый файл');

$deniedForReader = false;
try {
    $transfers->upload($reader, null, $fixture, 8, 'fsp-reader-upload-01', 1048576);
} catch (\DomainException) {
    $deniedForReader = true;
}
filespaceCheck($deniedForReader, 'право download не даёт право upload');

$revokeDownload = $grant(null, 'user', $reader, 'download', 'deny', 8, 'fsp-no-download-01');
filespaceCheck($revokeDownload['version'] === 9, 'запрет скачивания изменяет версию корня');
$blocked = false;
try {
    $transfers->download($reader, $fileId);
} catch (\DomainException) {
    $blocked = true;
}
filespaceCheck($blocked, 'явный запрет блокирует прямое скачивание по ID');
filespaceCheck($transfers->download($member, $fileId)['path'] === $download['path'],
    'запрет для читателя не блокирует другого разрешённого пользователя');

$revokeUpload = $grant(null, 'user', $member, 'upload', 'remove', 9, 'fsp-no-upload-01');
filespaceCheck($revokeUpload['version'] === 10, 'отзыв upload меняет версию');
$revokedReplay = false;
try {
    $transfers->upload($member, null, $fixture, 7, 'fsp-file-upload-01', 1048576);
} catch (\DomainException) {
    $revokedReplay = true;
}
filespaceCheck($revokedReplay, 'после отзыва доступа повтор команды не раскрывает прежний результат');

filespaceCheck((int) $pdo->query(
    "SELECT COUNT(*) FROM file_space_events WHERE event_code='file.upload'"
)->fetchColumn() === 1, 'аудит не дублирует событие загрузки');
filespaceCheck((int) $pdo->query('SELECT COUNT(*) FROM user_files')->fetchColumn() === 0,
    'общие файлы не загрязняют таблицу личного хранилища');

@unlink($download['path']);
@unlink($tempFile);
@rmdir($privateRoot . '/file_spaces/global');
@rmdir($privateRoot . '/file_spaces');
@rmdir($privateRoot);
echo "Контракт загрузки и скачивания Files 1.2 выполнен\n";
