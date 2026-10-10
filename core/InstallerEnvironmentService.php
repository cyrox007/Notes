<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use SensitiveParameter;
use Throwable;

/**
 * Подготовка файлового окружения и конфигурации новой установки.
 *
 * HTTP-контекст, форма мастера и операции БД остаются за пределами сервиса.
 */
final class InstallerEnvironmentService
{
    private const STORAGE_DIRECTORIES = [
        'file_manager',
        'messenger',
        'notes',
        'users',
        'rate-limit',
        'logs',
        'legacy',
    ];

    public function randomSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function functionAvailable(string $name): bool
    {
        return HostingCompatibility::functionAvailable($name);
    }

    public function longPollTimeoutSeconds(): int
    {
        $maxExecution = (int) HostingCompatibility::iniValue('max_execution_time');
        if ($maxExecution <= 0) {
            return 15;
        }

        return max(5, min(15, $maxExecution - 2));
    }

    public function iniBytes(string $name): int
    {
        return HostingCompatibility::iniBytes(HostingCompatibility::iniValue($name));
    }

    public function uploadTempWritable(): bool
    {
        $configured = trim(HostingCompatibility::iniValue('upload_tmp_dir'));
        if ($configured !== '') {
            return is_dir($configured) && is_writable($configured);
        }
        if (!$this->functionAvailable('sys_get_temp_dir')) {
            return false;
        }

        $fallback = sys_get_temp_dir();
        return $fallback !== '' && is_dir($fallback) && is_writable($fallback);
    }

    /**
     * @param list<string> $packagedModules
     * @return array<string,array{available:bool,message:string}>
     */
    public function optionalCapabilities(string $basePath, array $packagedModules): array
    {
        $hasMessenger = in_array('messenger', $packagedModules, true);
        $webSocketRuntime = !$hasMessenger || $this->webSocketRuntimeAvailable($basePath);
        $httpsPrerequisites = HostingCompatibility::outboundHttpsPrerequisites();
        $onlineUpdates = $httpsPrerequisites['ok'];
        $procOpen = $this->functionAvailable('proc_open');
        $pcntl = $this->functionAvailable('pcntl_fork');

        return [
            'Онлайн-обновления через HTTPS' => [
                'available' => $onlineUpdates,
                'message' => $onlineUpdates
                    ? 'Локальные PHP-предпосылки готовы. Фактический доступ к исходящему TCP/443 проверяется при обращении к серверу обновлений.'
                    : 'Установка работает, но встроенный обновлятор не сможет скачать релиз: отсутствуют локальные TLS/DNS-предпосылки.',
            ],
            'WebSocket-ускоритель Messenger' => [
                'available' => $webSocketRuntime,
                'message' => $webSocketRuntime
                    ? 'Можно включить позже; Long Poll уже обеспечивает полный Messenger.'
                    : 'Недоступен в этом PHP; Messenger будет полностью работать через Long Poll.',
            ],
            'Изолированный обновлятор через proc_open' => [
                'available' => $procOpen,
                'message' => $procOpen
                    ? 'Доступен ускоренный режим обновления в отдельном PHP-процессе.'
                    : 'Не требуется: обновлятор автоматически использует совместимый web-режим.',
            ],
            'Фоновый WebSocket через pcntl' => [
                'available' => $pcntl,
                'message' => $pcntl
                    ? 'Доступен фоновый режим Unix.'
                    : 'Не требуется: WebSocket можно запускать менеджером процессов или не использовать.',
            ],
        ];
    }

    public function privateStorageCandidate(string $basePath): string
    {
        try {
            return (new PrivateStorageResolver($basePath))->candidate();
        } catch (Throwable) {
            return '';
        }
    }

    public function preparePrivateStorage(string $path, string $basePath): string
    {
        $real = (new PrivateStorageResolver($basePath))->prepareExplicit($path);
        foreach (self::STORAGE_DIRECTORIES as $directory) {
            $this->prepareDirectory($real . DIRECTORY_SEPARATOR . $directory, 0700, $directory);
        }

        $this->assertPrivateStorageFilesystemContract($real);
        return $real;
    }

    public function prepareRuntimeDirectories(string $basePath): void
    {
        foreach (['compile', 'cache'] as $directory) {
            $path = $basePath . '/' . $directory;
            $this->prepareDirectory($path, 0750, $directory);
            if (!is_writable($path)) {
                throw new RuntimeException('Runtime-каталог недоступен на запись: ' . $directory);
            }
        }
    }

    /**
     * @param list<string> $schemaFiles
     * @param list<string> $packagedModules
     * @return array<string,bool>
     */
    public function requirements(string $basePath, array $schemaFiles, array $packagedModules): array
    {
        $checks = [
            'PHP 8.2+' => version_compare(PHP_VERSION, '8.2.0', '>='),
            'Нативное ядро приложения' => is_file($basePath . '/core/Environment.php')
                && is_file($basePath . '/core/NativeViewRenderer.php'),
            'mbstring' => extension_loaded('mbstring'),
            'ctype' => extension_loaded('ctype'),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'mysqli' => extension_loaded('mysqli'),
            'sodium' => extension_loaded('sodium'),
            'openssl' => extension_loaded('openssl'),
            'zlib' => extension_loaded('zlib'),
            'fileinfo' => extension_loaded('fileinfo'),
            'gd' => extension_loaded('gd'),
            'Хеширование паролей Argon2id' => in_array('argon2id', password_algos(), true),
            'random_bytes' => function_exists('random_bytes'),
            'ini_get' => $this->functionAvailable('ini_get'),
            'getenv / putenv' => HostingCompatibility::processEnvironmentAvailable(),
            'HTTP-загрузка файлов' => filter_var(
                HostingCompatibility::iniValue('file_uploads'),
                FILTER_VALIDATE_BOOLEAN
            ),
            'Доступный временный каталог PHP для загрузок' => $this->uploadTempWritable(),
            'flock / атомарное переименование' => $this->filesystemFunctionsAvailable(),
            'Запись .env в корень проекта' => is_writable($basePath),
            'Схемы БД состава модулей' => $this->schemaFilesAvailable($schemaFiles),
        ];

        if (in_array('messenger', $packagedModules, true)) {
            $checks['HTTP Long Poll Messenger'] = $this->functionAvailable('session_write_close')
                && $this->functionAvailable('usleep')
                && $this->functionAvailable('connection_aborted');
        }

        try {
            $this->prepareRuntimeDirectories($basePath);
            $checks['Каталоги runtime доступны для записи'] = true;
        } catch (Throwable) {
            $checks['Каталоги runtime доступны для записи'] = false;
        }

        return $checks;
    }

    public function writeEnvironmentFile(string $file, #[SensitiveParameter] array $data): void
    {
        $privateStorage = rtrim((string) $data['private_storage'], '/');
        $lines = $this->environmentLines($data, $privateStorage);
        $temp = $file . '.installing-' . bin2hex(random_bytes(6));

        if (file_put_contents($temp, implode("\n", $lines), LOCK_EX) === false) {
            throw new RuntimeException('Не удалось подготовить .env. Проверьте права корня проекта.');
        }
        @chmod($temp, 0600);

        if (rename($temp, $file)) {
            @chmod($file, 0600);
            return;
        }

        @unlink($temp);
        throw new RuntimeException('Не удалось атомарно создать .env.');
    }

    private function webSocketRuntimeAvailable(string $basePath): bool
    {
        return is_file($basePath . '/modules/messenger/socket/NativeMessengerServer.php')
            && is_file($basePath . '/modules/messenger/socket/SocketHandshake.php')
            && is_file($basePath . '/modules/messenger/socket/SocketFrameCodec.php')
            && $this->functionAvailable('stream_socket_server')
            && $this->functionAvailable('stream_select');
    }

    private function prepareDirectory(string $path, int $mode, string $label): void
    {
        if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
            throw new RuntimeException('Не удалось создать каталог: ' . $label);
        }
        @chmod($path, $mode);
    }

    private function assertPrivateStorageFilesystemContract(string $root): void
    {
        foreach (['fopen', 'flock', 'rename', 'unlink'] as $function) {
            if (!$this->functionAvailable($function)) {
                throw new RuntimeException('Private storage требует доступную PHP-функцию ' . $function);
            }
        }

        $source = $root . DIRECTORY_SEPARATOR . '.hosting-fs-probe-' . bin2hex(random_bytes(6));
        $target = $source . '.renamed';
        $handle = @fopen($source, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Private storage не позволяет создать проверочный lock-файл');
        }

        try {
            $this->assertStorageWrite($handle);
            fclose($handle);
            $handle = null;

            if (!@rename($source, $target) || !is_file($target)) {
                throw new RuntimeException('Private storage не поддерживает требуемое атомарное переименование');
            }
        } finally {
            if (is_resource($handle)) {
                @flock($handle, LOCK_UN);
                fclose($handle);
            }
            @unlink($source);
            @unlink($target);
        }
    }

    /** @param resource $handle */
    private function assertStorageWrite($handle): void
    {
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Файловая система private storage не поддерживает требуемый flock');
        }
        if (fwrite($handle, 'ok') !== 2 || !fflush($handle)) {
            throw new RuntimeException('Private storage не обеспечивает надёжную запись проверочного файла');
        }
        @flock($handle, LOCK_UN);
    }

    private function filesystemFunctionsAvailable(): bool
    {
        foreach (['flock', 'rename', 'fopen', 'unlink'] as $function) {
            if (!$this->functionAvailable($function)) {
                return false;
            }
        }
        return true;
    }

    /** @param list<string> $schemaFiles */
    private function schemaFilesAvailable(array $schemaFiles): bool
    {
        if ($schemaFiles === []) {
            return false;
        }
        foreach ($schemaFiles as $file) {
            if (!is_file($file) || is_link($file)) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    private function environmentLines(#[SensitiveParameter] array $data, string $privateStorage): array
    {
        return [
            '# Создано web-установщиком Workspace Organizer',
            '# Профиль установки: ' . (string) ($data['install_mode'] ?? 'hosting'),
            'DBDRIVER=mysql',
            'DBHOST=' . $this->envQuoted((string) $data['db_host']),
            'DBPORT=' . (int) $data['db_port'],
            'DBUSER=' . $this->envQuoted((string) $data['db_user']),
            'DBPASS=' . $this->envQuoted((string) $data['db_pass']),
            'DBNAME=' . $this->envQuoted((string) $data['db_name']),
            '',
            'UNIQUE_KEY=' . $this->envQuoted((string) $data['unique_key']),
            'MSG_SECRET_KEY=' . $this->envQuoted((string) $data['message_key']),
            'WS_TICKET_SECRET=' . $this->envQuoted((string) $data['ws_ticket_secret']),
            '',
            'PRIVATE_STORAGE_PATH=' . $this->envQuoted($privateStorage),
            'UPLOAD_DIR=' . $this->envQuoted($privateStorage . '/legacy/file_manager'),
            'NOTES_UPLOAD_DIR=' . $this->envQuoted($privateStorage . '/legacy/notes'),
            'MESSENGER_UPLOAD_DIR=' . $this->envQuoted($privateStorage . '/legacy/messenger'),
            '',
            'UPDATE_SERVER_URL=https://jsinteractive.ru/api/notes/v1/',
            'UPDATE_FEED_URL=https://jsinteractive.ru/api/notes/v1/stable/feed.json',
            'UPDATE_CHANNEL=stable',
            'UPDATE_ACCESS_MODE=auto',
            'UPDATE_CREDENTIALS_FILE=',
            '',
            'MAX_UPLOAD_SIZE=10485760',
            'NOTES_MAX_UPLOAD_SIZE=10485760',
            'MAX_NOTE_ATTACHMENTS=10',
            'MESSENGER_MAX_UPLOAD_SIZE=10485760',
            'MESSENGER_MAX_VOICE_SIZE=5242880',
            'MESSENGER_GROUP_AVATAR_MAX_SIZE=2097152',
            'PROFILE_AVATAR_MAX_SIZE=2097152',
            'MESSENGER_ORPHAN_TTL_SECONDS=86400',
            'MESSENGER_SEARCH_SCAN_LIMIT=1000',
            'MESSENGER_LONG_POLL_TIMEOUT_SECONDS=' . $this->longPollTimeoutSeconds(),
            '',
            'SITEURL=' . $this->envQuoted((string) $data['site_url']),
            'BASE_PATH=' . $this->envQuoted((string) $data['base_path']),
            'REGISTRATION_INVITE_CODE=',
            '',
            'WS_ENABLED=' . (!empty($data['ws_enabled']) ? '1' : '0'),
            'WS_HOST=127.0.0.1',
            'WS_PORT=27800',
            'WS_PUBLIC_URL=' . $this->envQuoted((string) $data['ws_public_url']),
            'WS_ALLOWED_ORIGINS=' . $this->envQuoted((string) $data['site_url']),
            'WS_MAX_CONNECTIONS=256',
            'WS_MAX_PAYLOAD_BYTES=2097152',
            '',
            'LOG_LEVEL=INFO',
            'LOG_FILE=' . $this->envQuoted($privateStorage . '/logs/app.log'),
            'AUDIT_LOG_RETENTION_DAYS=180',
            'SESSION_LIFETIME=3600',
            'MAX_LOGIN_ATTEMPTS=5',
            'AUTH_RATE_LIMIT_WINDOW_SECONDS=300',
            'UPLOAD_RATE_LIMIT_ATTEMPTS=60',
            'UPLOAD_RATE_LIMIT_WINDOW_SECONDS=60',
            'CSRF_ENABLED=true',
            'INSTALL_DATE=' . $this->envQuoted((string) $data['install_date']),
            '',
        ];
    }

    private function envQuoted(string $value): string
    {
        return '"' . str_replace(["\\", '"', "\r", "\n"], ['\\\\', '\\"', '', '\\n'], $value) . '"';
    }
}
