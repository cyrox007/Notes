<?php

declare(strict_types=1);

namespace App\Services;

use Core\DatabaseManager;
use Core\HostingCompatibility;
use InvalidArgumentException;

final class FileUploadLimitService
{
    public const DEFAULT_LIMIT_BYTES = 10 * 1024 * 1024;
    public const MIN_LIMIT_BYTES = 1024 * 1024;
    public const MAX_LIMIT_BYTES = 10 * 1024 * 1024 * 1024 * 1024;
    public const SETTING_KEY = 'file_manager_max_upload_bytes';

    private DatabaseManager $db;
    private ?PermissionService $permissions;

    public function __construct(?DatabaseManager $db = null, ?PermissionService $permissions = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance();
        $this->permissions = $permissions;
    }

    public function configuredLimitBytes(): int
    {
        $value = $this->db->fetchValue(
            'SELECT setting_value FROM system_settings WHERE setting_key = :setting_key LIMIT 1',
            [':setting_key' => self::SETTING_KEY]
        );
        if (is_numeric($value)) {
            return $this->normalizeLimit((int) $value);
        }

        $environment = getenv('MAX_UPLOAD_SIZE');
        if (is_string($environment) && ctype_digit($environment) && (int) $environment > 0) {
            return $this->normalizeLimit((int) $environment);
        }

        return self::DEFAULT_LIMIT_BYTES;
    }

    public function setConfiguredLimit(int $actorId, int $limitBytes): void
    {
        $this->permissions()->requirePermission($actorId, 'admin.settings.manage');
        $limitBytes = $this->normalizeLimit($limitBytes);
        $this->db->execute(
            "INSERT INTO system_settings (setting_key,setting_value,setting_type,category,description,is_editable)
             VALUES (:setting_key,:setting_value,'integer','file_manager','Максимальный размер одного загружаемого файла в байтах',1)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP",
            [':setting_key' => self::SETTING_KEY, ':setting_value' => (string) $limitBytes]
        );
    }

    /**
     * Возвращает известные приложению ограничения приёма HTTP-запроса.
     * PHP-пределы определяются точно. Ограничение веб-сервера может быть
     * неизвестно: Apache/Nginx не обязаны раскрывать активную директиву
     * LimitRequestBody/client_max_body_size процессу PHP.
     *
     * @return array<string,mixed>
     */
    public function diagnostics(?int $configuredBytes = null): array
    {
        $configured = $configuredBytes ?? $this->configuredLimitBytes();
        $requiredRequest = $this->recommendedRequestBytes($configured);

        $uploadRaw = trim(HostingCompatibility::iniValue('upload_max_filesize'));
        $postRaw = trim(HostingCompatibility::iniValue('post_max_size'));
        $uploadBytes = $this->finiteIniLimit($uploadRaw);
        $postBytes = $this->finiteIniLimit($postRaw);

        $serverSoftware = trim((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
        $serverType = $this->serverType($serverSoftware);
        $webLimit = $this->webServerLimit($serverType);

        $conflicts = [];
        if ($uploadBytes !== null && $uploadBytes < $configured) {
            $conflicts[] = 'PHP upload_max_filesize меньше лимита, заданного в Workspace';
        }
        if ($postBytes !== null && $postBytes < $requiredRequest) {
            $conflicts[] = 'PHP post_max_size недостаточен для файла этого размера с учётом multipart-запроса';
        }
        if ($webLimit['known'] && $webLimit['bytes'] !== null && $webLimit['bytes'] < $requiredRequest) {
            $conflicts[] = 'Веб-сервер ограничивает размер HTTP-запроса ниже значения Workspace';
        }

        $knownCaps = [$configured];
        if ($uploadBytes !== null) {
            $knownCaps[] = $uploadBytes;
        }
        if ($postBytes !== null) {
            $knownCaps[] = max(1, $postBytes - $this->requestReserveBytes($postBytes));
        }
        if ($webLimit['known'] && $webLimit['bytes'] !== null) {
            $knownCaps[] = max(1, $webLimit['bytes'] - $this->requestReserveBytes($webLimit['bytes']));
        }

        return [
            'configured_bytes' => $configured,
            'required_request_bytes' => $requiredRequest,
            'effective_known_file_bytes' => min($knownCaps),
            'php_upload_max_filesize' => $uploadRaw !== '' ? $uploadRaw : 'не удалось определить',
            'php_upload_max_filesize_bytes' => $uploadBytes,
            'php_post_max_size' => $postRaw !== '' ? $postRaw : 'не удалось определить',
            'php_post_max_size_bytes' => $postBytes,
            'php_ini_file' => $this->phpIniFile(),
            'php_scanned_ini_files' => $this->phpScannedIniFiles(),
            'web_server_software' => $serverSoftware !== '' ? $serverSoftware : 'не удалось определить',
            'web_server_type' => $serverType,
            'web_server_limit_known' => $webLimit['known'],
            'web_server_limit_bytes' => $webLimit['bytes'],
            'web_server_limit_source' => $webLimit['source'],
            'web_server_limit_raw' => $webLimit['raw'],
            'conflict' => $conflicts !== [],
            'conflicts' => $conflicts,
            'recommended_upload_max_filesize' => $this->megabytesDirective($configured),
            'recommended_post_max_size' => $this->megabytesDirective($requiredRequest),
        ];
    }

    public function effectiveKnownFileLimitBytes(): int
    {
        return (int) $this->diagnostics()['effective_known_file_bytes'];
    }

    public function postLimitExceededByCurrentRequest(): bool
    {
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($contentLength <= 0) {
            return false;
        }

        $postBytes = $this->finiteIniLimit(trim(HostingCompatibility::iniValue('post_max_size')));
        return $postBytes !== null && $contentLength > $postBytes;
    }

    public function phpUploadLimitLabel(): string
    {
        $raw = trim(HostingCompatibility::iniValue('upload_max_filesize'));
        return $raw !== '' ? $raw : 'неизвестно';
    }

    private function permissions(): PermissionService
    {
        return $this->permissions ??= new PermissionService($this->db);
    }

    private function normalizeLimit(int $bytes): int
    {
        if ($bytes < self::MIN_LIMIT_BYTES || $bytes > self::MAX_LIMIT_BYTES) {
            throw new InvalidArgumentException('Размер одного файла должен быть от 1 МБ до 10 ТБ');
        }
        return $bytes;
    }

    private function finiteIniLimit(string $raw): ?int
    {
        if ($raw === '' || $raw === '-1' || $raw === '0') {
            return null;
        }
        $bytes = HostingCompatibility::iniBytes($raw);
        return $bytes > 0 ? $bytes : null;
    }

    private function recommendedRequestBytes(int $fileBytes): int
    {
        return $fileBytes + $this->requestReserveBytes($fileBytes);
    }

    private function requestReserveBytes(int $bytes): int
    {
        return max(1024 * 1024, (int) ceil($bytes * 0.05));
    }

    private function megabytesDirective(int $bytes): string
    {
        return (string) max(1, (int) ceil($bytes / 1048576)) . 'M';
    }

    private function phpIniFile(): string
    {
        if (!HostingCompatibility::functionAvailable('php_ini_loaded_file')) {
            return 'не удалось определить';
        }
        $path = php_ini_loaded_file();
        return is_string($path) && $path !== '' ? $path : 'php.ini не загружен';
    }

    /** @return list<string> */
    private function phpScannedIniFiles(): array
    {
        if (!HostingCompatibility::functionAvailable('php_ini_scanned_files')) {
            return [];
        }
        $raw = php_ini_scanned_files();
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function serverType(string $software): string
    {
        $normalized = strtolower($software);
        if (str_contains($normalized, 'apache')) {
            return 'apache';
        }
        if (str_contains($normalized, 'nginx')) {
            return 'nginx';
        }
        if (str_contains($normalized, 'microsoft-iis') || str_contains($normalized, 'iis')) {
            return 'iis';
        }
        if (PHP_SAPI === 'cli-server') {
            return 'php_builtin';
        }
        return 'other';
    }

    /**
     * @return array{known:bool,bytes:?int,source:string,raw:string}
     */
    private function webServerLimit(string $serverType): array
    {
        $declared = getenv('WEB_SERVER_MAX_UPLOAD_SIZE');
        if (is_string($declared) && trim($declared) !== '') {
            $raw = trim($declared);
            $bytes = ctype_digit($raw) ? (int) $raw : HostingCompatibility::iniBytes($raw);
            if ($bytes > 0) {
                return ['known' => true, 'bytes' => $bytes, 'source' => 'WEB_SERVER_MAX_UPLOAD_SIZE', 'raw' => $raw];
            }
        }

        if ($serverType === 'apache' && defined('SITEPATH')) {
            $htaccess = rtrim((string) SITEPATH, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
            if (is_file($htaccess) && is_readable($htaccess)) {
                $source = @file_get_contents($htaccess);
                if (is_string($source) && preg_match_all('/^\s*LimitRequestBody\s+(\d+)\s*(?:#.*)?$/mi', $source, $matches) > 0) {
                    $raw = (string) end($matches[1]);
                    $bytes = (int) $raw;
                    return [
                        'known' => true,
                        'bytes' => $bytes > 0 ? $bytes : null,
                        'source' => '.htaccess: LimitRequestBody',
                        'raw' => $raw,
                    ];
                }
            }
        }

        if ($serverType === 'php_builtin') {
            return ['known' => true, 'bytes' => null, 'source' => 'встроенный PHP-сервер', 'raw' => 'без отдельного известного лимита'];
        }

        return ['known' => false, 'bytes' => null, 'source' => 'не удалось определить автоматически', 'raw' => ''];
    }
}
