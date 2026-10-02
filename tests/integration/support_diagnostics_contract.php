<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

require_once $root . '/core/ServiceLog.php';
require_once $root . '/core/SupportDiagnostics.php';

use Core\ServiceLog;
use Core\SupportDiagnostics;

function supportDiagnosticsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] диагностика поддержки: {$message}\n");
        exit(1);
    }
}

/**
 * @return array<string,string>
 */
function supportDiagnosticsReadStoredZip(string $bytes): array
{
    $files = [];
    $offset = 0;
    $length = strlen($bytes);

    while ($offset + 4 <= $length) {
        $signature = unpack('Vvalue', substr($bytes, $offset, 4));
        $value = is_array($signature) ? (int) ($signature['value'] ?? 0) : 0;

        if ($value === 0x02014b50 || $value === 0x06054b50) {
            break;
        }
        supportDiagnosticsAssert($value === 0x04034b50, 'ZIP содержит некорректный local header');
        supportDiagnosticsAssert($offset + 30 <= $length, 'ZIP local header обрезан');

        $header = unpack(
            'Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname_length/vextra_length',
            substr($bytes, $offset, 30)
        );
        supportDiagnosticsAssert(is_array($header), 'не удалось разобрать ZIP local header');
        supportDiagnosticsAssert((int) ($header['method'] ?? -1) === 0, 'диагностический ZIP должен использовать STORE');

        $nameLength = (int) ($header['name_length'] ?? 0);
        $extraLength = (int) ($header['extra_length'] ?? 0);
        $size = (int) ($header['size'] ?? -1);
        $dataOffset = $offset + 30 + $nameLength + $extraLength;

        supportDiagnosticsAssert(
            $nameLength > 0 && $size >= 0 && $dataOffset + $size <= $length,
            'ZIP entry выходит за границы архива'
        );

        $name = substr($bytes, $offset + 30, $nameLength);
        $contents = substr($bytes, $dataOffset, $size);
        $files[$name] = $contents;
        $offset = $dataOffset + $size;
    }

    return $files;
}

function supportDiagnosticsRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $candidate = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($candidate) && !is_link($candidate)) {
            supportDiagnosticsRemoveTree($candidate);
            continue;
        }

        @unlink($candidate);
    }

    @rmdir($path);
}

$temp = sys_get_temp_dir() . '/notes-support-diagnostics-' . bin2hex(random_bytes(6));
supportDiagnosticsAssert(mkdir($temp, 0700, true), 'не удалось создать внешний каталог теста');

$oldPrivate = getenv('PRIVATE_STORAGE_PATH');
$oldServiceLog = getenv('SERVICE_LOG_PATH');
$oldDbPass = getenv('DBPASS');

try {
    putenv('PRIVATE_STORAGE_PATH=' . $temp);
    putenv('SERVICE_LOG_PATH=' . $temp . '/logs/service-events.jsonl');
    putenv('DBPASS=DB-PASSWORD-MUST-NOT-LEAK');

    $log = new ServiceLog(null, $root);
    $log->record(
        'updater.fixture_failed',
        'error',
        'updater',
        [
            'transaction_id' => 'fixture-transaction',
            'password' => 'PASSWORD-MUST-NOT-LEAK',
            'continuation_token' => 'TOKEN-MUST-NOT-LEAK',
            'message' => "ALTER ROUTINE command denied to user 'hosting_user'@'localhost'",
            'safe_code' => 'migration_failed',
        ]
    );

    $rawLog = (string) file_get_contents($log->path());
    foreach ([
        'PASSWORD-MUST-NOT-LEAK',
        'TOKEN-MUST-NOT-LEAK',
        "'hosting_user'@'localhost'",
    ] as $secret) {
        supportDiagnosticsAssert(
            !str_contains($rawLog, $secret),
            'секрет или учётные данные попали в сервисный журнал'
        );
    }
    supportDiagnosticsAssert(
        str_contains($rawLog, '[redacted]'),
        'сервисный журнал не пометил очищенные значения'
    );

    $diagnostics = new SupportDiagnostics($root);
    $diagnosticsReflection = new ReflectionClass($diagnostics);
    $privateRootProperty = $diagnosticsReflection->getProperty('privateRoot');
    $privateRootProperty->setValue($diagnostics, 'C:/OSPanel/home/.workspace-organizer-private-fixture');
    $appRootProperty = $diagnosticsReflection->getProperty('appRoot');
    $appRootProperty->setValue($diagnostics, 'C:/OSPanel/domains/notes.local');

    $sanitizeMethod = $diagnosticsReflection->getMethod('sanitize');
    $windowsPathFixture = $sanitizeMethod->invoke(
        $diagnostics,
        'c:/ospanel/home/.workspace-organizer-private-fixture/updates/workspace-maintenance.json',
        0
    );
    supportDiagnosticsAssert(
        $windowsPathFixture === '[private-storage]/updates/workspace-maintenance.json',
        'Windows-путь private storage раскрывается при отличии регистра drive/path'
    );

    $grant = (new SupportDiagnostics($root))->createGrant(7, 120);
    $token = (string) ($grant['token'] ?? '');
    supportDiagnosticsAssert(
        preg_match('/^[A-Za-z0-9_-]{40,80}$/D', $token) === 1,
        'одноразовый токен имеет неверный формат'
    );

    $grantFiles = glob($temp . '/support-diagnostics/grants/*.json');
    $bundleFiles = glob($temp . '/support-diagnostics/bundles/*.zip');
    supportDiagnosticsAssert(
        is_array($grantFiles) && count($grantFiles) === 1,
        'не создан единственный grant-файл'
    );
    supportDiagnosticsAssert(
        is_array($bundleFiles) && count($bundleFiles) === 1,
        'не создан единственный диагностический снимок'
    );

    $grantBytes = (string) file_get_contents($grantFiles[0]);
    supportDiagnosticsAssert(
        !str_contains($grantBytes, $token),
        'одноразовый токен хранится открытым текстом'
    );
    supportDiagnosticsAssert(
        str_contains($grantBytes, hash('sha256', $token)),
        'grant не хранит SHA-256 одноразового токена'
    );

    $bundleBytes = (string) file_get_contents($bundleFiles[0]);
    supportDiagnosticsAssert(
        str_starts_with($bundleBytes, "PK\x03\x04"),
        'диагностический пакет не является ZIP'
    );

    foreach ([
        'DB-PASSWORD-MUST-NOT-LEAK',
        'PASSWORD-MUST-NOT-LEAK',
        'TOKEN-MUST-NOT-LEAK',
    ] as $secret) {
        supportDiagnosticsAssert(
            !str_contains($bundleBytes, $secret),
            'секрет попал в диагностический ZIP'
        );
    }

    $zipFiles = supportDiagnosticsReadStoredZip($bundleBytes);
    foreach ([
        'manifest.json',
        'README.txt',
        'hosting-profile.json',
        'health.json',
        'maintenance.json',
        'service-events.json',
        'updater-transactions.json',
        'security-summary.json',
        'privacy.json',
    ] as $requiredFile) {
        supportDiagnosticsAssert(
            array_key_exists($requiredFile, $zipFiles),
            "в диагностическом ZIP отсутствует {$requiredFile}"
        );
    }

    $manifest = json_decode($zipFiles['manifest.json'], true, 64, JSON_THROW_ON_ERROR);
    supportDiagnosticsAssert(
        ($manifest['schema'] ?? null) === 2
            && ($manifest['format'] ?? null) === 'zip-store'
            && ($manifest['retrieval']['one_time'] ?? null) === true
            && ($manifest['retrieval']['user_consent_required'] ?? null) === true
            && ($manifest['retrieval']['remote_shell_access'] ?? null) === false
            && ($manifest['retrieval']['live_log_access'] ?? null) === false,
        'manifest ZIP не закрепляет схему и границы удалённого доступа'
    );

    foreach (($manifest['files'] ?? []) as $name => $metadata) {
        supportDiagnosticsAssert(isset($zipFiles[$name]), "manifest ссылается на отсутствующий файл {$name}");
        supportDiagnosticsAssert(
            hash_equals((string) ($metadata['sha256'] ?? ''), hash('sha256', $zipFiles[$name])),
            "SHA-256 файла {$name} не совпадает с manifest"
        );
    }

    $privacy = json_decode($zipFiles['privacy.json'], true, 64, JSON_THROW_ON_ERROR);
    supportDiagnosticsAssert(
        ($privacy['user_content_included'] ?? null) === false
            && ($privacy['environment_file_included'] ?? null) === false
            && ($privacy['database_dump_included'] ?? null) === false
            && ($privacy['secrets_redacted'] ?? null) === true,
        'privacy.json не закрепляет запрет пользовательских данных и секретов'
    );

    $hosting = json_decode($zipFiles['hosting-profile.json'], true, 64, JSON_THROW_ON_ERROR);
    supportDiagnosticsAssert(
        isset($hosting['php']['version'], $hosting['php']['sapi'])
            && array_key_exists('open_basedir_enabled', $hosting['php'])
            && isset($hosting['php']['process_api'])
            && isset($hosting['php']['opcache'])
            && isset($hosting['filesystem'])
            && isset($hosting['database']['privileges'])
            && isset($hosting['updater']['recommended_mode'])
            && ($hosting['updater']['support_zip_requires_zip_extension'] ?? null) === false,
        'hosting-profile.json не содержит обязательный профиль ограниченного хостинга'
    );

    $supportSource = (string) file_get_contents($root . '/core/SupportDiagnostics.php');
    supportDiagnosticsAssert(
        str_contains($supportSource, "command denied to user")
            && str_contains($supportSource, "'[redacted]'@'[redacted]'"),
        'сырой updater-журнал не очищается от MySQL username/host'
    );

    $serviceSource = (string) file_get_contents($root . '/core/ServiceLog.php');
    supportDiagnosticsAssert(
        str_contains($serviceSource, 'registerRuntimeCapture')
            && str_contains($serviceSource, "'runtime.fatal'"),
        'сервисный журнал не фиксирует фатальные ошибки web-runtime'
    );

    $index = (string) file_get_contents($root . '/index.php');
    $supportOffset = strpos($index, '\\Core\\SupportDiagnostics::handleRequest(SITEPATH)');
    $recoveryOffset = strpos($index, '\\Core\\UpdateBootRecoveryGate::enforce(SITEPATH)');
    $schemaOffset = strpos($index, '\\Core\\SchemaReadiness::inspect(SITEPATH)');
    supportDiagnosticsAssert(
        $supportOffset !== false
            && $recoveryOffset !== false
            && $schemaOffset !== false
            && $supportOffset < $recoveryOffset
            && $supportOffset < $schemaOffset,
        'одноразовый endpoint должен работать до recovery/schema-barrier'
    );

    supportDiagnosticsAssert(
        str_contains($supportSource, '@unlink($grantPath)')
            && str_contains($supportSource, '@unlink($bundlePath)')
            && str_contains($supportSource, 'MAX_TTL = 1800')
            && str_contains($supportSource, "header('Content-Type: application/zip')")
            && str_contains($supportSource, 'X-Notes-Diagnostics-SHA256')
            && str_contains($supportSource, 'HTTP_AUTHORIZATION')
            && str_contains($supportSource, 'requestToken')
            && str_contains($supportSource, 'Bearer'),
        'одноразовость, ZIP-выдача или ограниченный срок доступа не закреплены'
    );

    $adminRuntime = (string) file_get_contents($root . '/modules/admin/AdminRuntimeProvider.php');
    $adminView = (string) file_get_contents($root . '/modules/admin/views/updates.php');
    supportDiagnosticsAssert(
        str_contains($adminRuntime, 'admin_support_diagnostics_create')
            && str_contains($adminRuntime, 'CSRFMiddleware::class'),
        'создание пакета не защищено Admin/CSRF-контуром'
    );
    supportDiagnosticsAssert(
        str_contains($adminView, 'Создать ZIP для поддержки')
            && str_contains($adminView, 'Одноразовая ссылка')
            && str_contains($adminView, 'первого скачивания')
            && str_contains($adminView, 'не получает постоянный доступ'),
        'Admin не объясняет пользователю одноразовую модель доступа'
    );

    fwrite(
        STDOUT,
        "[OK] сервисный журнал, профиль хостинга и одноразовый ZIP-пакет диагностики\n"
    );
} finally {
    if ($oldPrivate === false) {
        putenv('PRIVATE_STORAGE_PATH');
    } else {
        putenv('PRIVATE_STORAGE_PATH=' . $oldPrivate);
    }

    if ($oldServiceLog === false) {
        putenv('SERVICE_LOG_PATH');
    } else {
        putenv('SERVICE_LOG_PATH=' . $oldServiceLog);
    }

    if ($oldDbPass === false) {
        putenv('DBPASS');
    } else {
        putenv('DBPASS=' . $oldDbPass);
    }

    supportDiagnosticsRemoveTree($temp);
}
