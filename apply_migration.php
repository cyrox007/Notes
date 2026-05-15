<?php
/**
 * Скрипт для применения миграции к таблице user_files
 * Изменяет колонку type из ENUM в VARCHAR
 */

require_once __DIR__ . '/core.php';

use Core\DatabaseManager;
use Exception;

echo "=== Применение миграции к таблице user_files ===\n\n";

try {
    $dbManager = DatabaseManager::getInstance();
    
    // SQL запрос на изменение типа колонки
    $sql = "ALTER TABLE `user_files` 
            MODIFY COLUMN `type` VARCHAR(50) NOT NULL DEFAULT 'file' 
            COMMENT 'Тип: folder, file, document, image, video, audio, archive, code'";
    
    echo "Выполнение запроса:\n$sql\n\n";
    
    $pdo = $dbManager->connect();
    $pdo->exec($sql);
    
    echo "✓ Миграция успешно применена!\n";
    echo "Колонка `type` теперь имеет тип VARCHAR(50)\n";
    
} catch (Exception $e) {
    echo "✗ Ошибка при применении миграции: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== Готово ===\n";
