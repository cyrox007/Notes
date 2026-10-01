<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/core/Version.php';
require_once $root . '/core/UpdatePath.php';
require_once $root . '/core/PrivateStorageResolver.php';
require_once $root . '/core/UpdateDownloadCredentials.php';

use Core\UpdateDownloadCredentials;

function diagnosticsRemoteAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] удалённая диагностика: {$message}\n");
        exit(1);
    }
}

$credentials = new UpdateDownloadCredentials([
    'schema' => 1,
    'installation_id' => '11111111-2222-3333-4444-555555555555',
    'token' => str_repeat('a', 64),
    'base_url' => 'https://updates.example.test/api/notes/v1/',
]);

$headers = $credentials->headersForDiagnostics(
    'https://updates.example.test/api/notes/v1/diagnostics'
);

diagnosticsRemoteAssert(
    str_contains($headers, 'Authorization: Bearer ' . str_repeat('a', 64)),
    'diagnostic endpoint не получает updater bearer credential'
);
diagnosticsRemoteAssert(
    str_contains($headers, 'X-Notes-Installation: 11111111-2222-3333-4444-555555555555'),
    'diagnostic endpoint не получает installation ID'
);

$rejected = false;
try {
    $credentials->headersForDiagnostics('https://evil.example/diagnostics');
} catch (RuntimeException) {
    $rejected = true;
}
diagnosticsRemoteAssert(
    $rejected,
    'credential можно отправить на произвольный внешний URL'
);

$sender = (string) file_get_contents($root . '/core/SupportDiagnosticsSender.php');
$controller = (string) file_get_contents($root . '/modules/admin/controllers/UpdateController.php');
$runtime = (string) file_get_contents($root . '/modules/admin/AdminRuntimeProvider.php');
$view = (string) file_get_contents($root . '/modules/admin/views/updates.php');
$support = (string) file_get_contents($root . '/core/SupportDiagnostics.php');

diagnosticsRemoteAssert(
    str_contains($sender, "$credentials->baseUrl() . 'diagnostics'")
        && str_contains($sender, 'multipart/form-data')
        && str_contains($sender, 'application/zip')
        && str_contains($sender, 'manual_admin'),
    'sender не закрепляет trusted control-plane endpoint и multipart ZIP'
);
diagnosticsRemoteAssert(
    str_contains($support, 'createUploadBundle')
        && str_contains($support, 'MAX_BUNDLE_BYTES'),
    'существующий обезличенный SupportDiagnostics не используется для upload'
);
diagnosticsRemoteAssert(
    str_contains($runtime, 'admin_support_diagnostics_send')
        && str_contains($runtime, 'CSRFMiddleware::class')
        && str_contains($controller, 'sendSupportDiagnostics'),
    'отправка диагностики не защищена admin/CSRF контуром'
);
diagnosticsRemoteAssert(
    str_contains($view, 'Отправить диагностику разработчику')
        && str_contains($view, 'не входят пользовательские документы'),
    'интерфейс не объясняет явную отправку и границы данных'
);

fwrite(STDOUT, "[OK] диагностический ZIP отправляется только в активированный control plane\n");
