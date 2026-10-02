<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/RequestOrigin.php';
require_once __DIR__ . '/SecurityEventLog.php';
require_once dirname(__DIR__) . '/app/services/RequestRateLimiter.php';

use App\Services\RequestRateLimiter;
use Throwable;

/**
 * Ранний защитный контур от внешних сканеров.
 *
 * Он не пытается "наказать" клиента задержками: искусственный sleep сам по себе
 * превращается в удобный способ занять PHP workers. Вместо этого известные
 * scanner-probes получают короткий 404, а повторяющиеся запросы — 429.
 */
final class CrawlerDefense
{
    public const TRAP_PATH = '/.well-known/workspace-crawl-trap';

    private const PROBE_LIMIT = 6;
    private const TRAP_LIMIT = 1;
    private const WINDOW_SECONDS = 600;

    public static function handleEarlyRequest(): void
    {
        $rawUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($rawUri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return;
        }

        $classification = self::classifyPath($path);
        if ($classification === null) {
            return;
        }

        $clientIp = RequestOrigin::clientIp($_SERVER);
        $actorId = self::clientFingerprint($clientIp);
        $isTrap = $classification === 'crawler_trap';

        SecurityEventLog::emit(
            $isTrap ? 'crawler.trap_hit' : 'crawler.probe_detected',
            'warning',
            'crawler-defense',
            'network',
            $actorId,
            [
                'classification' => $classification,
                'path' => self::safePath($path),
                'method' => strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            ]
        );

        $retryAfter = self::WINDOW_SECONDS;
        $allowed = true;
        try {
            $result = RequestRateLimiter::consume(
                $isTrap ? 'crawler-trap' : 'crawler-probe',
                $clientIp,
                $isTrap ? self::TRAP_LIMIT : self::PROBE_LIMIT,
                self::WINDOW_SECONDS
            );
            $allowed = (bool) ($result['allowed'] ?? false);
            $retryAfter = max(1, (int) ($result['retry_after'] ?? self::WINDOW_SECONDS));
        } catch (Throwable $e) {
            SecurityEventLog::emit(
                'crawler.rate_limiter_failed',
                'warning',
                'crawler-defense',
                'system',
                null,
                ['error_type' => $e::class]
            );
        }

        if (!$allowed) {
            SecurityEventLog::emit(
                'crawler.rate_limited',
                'warning',
                'crawler-defense',
                'network',
                $actorId,
                [
                    'classification' => $classification,
                    'retry_after' => $retryAfter,
                ]
            );
            self::deny(429, $retryAfter);
        }

        self::deny(404);
    }

    public static function classifyPath(string $path): ?string
    {
        $normalized = self::normalizedRequestPath($path);
        if ($normalized === '') {
            return null;
        }

        if (
            $normalized === self::TRAP_PATH
            || str_ends_with($normalized, self::TRAP_PATH)
        ) {
            return 'crawler_trap';
        }

        $patterns = [
            'secret_file' => '~(?:^|/)(?:\.env(?:\.[^/]*)?|\.git(?:/|$)|\.svn(?:/|$)|\.hg(?:/|$)|\.aws(?:/|$)|\.ssh(?:/|$)|id_rsa(?:\.pub)?$)~i',
            'cms_probe' => '~(?:^|/)(?:wp-admin|wp-login\.php|xmlrpc\.php|wp-content|wp-includes)(?:/|$)~i',
            'db_admin_probe' => '~(?:^|/)(?:phpmyadmin|pma|adminer(?:\.php)?)(?:/|$)~i',
            'runtime_probe' => '~(?:^|/)(?:vendor/phpunit|phpunit|server-status|server-info|actuator|cgi-bin)(?:/|$)~i',
            'metadata_probe' => '~(?:^|/)(?:composer\.(?:json|lock)|package(?:-lock)?\.json|database\.sql|dump\.sql|backup(?:\.zip|\.tar|\.gz)?)(?:$|/)~i',
        ];

        foreach ($patterns as $classification => $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return $classification;
            }
        }

        return null;
    }

    private static function normalizedRequestPath(string $path): string
    {
        $decoded = rawurldecode($path);
        $decoded = str_replace('\\', '/', $decoded);
        $decoded = preg_replace('~/+~', '/', $decoded) ?? $decoded;
        return '/' . ltrim($decoded, '/');
    }

    private static function safePath(string $path): string
    {
        $safe = self::normalizedRequestPath($path);
        return mb_substr($safe, 0, 256);
    }

    private static function clientFingerprint(string $clientIp): string
    {
        $secret = trim((string) (getenv('UNIQUE_KEY') ?: ''));
        $hash = $secret !== ''
            ? hash_hmac('sha256', $clientIp, $secret)
            : hash('sha256', $clientIp);

        return substr($hash, 0, 24);
    }

    private static function deny(int $status, int $retryAfter = 0): never
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        if ($status === 429) {
            header('Retry-After: ' . max(1, $retryAfter));
            echo '429 Too Many Requests';
            exit;
        }

        echo '404 Page Not Found';
        exit;
    }
}
