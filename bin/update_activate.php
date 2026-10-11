<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/CliRuntime.php';
\Core\CliRuntime::assertCliHidden();
require_once $root . '/core/UpdateRemoteTransport.php';

$options = getopt('', ['server:', 'installation-id:', 'activation-file:', 'credentials-out:', 'help']);
if (isset($options['help'])) {
    echo "Активация доступа к загрузке обновлений без изменения локальной лицензии\n"
        . "php bin/update_activate.php --server=https://updates.example.com/ --installation-id=UUID \\\n"
        . "  --activation-file=/private/one-time-code --credentials-out=/private/update-access.json\n"
        . "Используйте Installation ID из /admin/license. Файл результата не должен существовать заранее.\n";
    exit;
}
$output = null;
$destination = (string) ($options['credentials-out'] ?? '');
try {
    $base = (string) ($options['server'] ?? '');
    \Core\UpdateDownloadCredentials::validateBaseUrl($base);
    $installation = strtolower(trim((string) ($options['installation-id'] ?? '')));
    $codePath = (string) ($options['activation-file'] ?? '');
    if (!is_file($codePath) || filesize($codePath) > 128) {
        throw new RuntimeException('Требуется приватный файл с одноразовым кодом активации');
    }
    $code = trim((string) file_get_contents($codePath));
    if (preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $installation) !== 1
        || preg_match('/^[0-9a-f]{64}$/D', $code) !== 1) {
        throw new RuntimeException('Некорректный Installation ID или код активации');
    }
    \Core\UpdateDownloadCredentials::assertExternalPath($destination);
    // Резервируем файл результата до расходования одноразового кода.
    // Действующие credentials никогда не перезаписываются.
    $old = umask(0077);
    try {
        $output = @fopen($destination, 'xb');
    } finally {
        umask($old);
    }
    if ($output === false) {
        throw new RuntimeException('Не удалось создать файл credentials; укажите новый приватный внешний путь');
    }
    $result = (new \Core\UpdateHttpsTransport())->activate($base, $installation, $code);
    new \Core\UpdateDownloadCredentials($result);
    if (($result['installation_id'] ?? '') !== $installation || ($result['base_url'] ?? '') !== $base) {
        throw new RuntimeException('Ответ активации не соответствует запрошенной установке или серверу');
    }
    $bytes = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (fwrite($output, $bytes) !== strlen($bytes) || !fflush($output)) {
        throw new RuntimeException('Не удалось сохранить активацию; запросите новый одноразовый код');
    }
    fclose($output);
    $output = null;
    echo "Доступ к обновлениям активирован. Credentials сохранены в приватном файле.\n"
        . "Установите UPDATE_ACCESS_MODE=online, UPDATE_CREDENTIALS_FILE в путь результата,\n"
        . "а UPDATE_FEED_URL=" . $base . "stable/feed.json в .env.\n";
} catch (Throwable $e) {
    if (is_resource($output)) {
        fclose($output);
        @unlink($destination);
    }
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . "\n");
    exit(1);
}
