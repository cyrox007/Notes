<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/core/CliRuntime.php';
\Core\CliRuntime::assertCliHidden();
\Core\CliRuntime::loadEnvironment($root);
require_once $root . '/core/UpdateManifestVerifier.php';
require_once $root . '/core/UpdateDownloadCredentials.php';
require_once $root . '/core/UpdateReadiness.php';

$options = getopt('', ['json', 'help']);
if (isset($options['help'])) {
    echo "Диагностика готовности обновлятора Workspace Organizer\n\n";
    echo "  php bin/update_doctor.php [--json]\n\n";
    echo "Режим только для чтения: сетевые запросы, загрузки, переходы режима обслуживания, изменения БД и файловой системы не выполняются.\n";
    exit(0);
}

try {
    $result = (new \Core\UpdateReadiness($root))->inspect();
    if (isset($options['json'])) {
        echo json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } else {
        echo 'Готовность обновлятора Workspace Organizer' . PHP_EOL;
        foreach ($result['checks'] as $name => $check) {
            echo sprintf("  [%s] %s%s\n", $check['ok'] ? 'OK' : 'FAIL', $name, $check['message'] !== '' ? ' — ' . $check['message'] : '');
        }
        echo 'Проверка удалённого релиза: ' . ($result['ready_for_check'] ? 'ГОТОВО' : 'НЕ ГОТОВО') . PHP_EOL;
        echo 'Применение обновления:       ' . ($result['ready_for_apply'] ? 'ГОТОВО' : 'НЕ ГОТОВО') . PHP_EOL;
    }
    exit($result['ready_for_apply'] ? 0 : 3);
} catch (Throwable $e) {
    if (isset($options['json'])) {
        echo json_encode([
            'ready_for_check' => false,
            'ready_for_apply' => false,
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
