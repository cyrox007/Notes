<?php

declare(strict_types=1);

use Core\UpdateAccessActivationTransport;
use Core\UpdateAccessBootstrap;
use Core\UpdateDownloadCredentials;
use Core\UpdateCredentialRefreshingTransport;
use Core\UpdateRemoteTransport;
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
            'token' => str_repeat($this->calls === 1 ? 'a' : 'b', 64),
            'base_url' => $baseUrl,
        ];
    }
}

final class AutomaticUpdateRequestFakeTransport implements UpdateRemoteTransport
{
    public function __construct(
        private int $status,
        private string $body = 'ok'
    ) {
    }

    public function fetchText(string $url, int $maxBytes): string
    {
        if ($this->status !== 200) {
            throw new RuntimeException('Тестовый отказ сервера обновлений', $this->status);
        }

        return $this->body;
    }

    public function downloadExact(
        string $url,
        string $destination,
        int $expectedBytes,
        string $expectedSha256
    ): array {
        if ($this->status !== 200) {
            throw new RuntimeException('Тестовый отказ сервера обновлений', $this->status);
        }

        file_put_contents($destination, $this->body);
        return ['bytes' => strlen($this->body), 'sha256' => hash('sha256', $this->body)];
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

    $refreshed = $bootstrap->refresh($installationId, $licenseToken);
    autoUpdateAssert(($refreshed['source'] ?? '') === 'license-refresh', 'Принудительный refresh не отметил источник ротации');
    autoUpdateAssert($transport->calls === 2, 'Refresh должен один раз обратиться к control plane');
    $rotated = UpdateDownloadCredentials::fromEnvironment();
    autoUpdateAssert($rotated !== null, 'Новый credential после refresh не читается');
    autoUpdateAssert(
        str_contains(
            $rotated->headersFor('https://jsinteractive.ru/api/notes/v1/stable/feed.json'),
            'Authorization: Bearer ' . str_repeat('b', 64)
        ),
        'После refresh продолжает использоваться старый credential'
    );
    $quarantine = glob($credentialPath . '.rejected-*') ?: [];
    autoUpdateAssert(count($quarantine) === 1, 'Старый credential не помещён в диагностический карантин');
    $rejected = UpdateDownloadCredentials::fromFile($quarantine[0]);
    autoUpdateAssert(
        str_contains(
            $rejected->headersFor('https://jsinteractive.ru/api/notes/v1/stable/feed.json'),
            'Authorization: Bearer ' . str_repeat('a', 64)
        ),
        'Карантин не сохранил отклонённый credential для диагностики'
    );

    $factoryCalls = 0;
    $refreshCalls = 0;
    $retrying = new UpdateCredentialRefreshingTransport(
        static function () use (&$factoryCalls): UpdateRemoteTransport {
            $factoryCalls++;
            return new AutomaticUpdateRequestFakeTransport($factoryCalls === 1 ? 401 : 200, 'signed-feed');
        },
        static function () use (&$refreshCalls): void {
            $refreshCalls++;
        }
    );
    autoUpdateAssert(
        $retrying->fetchText('https://updates.example/stable/feed.json', 1024) === 'signed-feed',
        'HTTP 401 не был восстановлен повторным запросом'
    );
    autoUpdateAssert($factoryCalls === 2 && $refreshCalls === 1, 'После 401 разрешён не один refresh/retry');

    $deniedFactoryCalls = 0;
    $deniedRefreshCalls = 0;
    $denied = new UpdateCredentialRefreshingTransport(
        static function () use (&$deniedFactoryCalls): UpdateRemoteTransport {
            $deniedFactoryCalls++;
            return new AutomaticUpdateRequestFakeTransport(403);
        },
        static function () use (&$deniedRefreshCalls): void {
            $deniedRefreshCalls++;
        }
    );
    try {
        $denied->fetchText('https://updates.example/stable/feed.json', 1024);
        autoUpdateAssert(false, 'HTTP 403 ошибочно принят как восстанавливаемый credential');
    } catch (RuntimeException $e) {
        autoUpdateAssert($e->getCode() === 403, 'HTTP 403 потерял исходный статус');
    }
    autoUpdateAssert(
        $deniedFactoryCalls === 1 && $deniedRefreshCalls === 0,
        'HTTP 403 не должен ротировать credential'
    );

    $repeat401FactoryCalls = 0;
    $repeat401RefreshCalls = 0;
    $repeat401 = new UpdateCredentialRefreshingTransport(
        static function () use (&$repeat401FactoryCalls): UpdateRemoteTransport {
            $repeat401FactoryCalls++;
            return new AutomaticUpdateRequestFakeTransport(401);
        },
        static function () use (&$repeat401RefreshCalls): void {
            $repeat401RefreshCalls++;
        }
    );
    try {
        $repeat401->fetchText('https://updates.example/stable/feed.json', 1024);
        autoUpdateAssert(false, 'Повторный HTTP 401 ошибочно принят как успешный');
    } catch (RuntimeException $e) {
        autoUpdateAssert($e->getCode() === 401, 'Повторный HTTP 401 потерял исходный статус');
    }
    autoUpdateAssert(
        $repeat401FactoryCalls === 2 && $repeat401RefreshCalls === 1,
        'Повторный HTTP 401 создал цикл ротации credential'
    );

    $remoteCli = (string) file_get_contents($root . '/bin/update_remote.php');
    autoUpdateAssert(
        str_contains($remoteCli, '(new LicenseService())->ensureUpdateAccess();'),
        'CLI-проверка обновлений потеряла автоматический bootstrap по лицензии'
    );
    autoUpdateAssert(
        str_contains($remoteCli, "UpdateDownloadCredentials::accessMode() !== 'offline'"),
        'CLI-проверка не сохраняет явный offline-режим'
    );

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
