<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/** Installation credential, scoped to one HTTPS delivery directory. Never used by runtime licensing. */
final class UpdateDownloadCredentials
{
    public function __construct(private array $data)
    {
        if (($data['schema'] ?? null) !== 1
            || !is_string($data['installation_id'] ?? null)
            || preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $data['installation_id']) !== 1
            || !is_string($data['token'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $data['token']) !== 1
            || !is_string($data['base_url'] ?? null)) {
            throw new RuntimeException('Invalid online update credentials');
        }
        self::validateBaseUrl($data['base_url']);
    }

    public static function validateBaseUrl(string $url): void
    {
        // A canonical directory URL avoids ambiguous credential scopes and encoded traversal.
        if (preg_match('~^https://[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?:/[A-Za-z0-9_-]+)*/$~D', $url) !== 1
            || !str_contains((string) parse_url($url, PHP_URL_HOST), '.')) {
            throw new RuntimeException('Online update base URL must be a canonical HTTPS directory URL');
        }
    }

    public static function fromEnvironment(): ?self
    {
        $mode = trim((string) getenv('UPDATE_ACCESS_MODE'));
        if ($mode === '' || $mode === 'offline') {
            return null;
        }
        if ($mode !== 'online') {
            throw new RuntimeException('UPDATE_ACCESS_MODE must be online or offline');
        }
        $path = trim((string) getenv('UPDATE_CREDENTIALS_FILE'));
        self::assertExternalPath($path);
        if (!is_file($path) || !is_readable($path) || filesize($path) > 4096) {
            throw new RuntimeException('Online update credentials are missing or unreadable; activate update access first');
        }
        if (DIRECTORY_SEPARATOR === '/' && (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('Online update credentials must have mode 0600');
        }
        $data = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid online update credentials file');
        }
        return new self($data);
    }

    public static function assertExternalPath(string $path): void
    {
        $parent = realpath(dirname($path));
        $root = str_replace('\\', '/', (string) realpath(dirname(__DIR__)));
        $resolved = is_string($parent) ? str_replace('\\', '/', $parent) . '/' . basename($path) : '';
        if ($path === '' || (!str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1)
            || $resolved === '' || is_link($path)
            || str_starts_with(strtolower($resolved), strtolower($root) . '/')) {
            throw new RuntimeException('Update credentials must use an absolute path outside the application tree');
        }
    }

    public function installationId(): string
    {
        return $this->data['installation_id'];
    }

    public function headersFor(string $url): string
    {
        $base = $this->data['base_url'];
        if (!str_starts_with($url, $base)) {
            throw new RuntimeException('Refusing to send update credentials outside the activated server scope');
        }
        $relative = substr($url, strlen($base));
        if (preg_match('~^(?:alpha|beta|stable)/[A-Za-z0-9][A-Za-z0-9._+-]*$~D', $relative) !== 1) {
            throw new RuntimeException('Online update request is outside the activated delivery paths');
        }
        return 'Authorization: Bearer ' . $this->data['token'] . "\r\n"
            . 'X-Notes-Installation: ' . $this->data['installation_id'] . "\r\n";
    }
}
