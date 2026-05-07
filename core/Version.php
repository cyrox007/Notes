<?php
/**
 * Версия продукта
 * 
 * Центральное хранилище версии приложения
 * Формат: MAJOR.MINOR.PATCH-PRERELEASE
 */

declare(strict_types=1);

namespace Core;

class Version 
{
    /**
     * Текущая версия приложения
     * 
     * История версий:
     * 
     * 0.1.5-alpha (текущая)
     * - Добавлена система регистрации по инвайт-коду
     * - Улучшена система авторизации с CSRF защитой
     * - Добавлен профиль пользователя с редактированием
     * - Добавлена админ-панель с настройкой кастомных полей
     * - Интеграция WebSocket для мессенджера
     * - Система заметок с шифрованием
     * - Улучшена структура БД с ORM
     * - Добавлено логирование запросов к БД
     * - Исправлены проблемы безопасности
     * 
     * 0.1.4-alpha
     * - Добавена система сообщений (мессенджер)
     * - Шифрование сообщений на основе AES-256
     * - WebSocket сервер для real-time уведомлений
     * 
     * 0.1.3-alpha
     * - Добавлена система заметок
     * - Базовая авторизация пользователей
     * - Интеграция Smarty шаблонизатора
     * 
     * 0.1.2-alpha
     * - Добавлена маршрутизация запросов
     * - Базовая структура MVC
     * - Подключение к базе данных
     * 
     * 0.1.1-alpha
     * - Начальная настройка проекта
     * - Конфигурация окружения
     * 
     * 0.1.0-alpha
     * - Первый альфа релиз
     * - Базовая структура приложения
     */
    public const VERSION = '0.1.5-alpha';
    
    /**
     * Название продукта
     */
    public const PRODUCT_NAME = 'Workspace Organizer';
    
    /**
     * Статус версии (alpha, beta, rc, stable)
     */
    public const STATUS = 'alpha';
    
    /**
     * Код версии для внутреннего использования
     */
    public const VERSION_CODE = 105; // 0.1.5 = 105
    
    /**
     * Дата текущего релиза
     */
    public const RELEASE_DATE = '2024-01-15';
    
    /**
     * Получить полную строку версии
     * 
     * @return string
     */
    public static function getFullVersion(): string 
    {
        return self::VERSION;
    }
    
    /**
     * Получить информацию о продукте
     * 
     * @return array<string, string>
     */
    public static function getProductInfo(): array 
    {
        return [
            'name' => self::PRODUCT_NAME,
            'version' => self::VERSION,
            'status' => self::STATUS,
            'version_code' => (string)self::VERSION_CODE,
            'release_date' => self::RELEASE_DATE
        ];
    }
    
    /**
     * Проверить является ли текущая версия альфа
     * 
     * @return bool
     */
    public static function isAlpha(): bool 
    {
        return self::STATUS === 'alpha';
    }
    
    /**
     * Сравнить текущую версию с указанной
     * 
     * @param string $version Версия для сравнения
     * @return int -1 если текущая меньше, 0 если равны, 1 если текущая больше
     */
    public static function compare(string $version): int 
    {
        return version_compare(self::VERSION, $version);
    }
}
