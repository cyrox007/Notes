<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/UpdateDatabaseRestorer.php';

$path = tempnam(sys_get_temp_dir(), 'updater-stream-');
if ($path === false) throw new RuntimeException('Не удалось создать тестовый дамп');
try {
    $handle = fopen($path, 'wb');
    $line = "INSERT INTO `items` (`payload`) VALUES (X'" . str_repeat('AB', 1024) . "');\n";
    // Дамп существенно больше доступной памяти процесса (запуск с 32 МБ).
    for ($i = 0; $i < 65536; ++$i) fwrite($handle, $line);
    fwrite($handle, "DELIMITER $\nCREATE TRIGGER `test` AFTER INSERT ON `items`\n"
        . "FOR EACH ROW BEGIN\nSET @n = 1;\nEND$\nDELIMITER ;\n");
    fclose($handle);
    $restorer = new Core\UpdateDatabaseRestorer();
    $restorer->validateDump($path);
    if (memory_get_peak_usage(true) > 16 * 1024 * 1024) {
        throw new RuntimeException('Память растёт с общим размером дампа');
    }
    foreach ([
        "SET NAMES utf8mb4;\nINSERT INTO `items` VALUES (1)",
        "SET NAMES utf8mb4;\n\xFF;\n",
        str_repeat('x', 8 * 1024 * 1024 + 1) . ";\n",
        "-- пустой дамп\n",
        "SELECT 1\nDELIMITER $\n",
    ] as $invalid) {
        file_put_contents($path, $invalid);
        $rejected = false;
        try { $restorer->validateDump($path); }
        catch (RuntimeException $e) { $rejected = true; }
        if (!$rejected) throw new RuntimeException('Повреждённый дамп принят');
    }
    echo "[OK] Потоковый разбор большого дампа и отказ до удаления таблиц\n";
} finally {
    unlink($path);
}
