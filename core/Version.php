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
     * 0.8.0-alpha (текущая) - Major Update: Task & File Management
     * - Добавлен модуль управления задачами с ежедневником
     * - Добавлен модуль управления личными файлами пользователя
     * - Мультимедиа плеер для аудио/видео файлов
     * - CodeExplorer для просмотра кода с подсветкой синтаксиса
     * - Улучшена система регистрации по инвайт-коду
     * - Обновлена система авторизации с CSRF защитой
     * - Расширен профиль пользователя с редактированием
     * - Админ-панель с управлением пользователями и кастомными полями
     * - Интеграция WebSocket для мессенджера и уведомлений
     * - Система заметок с двойным шифрованием (AES-256)
     * - Полноценная ORM с поддержкой MySQL/MariaDB и SQLite
     * - Логирование запросов к БД
     * - Middleware для маршрутизации
     * - Исправлены проблемы безопасности
     * 
     * 0.7.0-alpha - Messaging & Notes System
     * - Добавлена система сообщений (мессенджер) с real-time обновлениями
     * - Двойное шифрование сообщений и заметок
     * - WebSocket сервер для мгновенных уведомлений
     * - Статусы онлайн/офлайн пользователей
     * - Индикатор набора текста
     * - Шаринг заметок с разными уровнями доступа
     * - Загрузка файлов в сообщения
     * - Голосовые сообщения через MediaRecorder API
     * 
     * 0.6.0-alpha - Profile & Admin Panel
     * - Расширенный профиль пользователя с аватаром
     * - Смена пароля с проверкой сложности
     * - Удаление аккаунта с подтверждением
     * - Админ-панель для управления пользователями
     * - Настройка кастомных полей профиля
     * - Блокировка/разблокировка пользователей
     * - Валидация email и телефона
     * 
     * 0.5.0-alpha - ORM & Database Layer
     * - Полная переработка ORM системы
     * - Поддержка SELECT, JOIN, WHERE, GET, FIRST
     * - Поддержка нескольких СУБД (MySQL, MariaDB, SQLite)
     * - Миграции базы данных
     * - Логирование SQL запросов
     * - Connection pooling
     * 
     * 0.4.0-alpha - Core Architecture
     * - Модификация session, redirect, getRoute для Smarty
     * - Route Middleware система
     * - Улучшенная маршрутизация запросов
     * - Request data обработка
     * - Рефакторинг контроллеров
     * - Оптимизация ядра системы
     * 
     * 0.3.0-alpha - Templating & Routing
     * - Интеграция Smarty шаблонизатора
     * - Система маршрутизации (Router)
     * - MVC архитектура
     * - Базовая структура контроллеров и моделей
     * - Подключение к базе данных
     * 
     * 0.2.0-alpha - Authentication & Registration
     * - Система регистрации пользователей
     * - Авторизация с хешированием паролей
     * - Управление сессиями
     * - Профиль пользователя (базовый)
     * - Система диалогов и сообщений (начальная)
     * 
     * 0.1.0-alpha - Initial Release
     * - Первый альфа релиз
     * - Базовая структура приложения
     * - Конфигурация окружения
     * - Начальная настройка проекта
     */
    public const VERSION = '0.8.0-alpha';
    
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
    public const VERSION_CODE = 800; // 0.8.0 = 800
    
    /**
     * Дата текущего релиза
     */
    public const RELEASE_DATE = '2026-05-07';
    
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
