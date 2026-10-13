<?php

declare(strict_types=1);

/** Read-only target requirements; usable before loading the 1.1 runtime. */
final class PreparationRequirements
{
    /** @param list<string> $files */
    public static function inspect(string $root, array $files): array
    {
        $checks = [];
        $add = static function (string $name, bool $ok, string $detail, string $action) use (&$checks): void {
            $checks[] = compact('name', 'ok', 'detail', 'action');
        };
        $add('PHP для 1.1', PHP_VERSION_ID >= 80200, PHP_VERSION, 'Выберите PHP 8.2 или новее для сайта, затем перезапустите PHP.');
        foreach (['mysqli', 'pdo_mysql', 'openssl', 'sodium', 'zlib', 'mbstring', 'fileinfo', 'gd'] as $extension) {
            $add('Расширение ' . $extension, extension_loaded($extension), extension_loaded($extension) ? 'Включено' : 'Отсутствует', 'Включите расширение ' . $extension . ' в PHP сайта.');
        }
        $version = @file_get_contents($root . '/core/Version.php');
        $add('Исходная версия', is_string($version) && preg_match('/VERSION_CODE\s*=\s*10016\s*;/', $version) === 1, 'Подготовительный патч предназначен для 1.0.16', 'Используйте этот патч только на установленной версии 1.0.16.');
        $unwritable = [];
        foreach ($files as $file) {
            $target = $root . '/' . $file;
            $parent = dirname($target);
            while (!file_exists($parent) && dirname($parent) !== $parent) {
                $parent = dirname($parent);
            }
            $resolved = realpath($parent);
            $inside = is_string($resolved) && self::inside($resolved, $root);
            if (!$inside || is_link($target) || !is_writable($parent) || (file_exists($target) && (!is_file($target) || !is_writable($target)))) {
                $unwritable[] = $file;
            }
        }
        $add('Замена файлов обновлятора', $unwritable === [], $unwritable === [] ? 'Доступна' : implode(', ', $unwritable), 'Разрешите пользователю PHP запись в указанные файлы и их каталоги. Символические ссылки не допускаются.');
        try {
            $private = (new \Core\PrivateStorageResolver($root))->candidate();
            $parent = $private;
            while (!file_exists($parent) && dirname($parent) !== $parent) {
                $parent = dirname($parent);
            }
            $privateReady = !self::inside($private, $root) && is_dir($parent) && is_writable($parent);
            $add('Приватное хранилище', $privateReady, $privateReady ? 'Вне каталога сайта, запись разрешена' : 'Запись недоступна', 'Настройте PRIVATE_STORAGE_PATH вне web-каталога и разрешите запись пользователю PHP.');
            $free = \Core\HostingCompatibility::freeDiskBytes($parent);
            $add('Свободное место для подготовки', $free !== null && $free >= 67108864, $free === null ? 'Не удалось определить' : (string) round($free / 1048576) . ' МБ', 'Освободите минимум 64 МБ для подготовительного патча. Полное обновление отдельно проверяет место для резервной копии БД и файлов.');
        } catch (\Throwable) {
            $add('Приватное хранилище', false, 'Безопасное хранилище недоступно', 'Проверьте PRIVATE_STORAGE_PATH: каталог должен быть вне web-каталога и доступен для записи PHP.');
        }
        try {
            $config = \Core\Config::$db_connection;
            $db = new \mysqli((string) $config['hostname'], (string) $config['username'], (string) $config['password'], (string) $config['database'], (int) $config['port']);
            $support = \Core\HostingCompatibility::databaseServerSupport($db->server_info);
            $db->close();
            $add('База данных', $support['supported'], $support['message'], 'Проверьте параметры БД в .env и поддерживаемую версию MySQL/MariaDB.');
        } catch (\Throwable) {
            $add('База данных', false, 'Не удалось подключиться', 'Проверьте DBHOST, DBPORT, DBNAME, DBUSER и DBPASS в .env.');
        }
        $opcache = !filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN) || filter_var(ini_get('opcache.validate_timestamps'), FILTER_VALIDATE_BOOLEAN);
        $add('Web-обновление: OPcache', $opcache, $opcache ? 'Перечитывает изменённые файлы' : 'Изменения файлов не перечитываются', 'Включите opcache.validate_timestamps=1 и перезапустите PHP.');
        return ['ready' => !in_array(false, array_column($checks, 'ok'), true), 'checks' => $checks];
    }

    private static function inside(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $root = strtolower($root);
        }
        return $path === $root || str_starts_with($path . '/', $root . '/');
    }
}
