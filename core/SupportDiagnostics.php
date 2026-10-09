<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/ServiceLog.php';
require_once __DIR__ . '/SupportZipWriter.php';
require_once __DIR__ . '/HostingProfileProbe.php';

use App\Services\MaintenanceModeService;
use RuntimeException;
use Throwable;

/**
 * Формирует обезличенный диагностический пакет и выдаёт одноразовый доступ.
 *
 * Пакет создаётся только по явному действию суперадминистратора. Внешний
 * получатель получает снимок, а не произвольный доступ к файловой системе.
 */
final class SupportDiagnostics
{
    private const SCHEMA = 2;
    private const DEFAULT_TTL = 900;
    private const MAX_TTL = 1800;
    private const MAX_BUNDLE_BYTES = 8388608;
    private const MAX_JOURNAL_BYTES = 524288;
    private const SENSITIVE_KEY = '/(?:^|_)(password|passphrase|secret|token|authorization|cookie|csrf|session|license|private_key)(?:$|_)/i';

    private string $appRoot;
    private string $privateRoot;
    private string $storageRoot;

    public function __construct(?string $appRoot = null)
    {
        $resolvedApp = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($resolvedApp) || !is_dir($resolvedApp)) {
            throw new RuntimeException('Не удалось определить корень приложения для диагностики');
        }
        $this->appRoot = self::normalize($resolvedApp);

        $private = getenv('PRIVATE_STORAGE_PATH');
        $private = is_string($private) ? trim($private) : '';
        if ($private === '') {
            throw new RuntimeException('PRIVATE_STORAGE_PATH не настроен');
        }
        if (!self::isAbsolute($private) || is_link($private)) {
            throw new RuntimeException('PRIVATE_STORAGE_PATH небезопасен для диагностики');
        }

        $resolvedPrivate = realpath($private);
        if (!is_string($resolvedPrivate) || !is_dir($resolvedPrivate) || !is_writable($resolvedPrivate)) {
            throw new RuntimeException('PRIVATE_STORAGE_PATH недоступен для диагностики');
        }
        $this->privateRoot = self::normalize($resolvedPrivate);

        if (self::inside($this->privateRoot, $this->appRoot)) {
            throw new RuntimeException('Диагностические пакеты должны храниться вне дерева приложения');
        }

        $this->storageRoot = $this->privateRoot . '/support-diagnostics';
        $this->ensureDirectory($this->storageRoot);
        $this->ensureDirectory($this->storageRoot . '/grants');
        $this->ensureDirectory($this->storageRoot . '/bundles');
    }

    /**
     * @return array{token:string,expires_at:int,bundle_id:string,endpoint:string}
     */
    public function createGrant(int $actorId, int $ttlSeconds = self::DEFAULT_TTL): array
    {
        if ($actorId <= 0) {
            throw new RuntimeException('Не определён пользователь, запросивший диагностику');
        }

        $ttlSeconds = max(60, min(self::MAX_TTL, $ttlSeconds));
        $this->cleanupExpired();

        $now = time();
        $expiresAt = $now + $ttlSeconds;
        $bundleId = gmdate('Ymd-His', $now) . '-' . bin2hex(random_bytes(6));
        $bundlePath = $this->storageRoot . '/bundles/' . $bundleId . '.zip';

        $bytes = $this->buildArchive($bundleId, $actorId, $now);
        if (strlen($bytes) > self::MAX_BUNDLE_BYTES) {
            throw new RuntimeException('Диагностический ZIP превышает допустимый размер');
        }

        $this->atomicWrite($bundlePath, $bytes);

        $token = self::base64UrlEncode(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $grantPath = $this->storageRoot . '/grants/' . $tokenHash . '.json';

        $grant = [
            'schema' => self::SCHEMA,
            'token_sha256' => $tokenHash,
            'bundle_id' => $bundleId,
            'bundle_sha256' => hash('sha256', $bytes),
            'bundle_format' => 'zip',
            'bundle_schema' => self::SCHEMA,
            'created_at' => $now,
            'expires_at' => $expiresAt,
            'created_by' => $actorId,
            'one_time' => true,
        ];

        $grantBytes = json_encode(
            $grant,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        $this->atomicWrite($grantPath, $grantBytes);

        ServiceLog::emit(
            'support.diagnostics_grant_created',
            'info',
            'support',
            [
                'bundle_id' => $bundleId,
                'expires_at' => $expiresAt,
                'created_by' => $actorId,
            ]
        );

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
            'bundle_id' => $bundleId,
            'endpoint' => 'support-diagnostics',
            'transport' => 'https-get',
            'authorization' => 'bearer-or-query',
        ];
    }

    /**
     * Формирует тот же обезличенный ZIP для защищённой отправки в control plane.
     *
     * @return array{bundle_id:string,bytes:string,sha256:string,size:int}
     */
    public function createUploadBundle(int $actorId): array
    {
        if ($actorId <= 0) {
            throw new RuntimeException('Не определён пользователь, запросивший диагностику');
        }

        $now = time();
        $bundleId = gmdate('Ymd-His', $now) . '-' . bin2hex(random_bytes(6));
        $bytes = $this->buildArchive($bundleId, $actorId, $now);
        if (strlen($bytes) > self::MAX_BUNDLE_BYTES) {
            throw new RuntimeException('Диагностический ZIP превышает допустимый размер');
        }

        ServiceLog::emit(
            'support.diagnostics_upload_bundle_created',
            'info',
            'support',
            [
                'bundle_id' => $bundleId,
                'created_by' => $actorId,
                'size' => strlen($bytes),
            ]
        );

        return [
            'bundle_id' => $bundleId,
            'bytes' => $bytes,
            'sha256' => hash('sha256', $bytes),
            'size' => strlen($bytes),
        ];
    }

    public static function canHandleRequest(): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return false;
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path)
            && preg_match('~(?:^|/)support-diagnostics/?$~D', $path) === 1;
    }

    public static function handleRequest(string $appRoot): never
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Content-Type-Options: nosniff');

        $token = self::requestToken();
        if ($token === null) {
            self::deny();
        }

        try {
            $service = new self($appRoot);
            $service->serveToken($token);
        } catch (Throwable $e) {
            ServiceLog::emit(
                'support.diagnostics_download_failed',
                'warning',
                'support',
                ['error_type' => $e::class]
            );
            self::deny();
        }
    }

    private static function requestToken(): ?string
    {
        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($authorization !== ''
            && preg_match('/^Bearer\\s+([A-Za-z0-9_-]{40,80})$/iD', $authorization, $match) === 1) {
            return (string) $match[1];
        }

        $queryToken = trim((string) ($_GET['token'] ?? ''));
        return preg_match('/^[A-Za-z0-9_-]{40,80}$/D', $queryToken) === 1
            ? $queryToken
            : null;
    }

    private function serveToken(string $token): never
    {
        $tokenHash = hash('sha256', $token);
        $grantPath = $this->storageRoot . '/grants/' . $tokenHash . '.json';
        $grant = $this->readJsonFile($grantPath, 32768);

        if (!hash_equals($tokenHash, (string) ($grant['token_sha256'] ?? ''))) {
            self::deny();
        }

        $expiresAt = (int) ($grant['expires_at'] ?? 0);
        if ($expiresAt < time() || $expiresAt > time() + self::MAX_TTL + 60) {
            @unlink($grantPath);
            self::deny();
        }

        $bundleId = (string) ($grant['bundle_id'] ?? '');
        if (preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/D', $bundleId) !== 1) {
            self::deny();
        }

        $bundlePath = $this->storageRoot . '/bundles/' . $bundleId . '.zip';
        if (!is_file($bundlePath) || is_link($bundlePath)) {
            self::deny();
        }

        $size = filesize($bundlePath);
        if (!is_int($size) || $size <= 0 || $size > self::MAX_BUNDLE_BYTES) {
            self::deny();
        }

        $bytes = file_get_contents($bundlePath);
        if (!is_string($bytes) || strlen($bytes) !== $size) {
            self::deny();
        }

        $expectedSha = strtolower((string) ($grant['bundle_sha256'] ?? ''));
        if (preg_match('/^[0-9a-f]{64}$/D', $expectedSha) !== 1
            || !hash_equals($expectedSha, hash('sha256', $bytes))) {
            self::deny();
        }

        // Токен одноразовый: сначала отзываем разрешение, потом отдаём уже
        // загруженный в память снимок. Повторный запрос не получит пакет.
        if (!@unlink($grantPath)) {
            throw new RuntimeException('Не удалось погасить одноразовый доступ');
        }
        @unlink($bundlePath);

        ServiceLog::emit(
            'support.diagnostics_downloaded',
            'info',
            'support',
            ['bundle_id' => $bundleId]
        );

        header('Content-Type: application/zip');
        header(
            'Content-Disposition: attachment; filename="notes-diagnostics-' . $bundleId . '.zip"'
        );
        header('X-Notes-Diagnostics-Schema: ' . self::SCHEMA);
        header('X-Notes-Diagnostics-Bundle-Id: ' . $bundleId);
        header('X-Notes-Diagnostics-SHA256: ' . hash('sha256', $bytes));
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    private function buildArchive(string $bundleId, int $actorId, int $now): string
    {
        $bundle = $this->buildBundle($bundleId, $actorId, $now);

        $files = [
            'hosting-profile.json' => $this->jsonBytes($bundle['hosting_profile'] ?? []),
            'health.json' => $this->jsonBytes($bundle['health'] ?? []),
            'maintenance.json' => $this->jsonBytes($bundle['maintenance'] ?? []),
            'service-events.json' => $this->jsonBytes($bundle['service_events'] ?? []),
            'updater-transactions.json' => $this->jsonBytes($bundle['updater_transactions'] ?? []),
            'security-summary.json' => $this->jsonBytes($bundle['security_summary_24h'] ?? []),
            'privacy.json' => $this->jsonBytes($bundle['privacy'] ?? []),
        ];

        $manifestFiles = [];
        foreach ($files as $name => $bytes) {
            $manifestFiles[$name] = [
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ];
        }

        $manifest = [
            'schema' => self::SCHEMA,
            'product' => 'workspace-organizer',
            'bundle_id' => $bundleId,
            'generated_at' => $now,
            'generated_at_iso' => gmdate('c', $now),
            'application' => $bundle['application'] ?? [],
            'format' => 'zip-store',
            'files' => $manifestFiles,
            'retrieval' => [
                'one_time' => true,
                'user_consent_required' => true,
                'remote_shell_access' => false,
                'live_log_access' => false,
            ],
        ];

        $writer = new SupportZipWriter();
        $writer->add('manifest.json', $this->jsonBytes($manifest));
        $writer->add(
            'README.txt',
            "Пакет диагностики Notes.\n"
            . "Создан по явному действию администратора.\n"
            . "Пользовательские данные, .env, пароли, лицензия и ключи не включаются.\n"
            . "Файлы JSON предназначены для поддержки и автоматического разбора.\n"
        );

        foreach ($files as $name => $bytes) {
            $writer->add($name, $bytes);
        }

        return $writer->finish();
    }

    private function jsonBytes(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    }

    /** @return array<string,mixed> */
    private function buildBundle(string $bundleId, int $actorId, int $now): array
    {
        require_once $this->appRoot . '/core/Version.php';
        require_once $this->appRoot . '/core/ServiceLog.php';
        require_once $this->appRoot . '/core/SecurityEventLog.php';
        require_once $this->appRoot . '/core/UpdateWebHealthProbe.php';
        require_once $this->appRoot . '/app/services/MaintenanceModeService.php';

        $health = [];
        try {
            $health = (new UpdateWebHealthProbe($this->appRoot))->inspect();
        } catch (Throwable $e) {
            $health = [
                'status' => 'unavailable',
                'error_type' => $e::class,
            ];
        }

        $maintenance = [];
        try {
            $maintenance = (new MaintenanceModeService(null, $this->appRoot))->state();
        } catch (Throwable $e) {
            $maintenance = [
                'active' => null,
                'valid' => null,
                'error_type' => $e::class,
            ];
        }

        $security = [];
        try {
            $security = (new SecurityEventLog(null, $this->appRoot))->summarize(86400);
        } catch (Throwable $e) {
            $security = [
                'status' => 'unavailable',
                'error_type' => $e::class,
            ];
        }

        $serviceEvents = [];
        try {
            $serviceEvents = (new ServiceLog(null, $this->appRoot))->tail(500);
        } catch (Throwable $e) {
            $serviceEvents = [[
                'event' => 'support.service_log_unavailable',
                'level' => 'warning',
                'component' => 'support',
                'context' => ['error_type' => $e::class],
            ]];
        }

        $disabledFunctions = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ini_get('disable_functions'))
        )));
        sort($disabledFunctions, SORT_STRING);

        $payload = [
            'schema' => self::SCHEMA,
            'bundle_id' => $bundleId,
            'generated_at' => $now,
            'generated_at_iso' => gmdate('c', $now),
            'generated_by_user_id' => $actorId,
            'application' => [
                'version' => Version::VERSION,
                'version_code' => Version::VERSION_CODE,
                'status' => Version::STATUS,
            ],
            'runtime' => [
                'php_version' => PHP_VERSION,
                'php_sapi' => PHP_SAPI,
                'os_family' => PHP_OS_FAMILY,
                'disabled_functions' => $disabledFunctions,
                'extensions' => [
                    'mysqli' => extension_loaded('mysqli'),
                    'pdo_mysql' => extension_loaded('pdo_mysql'),
                    'mbstring' => extension_loaded('mbstring'),
                    'sodium' => extension_loaded('sodium'),
                    'openssl' => extension_loaded('openssl'),
                    'zip' => extension_loaded('zip'),
                ],
            ],
            'hosting_profile' => (new HostingProfileProbe())->inspect($this->storageRoot),
            'maintenance' => $maintenance,
            'health' => $health,
            'security_summary_24h' => $security,
            'service_events' => $serviceEvents,
            'updater_transactions' => $this->recentUpdaterJournals(6),
            'privacy' => [
                'user_content_included' => false,
                'environment_file_included' => false,
                'database_dump_included' => false,
                'secrets_redacted' => true,
            ],
        ];

        return $this->sanitize($payload, 0);
    }

    /** @return list<array<string,mixed>> */
    private function recentUpdaterJournals(int $limit): array
    {
        $stateRoot = getenv('UPDATE_STATE_PATH');
        $stateRoot = is_string($stateRoot) ? trim($stateRoot) : '';
        if ($stateRoot === '') {
            $stateRoot = $this->privateRoot . '/updates';
        }

        $transactions = rtrim(self::normalize($stateRoot), '/') . '/transactions';
        if (!is_dir($transactions) || is_link($transactions)) {
            return [];
        }

        $files = glob($transactions . '/*.json');
        if (!is_array($files) || $files === []) {
            return [];
        }

        usort(
            $files,
            static fn (string $a, string $b): int => ((int) @filemtime($b)) <=> ((int) @filemtime($a))
        );

        $result = [];
        foreach (array_slice($files, 0, max(1, min(20, $limit))) as $path) {
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            $size = filesize($path);
            if (!is_int($size) || $size <= 0 || $size > self::MAX_JOURNAL_BYTES) {
                continue;
            }
            try {
                $data = $this->readJsonFile($path, self::MAX_JOURNAL_BYTES);
            } catch (Throwable) {
                continue;
            }
            $result[] = $this->sanitize($data, 0);
        }

        return $result;
    }

    private function cleanupExpired(): void
    {
        $now = time();
        $grantFiles = glob($this->storageRoot . '/grants/*.json');
        if (is_array($grantFiles)) {
            foreach ($grantFiles as $grantPath) {
                try {
                    $grant = $this->readJsonFile($grantPath, 32768);
                } catch (Throwable) {
                    @unlink($grantPath);
                    continue;
                }

                if ((int) ($grant['expires_at'] ?? 0) >= $now) {
                    continue;
                }

                $bundleId = (string) ($grant['bundle_id'] ?? '');
                if (preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/D', $bundleId) === 1) {
                    @unlink($this->storageRoot . '/bundles/' . $bundleId . '.zip');
                }
                @unlink($grantPath);
            }
        }

        $bundleFiles = glob($this->storageRoot . '/bundles/*.zip');
        if (is_array($bundleFiles)) {
            foreach ($bundleFiles as $bundlePath) {
                $mtime = @filemtime($bundlePath);
                if (is_int($mtime) && $mtime < $now - self::MAX_TTL - 3600) {
                    @unlink($bundlePath);
                }
            }
        }
    }

    /** @return array<string,mixed> */
    private function readJsonFile(string $path, int $maxBytes): array
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('Диагностический файл отсутствует или небезопасен');
        }

        $size = filesize($path);
        if (!is_int($size) || $size <= 0 || $size > $maxBytes) {
            throw new RuntimeException('Некорректный размер диагностического файла');
        }

        $bytes = file_get_contents($path);
        if (!is_string($bytes) || strlen($bytes) !== $size) {
            throw new RuntimeException('Не удалось полностью прочитать диагностический файл');
        }

        $decoded = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException('Некорректный формат диагностического файла');
        }

        return $decoded;
    }

    private function atomicWrite(string $path, string $bytes): void
    {
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Диагностический файл уже существует');
        }

        $tmp = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $oldUmask = umask(0077);
        $handle = @fopen($tmp, 'xb');
        umask($oldUmask);
        if ($handle === false) {
            throw new RuntimeException('Не удалось создать временный диагностический файл');
        }

        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new RuntimeException('Не удалось полностью записать диагностический файл');
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($tmp);
            throw $e;
        }
        fclose($handle);
        @chmod($tmp, 0600);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Не удалось атомарно опубликовать диагностический файл');
        }
        @chmod($path, 0600);
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path)) {
            $oldUmask = umask(0077);
            $created = @mkdir($path, 0700, true);
            umask($oldUmask);
            if (!$created && !is_dir($path)) {
                throw new RuntimeException('Не удалось создать каталог диагностики');
            }
        }

        if (!is_dir($path) || is_link($path) || !is_writable($path)) {
            throw new RuntimeException('Каталог диагностики небезопасен или недоступен');
        }
        @chmod($path, 0700);
    }

    private function sanitize(mixed $value, int $depth): mixed
    {
        if ($depth > 8) {
            return '[depth-limit]';
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $safe = $this->redactKnownRoots($value);

            $safe = preg_replace(
                "/(command denied to user)\\s+'[^']+'@'[^']+'/i",
                "$1 '[redacted]'@'[redacted]'",
                $safe
            ) ?? $safe;
            $safe = preg_replace(
                '/([?&](?:token|secret|key|authorization|session|csrf)=)[^&\\s]+/i',
                '$1[redacted]',
                $safe
            ) ?? $safe;

            foreach ([
                'DBPASS',
                'UNIQUE_KEY',
                'MSG_SECRET_KEY',
                'WS_TICKET_SECRET',
                'LICENSE_TOKEN',
                'WORKSPACE_LICENSE_TOKEN',
            ] as $envName) {
                $secret = getenv($envName);
                if (is_string($secret) && trim($secret) !== '') {
                    $safe = str_replace($secret, '[redacted]', $safe);
                }
            }

            return mb_substr($safe, 0, 2048);
        }

        if (!is_array($value)) {
            return '[' . get_debug_type($value) . ']';
        }

        $safe = [];
        $count = 0;
        foreach ($value as $key => $child) {
            if ($count++ >= 200) {
                break;
            }

            $name = (string) $key;
            if (preg_match(self::SENSITIVE_KEY, $name) === 1) {
                $safe[$key] = '[redacted]';
                continue;
            }
            $safe[$key] = $this->sanitize($child, $depth + 1);
        }

        return $safe;
    }

    private static function deny(): never
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Диагностический пакет недоступен.\n";
        exit;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function redactKnownRoots(string $value): string
    {
        $safe = self::normalize($value);

        foreach ([
            [$this->appRoot, '[app-root]'],
            [$this->privateRoot, '[private-storage]'],
        ] as [$root, $replacement]) {
            $normalizedRoot = self::normalize((string) $root);
            $modifier = preg_match('/^[A-Za-z]:\//D', $normalizedRoot) === 1 ? 'i' : '';
            $pattern = '~' . preg_quote($normalizedRoot, '~') . '~' . $modifier;
            $safe = preg_replace($pattern, (string) $replacement, $safe) ?? $safe;
        }

        return $safe;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/D', $path) === 1;
    }

    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function inside(string $path, string $parent): bool
    {
        $path = self::normalize($path);
        $parent = self::normalize($parent);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }
}
