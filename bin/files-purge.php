<?php

declare(strict_types=1);

use App\Services\Files\FileSpacePurgeService;
use Core\CliRuntime;
use Core\PrefixPDO;

// Команда не должна быть доступна через HTTP даже при ошибке веб-конфигурации.
require_once __DIR__ . '/../core/CliRuntime.php';
CliRuntime::assertCliHidden();
$root = CliRuntime::loadEnvironment(dirname(__DIR__));

$options = getopt('', ['help','apply','limit:']);
if (isset($options['help'])) {
    echo "Использование: php bin/files-purge.php [--limit=5] [--apply]\n";
    echo "  Без --apply только показывает объекты с истёкшим сроком хранения.\n";
    echo "  --apply выполняет очистку с карантином и восстановлением после отказа.\n";
    echo "  Для автоматического запуска сначала настройте надёжное резервное копирование.\n";
    exit(0);
}
$limitRaw = (string) ($options['limit'] ?? '5');
if (!preg_match('/^[1-9][0-9]?$/D', $limitRaw) || (int) $limitRaw > 50) {
    fwrite(STDERR, "Допустимый --limit: целое число от 1 до 50.\n");
    exit(2);
}
$limit = (int) $limitRaw;

$required = static function (string $name): string {
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException('Не указана переменная окружения ' . $name);
    }
    return trim($value);
};

try {
    $dbName = $required('DBNAME');
    $dbUser = $required('DBUSER');
    $host = (string) (getenv('DBHOST') ?: '127.0.0.1');
    $port = (int) (getenv('DBPORT') ?: 3306);
    require_once $root . '/core/PrefixPDO.php';
    require_once $root . '/modules/files/services/FileSpacePurgeService.php';
    $pdo = new PrefixPDO(
        'mysql:host=' . $host . ';port=' . $port
            . ';dbname=' . $dbName . ';charset=utf8mb4',
        $dbUser,
        (string) (getenv('DBPASS') ?: ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    // Отсутствие PRIVATE_STORAGE_PATH допустимо: используется путь,
    // согласованный с обычным файловым менеджером.
    if (!defined('SITEPATH')) {
        define('SITEPATH', $root);
    }
    $service = new FileSpacePurgeService($pdo);
    if (!isset($options['apply'])) {
        $candidates = $service->candidates($limit);
        echo "Безопасный просмотр: файловые данные не изменены.\n";
        foreach ($candidates as $item) {
            echo sprintf("  #%d: %s; удалён %s; срок %d дней\n",
                (int) $item['id'], (string) $item['name'],
                (string) $item['deleted_at'], (int) $item['trash_retention_days']);
        }
        echo "Кандидатов в выбранном наборе: " . count($candidates) . "\n";
        exit(0);
    }

    $result = $service->run($limit);
    echo sprintf(
        "Очистка завершена: каталогов/объектов-корней %d, файлов %d, байт %d. Кандидатов в следующем наборе: %d.\n",
        $result['processed'], $result['files'],
        $result['bytes'], $result['remaining']
    );
} catch (Throwable $error) {
    fwrite(STDERR, "Очистка не выполнена: " . $error->getMessage() . "\n");
    exit(1);
}
