<?php

declare(strict_types=1);

use Core\UpdateAccessActivationTransport;
use Core\UpdateAccessBootstrap;
use Core\UpdateDownloadCredentials;
use Core\Version;

$root = dirname(__DIR__, 2);
require_once $root . '/core/Version.php';
require_once $root . '/core/UpdateAccessBootstrap.php';

function autoUpdateAssert(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }

    fwrite(STDERR, "[ОШИБКА] {$message}\n");
    exit(1);
}

function autoUpdateRemoveTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        return;
    }

    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) {
        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target) && !is_link($target)) {
            autoUpdateRemoveTree($target);
            continue;
        }
        @unlink($target);
    }
    @rmdir($path);
}

final class AutomaticUpdateBootstrapFakeTransport implements UpdateAccessActivationTransport
{
    public int $calls = 0;

    /** @var array<string,mixed> */
    public array $lastRequest = [];

    public function activate(string $baseUrl, string $installationId, string $activationCode): array
    {
        throw new RuntimeException('Старый activation code не должен использоваться');
    }

    public function activateWithLicense(
        string $baseUrl,
        string $installationId,
        string $licenseToken,
        string $version,
        int $versionCode,
        string $channel
    ): array {
        $this->calls++;
        $this->lastRequest = [
            'base_url' => $baseUrl,
            'installation_id' => $installationId,
            'license_token' => $licenseToken,
            'version' => $version,
            'version_code' => $versionCode,
            'channel' => $channel,
        ];

        return [
            'schema' => 1,
            'installation_id' => $installationId,
            'token' => str_repeat('a', 64),
            'base_url' => $baseUrl,
        ];
    }
}

$temp = sys_get_temp_dir() . '/wo-auto-update-' . bin2hex(random_bytes(6));
autoUpdateAssert(mkdir($temp, 0700, true), 'Не удалось создать временный каталог');
$private = $temp . '/private';
autoUpdateAssert(mkdir($private, 0700, true), 'Не удалось создать private storage');

$environment = [
    'PRIVATE_STORAGE_PATH',
    'UPDATE_ACCESS_MODE',
    'UPDATE_CREDENTIALS_FILE',
    'UPDATE_SERVER_URL',
    'UPDATE_FEED_URL',
    'UPDATE_CHANNEL',
];
$previous = [];
foreach ($environment as $name) {
    $previous[$name] = getenv($name);
}

try {
    $installationId = '65c1545d-c9e0-47f8-9a83-8c511ba0e418';
    $licenseToken = 'wo1.test.payload.signature';

    putenv('PRIVATE_STORAGE_PATH=' . $private);
    putenv('UPDATE_ACCESS_MODE=auto');
    // Имитируем ошибочный путь из 1.0.0: 1.0.2 должен его проигнорировать.
    putenv('UPDATE_CREDENTIALS_FILE=' . $root . '/update-access.json');
    putenv('UPDATE_SERVER_URL');
    putenv('UPDATE_FEED_URL');
    putenv('UPDATE_CHANNEL');

    autoUpdateAssert(
        UpdateAccessBootstrap::feedUrl() === 'https://jsinteractive.ru/api/notes/v1/stable/feed.json',
        'Штатный feed не подставился автоматически'
    );
    autoUpdateAssert(UpdateAccessBootstrap::channel() === 'stable', 'Штатный канал должен быть stable');

    $transport = new AutomaticUpdateBootstrapFakeTransport();
    $bootstrap = new UpdateAccessBootstrap($transport);
    $result = $bootstrap->ensure($installationId, $licenseToken);

    autoUpdateAssert(($result['status'] ?? '') === 'ready', 'Bootstrap не завершился готовым состоянием');
    autoUpdateAssert(($result['source'] ?? '') === 'license', 'Bootstrap должен использовать лицензию');
    autoUpdateAssert($transport->calls === 1, 'Сервер активации должен вызываться один раз');
    autoUpdateAssert(
        ($transport->lastRequest['base_url'] ?? '') === 'https://jsinteractive.ru/api/notes/v1/',
        'Использован неожиданный control plane'
    );
    autoUpdateAssert(
        ($transport->lastRequest['version'] ?? '') === Version::VERSION
            && ($transport->lastRequest['version_code'] ?? 0) === Version::VERSION_CODE,
        'Bootstrap не передал текущую версию установки'
    );

    $credentialPath = UpdateDownloadCredentials::credentialsPath();
    $expectedPrefix = str_replace('\\', '/', realpath($private) ?: $private) . '/update-access/';
    autoUpdateAssert(
        str_starts_with(str_replace('\\', '/', $credentialPath), $expectedPrefix),
        'Credential не перенесён в отдельный каталог private storage'
    );
    autoUpdateAssert(is_file($credentialPath), 'Credential не сохранён');
    autoUpdateAssert(
        !str_starts_with(
            strtolower(str_replace('\\', '/', $credentialPath)),
            strtolower(str_replace('\\', '/', $root)) . '/'
        ),
        'Credential оказался внутри дерева приложения'
    );

    $loaded = UpdateDownloadCredentials::fromEnvironment();
    autoUpdateAssert($loaded !== null, 'Сохранённый credential не читается');
    autoUpdateAssert($loaded->installationId() === $installationId, 'Credential относится к другой установке');
    autoUpdateAssert(
        str_contains(
            $loaded->headersFor('https://jsinteractive.ru/api/notes/v1/stable/feed.json'),
            'X-Notes-Installation: ' . $installationId
        ),
        'Credential не ограничен текущей установкой'
    );

    $second = $bootstrap->ensure($installationId, $licenseToken);
    autoUpdateAssert(($second['source'] ?? '') === 'existing', 'Повторный bootstrap должен использовать готовый credential');
    autoUpdateAssert($transport->calls === 1, 'Готовый credential не должен ротироваться без причины');

    putenv('UPDATE_ACCESS_MODE=offline');
    $offlinePrivate = $temp . '/offline-private';
    autoUpdateAssert(mkdir($offlinePrivate, 0700, true), 'Не удалось создать offline private storage');
    putenv('PRIVATE_STORAGE_PATH=' . $offlinePrivate);
    putenv('UPDATE_CREDENTIALS_FILE=');
    $offline = $bootstrap->ensure($installationId, $licenseToken);
    autoUpdateAssert(($offline['status'] ?? '') === 'offline', 'Offline режим должен оставаться доступным');
    autoUpdateAssert($transport->calls === 1, 'Offline режим не должен обращаться к control plane');

    echo "[OK] Автоматический доступ к обновлениям: лицензия → credential без activation code и ручных путей\n";
} finally {
    foreach ($previous as $name => $value) {
        if ($value === false) {
            putenv($name);
            continue;
        }
        putenv($name . '=' . $value);
    }
    autoUpdateRemoveTree($temp);
}
