<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/RuntimeAutoloader.php';
\Core\RuntimeAutoloader::register($root);
require_once $root . '/core/DatabaseManager.php';

use App\Services\LicenseService;
use App\Services\LicenseVerifier;
use Core\DatabaseManager;

function assertLicenseReads(bool $condition, string $message): void
{
    if ($condition) {
        return;
    }
    fwrite(STDERR, 'Проверка снимка лицензии: ' . $message . PHP_EOL);
    exit(1);
}

final class LicenseSettingsCountingPdo extends PDO
{
    public int $settingsReads = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (preg_match('/SELECT\s+.*\bFROM\s+system_settings\b/is', $query) === 1) {
            $this->settingsReads++;
        }
        return parent::prepare($query, $options);
    }
}

$pdo = new LicenseSettingsCountingPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE system_settings (setting_key TEXT PRIMARY KEY,setting_value TEXT)');
$installationId = '11111111-2222-4333-8444-555555555555';
$insert = $pdo->prepare('INSERT INTO system_settings (setting_key,setting_value) VALUES (?,?)');
$insert->execute([LicenseService::INSTALLATION_ID_KEY, $installationId]);
$insert->execute([LicenseService::LICENSE_TOKEN_KEY, '']);

$service = new LicenseService(
    DatabaseManager::forConnection($pdo),
    null,
    new LicenseVerifier([])
);

$before = $pdo->settingsReads;
$status = $service->status();
assertLicenseReads($pdo->settingsReads - $before === 1, 'действующий статус требует одну выборку');
assertLicenseReads($status['installation_id'] === $installationId, 'неверная установка');
assertLicenseReads($status['valid'] === false && $status['has_token'] === false
    && $status['code'] === 'unlicensed', 'отсутствующий токен не блокирует доступ');

$pdo->prepare('UPDATE system_settings SET setting_value=? WHERE setting_key=?')
    ->execute(['wo1.test.token.signature', LicenseService::LICENSE_TOKEN_KEY]);
$before = $pdo->settingsReads;
$status = $service->status();
assertLicenseReads($pdo->settingsReads - $before === 1, 'проверка после активации повторяет запрос');
assertLicenseReads($status['has_token'] === true && $status['valid'] === false
    && $status['code'] === 'trust_not_configured', 'активация не замечена немедленно');
assertLicenseReads(!array_key_exists('token', $status), 'полный лицензионный токен открыт наружу');

$pdo->prepare('UPDATE system_settings SET setting_value=? WHERE setting_key=?')
    ->execute(['', LicenseService::LICENSE_TOKEN_KEY]);
assertLicenseReads($service->status()['code'] === 'unlicensed',
    'отзыв токена в том же процессе не замечен');

$pdo->prepare('UPDATE system_settings SET setting_value=? WHERE setting_key=?')
    ->execute(['invalid-installation-id', LicenseService::INSTALLATION_ID_KEY]);
$rejected = false;
try {
    $service->status();
} catch (RuntimeException) {
    $rejected = true;
}
assertLicenseReads($rejected, 'некорректная установка не отклонена');

echo 'Статус лицензии: одно чтение, немедленный отзыв и защита идентификатора проверены' . PHP_EOL;
