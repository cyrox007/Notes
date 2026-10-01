<?php

declare(strict_types=1);

namespace Core;

use mysqli;
use Throwable;

/**
 * Безопасный профиль окружения для диагностики виртуального хостинга.
 *
 * Не использует phpinfo() целиком и не возвращает пути, логины, DSN,
 * переменные окружения или другие значения, способные раскрыть секреты.
 */
final class HostingProfileProbe
{
    /** @return array<string,mixed> */
    public function inspect(string $probeRoot): array
    {
        return [
            'schema' => 1,
            'php' => $this->phpProfile(),
            'filesystem' => $this->filesystemProfile($probeRoot),
            'database' => $this->databaseProfile(),
            'updater' => $this->updaterProfile(),
        ];
    }

    /** @return array<string,mixed> */
    private function phpProfile(): array
    {
        $disabled = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ini_get('disable_functions'))
        )));
        sort($disabled, SORT_STRING);

        $openBasedir = trim((string) ini_get('open_basedir'));
        $uploadTmp = trim((string) ini_get('upload_tmp_dir'));
        $systemTmp = sys_get_temp_dir();

        return [
            'version' => PHP_VERSION,
            'version_id' => PHP_VERSION_ID,
            'sapi' => PHP_SAPI,
            'os_family' => PHP_OS_FAMILY,
            'memory_limit' => (string) ini_get('memory_limit'),
            'max_execution_time' => (int) ini_get('max_execution_time'),
            'max_input_time' => (int) ini_get('max_input_time'),
            'post_max_size' => (string) ini_get('post_max_size'),
            'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
            'max_file_uploads' => (int) ini_get('max_file_uploads'),
            'default_socket_timeout' => (int) ini_get('default_socket_timeout'),
            'realpath_cache_size' => (string) ini_get('realpath_cache_size'),
            'realpath_cache_ttl' => (int) ini_get('realpath_cache_ttl'),
            'open_basedir_enabled' => $openBasedir !== '',
            'open_basedir_entries' => $openBasedir === ''
                ? 0
                : count(array_filter(explode(PATH_SEPARATOR, $openBasedir))),
            'upload_tmp_dir_configured' => $uploadTmp !== '',
            'upload_tmp_dir_writable' => $uploadTmp !== ''
                ? is_dir($uploadTmp) && is_writable($uploadTmp)
                : null,
            'system_tmp_writable' => is_dir($systemTmp) && is_writable($systemTmp),
            'disabled_functions' => $disabled,
            'process_api' => [
                'proc_open' => function_exists('proc_open'),
                'proc_get_status' => function_exists('proc_get_status'),
                'proc_terminate' => function_exists('proc_terminate'),
                'proc_close' => function_exists('proc_close'),
                'exec' => function_exists('exec'),
                'system' => function_exists('system'),
                'passthru' => function_exists('passthru'),
                'shell_exec' => function_exists('shell_exec'),
                'popen' => function_exists('popen'),
            ],
            'opcache' => [
                'extension_loaded' => extension_loaded('Zend OPcache'),
                'enabled' => $this->iniBool('opcache.enable'),
                'enable_cli' => $this->iniBool('opcache.enable_cli'),
                'validate_timestamps' => $this->iniBool('opcache.validate_timestamps'),
                'revalidate_freq' => (int) ini_get('opcache.revalidate_freq'),
                'invalidate_available' => function_exists('opcache_invalidate'),
                'reset_available' => function_exists('opcache_reset'),
            ],
            'extensions' => [
                'mysqli' => extension_loaded('mysqli'),
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'mbstring' => extension_loaded('mbstring'),
                'sodium' => extension_loaded('sodium'),
                'openssl' => extension_loaded('openssl'),
                'fileinfo' => extension_loaded('fileinfo'),
                'zlib' => extension_loaded('zlib'),
                'gd' => extension_loaded('gd'),
                'zip' => extension_loaded('zip'),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function filesystemProfile(string $probeRoot): array
    {
        $result = [
            'private_storage_writable' => is_dir($probeRoot) && is_writable($probeRoot),
            'create_file' => false,
            'exclusive_lock' => false,
            'atomic_rename' => false,
            'replace_file' => false,
            'delete_file' => false,
        ];

        if (!$result['private_storage_writable']) {
            return $result;
        }

        $id = bin2hex(random_bytes(8));
        $source = rtrim($probeRoot, '/\\') . DIRECTORY_SEPARATOR . '.hosting-probe-' . $id;
        $target = $source . '-renamed';
        $replacement = $source . '-replacement';

        try {
            $handle = @fopen($source, 'xb');
            if ($handle === false) {
                return $result;
            }

            try {
                $written = fwrite($handle, 'notes-hosting-probe');
                $result['create_file'] = $written === strlen('notes-hosting-probe');
                $result['exclusive_lock'] = @flock($handle, LOCK_EX | LOCK_NB);
                if ($result['exclusive_lock']) {
                    @flock($handle, LOCK_UN);
                }
                @fflush($handle);
            } finally {
                fclose($handle);
            }

            $result['atomic_rename'] = @rename($source, $target);

            if ($result['atomic_rename']) {
                $replacementHandle = @fopen($replacement, 'xb');
                if ($replacementHandle !== false) {
                    fwrite($replacementHandle, 'replacement');
                    fflush($replacementHandle);
                    fclose($replacementHandle);

                    // POSIX разрешает rename поверх файла. На Windows это может
                    // быть запрещено, и updater использует file-level quarantine.
                    $result['replace_file'] = @rename($replacement, $target);
                }
            }
        } catch (Throwable) {
            // Профиль диагностики не должен ломать приложение.
        } finally {
            foreach ([$source, $target, $replacement] as $path) {
                if (is_file($path) && !is_link($path)) {
                    @unlink($path);
                }
            }
            $result['delete_file'] = !file_exists($source)
                && !file_exists($target)
                && !file_exists($replacement);
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function databaseProfile(): array
    {
        $profile = [
            'available' => false,
            'driver' => 'mysql',
            'server_version' => null,
            'grant_inspection' => 'unavailable',
            'privileges' => $this->emptyPrivilegeMap(),
        ];

        if (!extension_loaded('mysqli')) {
            return $profile;
        }

        $user = getenv('DBUSER');
        $database = getenv('DBNAME');
        if (!is_string($user) || trim($user) === ''
            || !is_string($database) || trim($database) === '') {
            return $profile;
        }

        mysqli_report(MYSQLI_REPORT_OFF);

        try {
            $db = @new mysqli(
                (string) (getenv('DBHOST') ?: 'localhost'),
                trim($user),
                (string) (getenv('DBPASS') ?: ''),
                trim($database),
                (int) (getenv('DBPORT') ?: 3306)
            );

            if ($db->connect_errno !== 0) {
                return $profile;
            }

            try {
                $profile['available'] = true;
                $profile['server_version'] = $this->safeServerVersion((string) $db->server_info);
                $profile['privileges'] = $this->inspectPrivileges($db);
                $profile['grant_inspection'] = 'ok';
            } finally {
                $db->close();
            }
        } catch (Throwable) {
            return $profile;
        }

        return $profile;
    }

    /** @return array<string,bool|null> */
    private function inspectPrivileges(mysqli $db): array
    {
        $map = $this->emptyPrivilegeMap();
        $result = @$db->query('SHOW GRANTS FOR CURRENT_USER()');
        if ($result === false) {
            return $map;
        }

        $grants = [];
        while ($row = $result->fetch_row()) {
            if (isset($row[0]) && is_string($row[0])) {
                $grants[] = strtoupper($row[0]);
            }
        }
        $result->free();

        $known = [
            'SELECT',
            'INSERT',
            'UPDATE',
            'DELETE',
            'CREATE',
            'ALTER',
            'DROP',
            'INDEX',
            'REFERENCES',
            'TRIGGER',
            'CREATE VIEW',
            'SHOW VIEW',
            'LOCK TABLES',
            'CREATE ROUTINE',
            'ALTER ROUTINE',
            'EXECUTE',
        ];

        $all = false;
        foreach ($grants as $grant) {
            if (str_contains($grant, 'ALL PRIVILEGES')) {
                $all = true;
                break;
            }
        }

        foreach ($known as $privilege) {
            $key = strtolower(str_replace(' ', '_', $privilege));
            if ($all) {
                $map[$key] = true;
                continue;
            }

            foreach ($grants as $grant) {
                if (preg_match(
                    '/(?:^|[ ,])' . preg_quote($privilege, '/') . '(?:[ ,]|$)/',
                    $grant
                ) === 1) {
                    $map[$key] = true;
                    break;
                }
            }

            if ($map[$key] === null) {
                $map[$key] = false;
            }
        }

        return $map;
    }

    /** @return array<string,bool|null> */
    private function emptyPrivilegeMap(): array
    {
        return [
            'select' => null,
            'insert' => null,
            'update' => null,
            'delete' => null,
            'create' => null,
            'alter' => null,
            'drop' => null,
            'index' => null,
            'references' => null,
            'trigger' => null,
            'create_view' => null,
            'show_view' => null,
            'lock_tables' => null,
            'create_routine' => null,
            'alter_routine' => null,
            'execute' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function updaterProfile(): array
    {
        $processFunctions = [
            'proc_open',
            'proc_get_status',
            'proc_terminate',
            'proc_close',
        ];

        $processAvailable = true;
        foreach ($processFunctions as $function) {
            if (!function_exists($function)) {
                $processAvailable = false;
                break;
            }
        }

        return [
            'recommended_mode' => $processAvailable ? 'process' : 'web-only',
            'process_api_available' => $processAvailable,
            'php_cli_required' => false,
            'support_zip_requires_zip_extension' => false,
        ];
    }

    private function iniBool(string $name): ?bool
    {
        $value = ini_get($name);
        if ($value === false) {
            return null;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return false;
        }

        return !in_array($normalized, ['0', 'off', 'false', 'no'], true);
    }

    private function safeServerVersion(string $version): ?string
    {
        $version = trim($version);
        if ($version === '') {
            return null;
        }

        return mb_substr($version, 0, 120);
    }
}
