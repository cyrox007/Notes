<?php

namespace Core;

use PDO;
use PDOException;
use Exception;

/**
 * Упрощенный класс для получения соединения с БД.
 * Обертка над DatabaseManager для обратной совместимости.
 * Рекомендуется использовать DatabaseManager::getInstance() для всех операций.
 */
class DatabaseControll {
    /**
     * Получает PDO соединение через DatabaseManager singleton
     */
    public static function connect(): PDO {
        $dbManager = DatabaseManager::getInstance();
        $dbManager->log("[DatabaseControll] Запрошено соединение через connect()", 'DEBUG');
        return $dbManager->getPdo();
    }

    /**
     * @deprecated Используйте DatabaseManager::getInstance() напрямую
     */
    public static function getInstance(): DatabaseManager {
        error_log("[DatabaseControll] WARNING: Используется устаревший метод getInstance()");
        return DatabaseManager::getInstance();
    }
}