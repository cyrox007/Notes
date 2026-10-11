<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];

$read = static function (string $relative) use ($root, &$failures): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    if (!is_string($content)) {
        $failures[] = 'Не удалось прочитать ' . $relative;
        return '';
    }
    return $content;
};

$moduleFiles = [
    'modules/files/FilesCapability.php',
    'modules/notes/NotesCapability.php',
    'modules/notes/controllers/NoteAttachmentController.php',
    'modules/messenger/handlers/MessengerCrypto.php',
    'modules/messenger/services/MessengerMediaService.php',
    'modules/messenger/services/MessengerVoiceService.php',
    'modules/profile/services/UserAvatarService.php',
];

foreach ($moduleFiles as $relative) {
    $content = $read($relative);
    if ($content === '') {
        continue;
    }
    if (preg_match('/\bgetenv\s*\(/', $content) === 1) {
        $failures[] = $relative . ': прямое чтение getenv() запрещено, используйте Core\\Environment';
    }
    if (preg_match('/\berror_log\s*\(/', $content) === 1) {
        $failures[] = $relative . ': прямой error_log() запрещён, используйте Core\\ServiceLog';
    }
}

$storageUsers = [
    'modules/files/FilesCapability.php',
    'modules/notes/controllers/NoteAttachmentController.php',
    'modules/messenger/services/MessengerMediaService.php',
    'modules/messenger/services/MessengerVoiceService.php',
    'modules/profile/services/UserAvatarService.php',
];
foreach ($storageUsers as $relative) {
    $content = $read($relative);
    if ($content !== '' && !str_contains($content, 'PrivateStoragePath')) {
        $failures[] = $relative . ': приватное хранилище должно разрешаться через Core\\PrivateStoragePath';
    }
}

$tasks = $read('modules/tasks/services/TaskBoardService.php');
if ($tasks !== '' && preg_match('/\bCREATE\s+TABLE\b/i', $tasks) === 1) {
    $failures[] = 'TaskBoardService: прикладной сервис не должен создавать таблицы во время запроса';
}

$manifest = json_decode($read('database/migrations/manifest.json'), true);
if (!is_array($manifest) || !in_array('20260915_shared_task_boards.sql', $manifest, true)) {
    $failures[] = 'Схема общих досок должна принадлежать миграции 20260915_shared_task_boards.sql';
}

$crypto = $read('modules/messenger/handlers/MessengerCrypto.php');
if ($crypto !== '') {
    if (!str_contains($crypto, 'AeadCipher::seal(') || !str_contains($crypto, 'AeadCipher::open(')) {
        $failures[] = 'MessengerCrypto: текущий формат должен использовать общий Core\\AeadCipher';
    }
    if (preg_match('/sodium_crypto_aead_xchacha20poly1305_ietf_(?:encrypt|decrypt)\s*\(/', $crypto) === 1) {
        $failures[] = 'MessengerCrypto: прямой вызов AEAD запрещён вне Core\\AeadCipher';
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Архитектурный контракт модулей нарушен:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Архитектурный контракт модулей: OK\n";
