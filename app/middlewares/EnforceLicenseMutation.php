<?php

declare(strict_types=1);

namespace App\Middlewares;

use App\Services\LicenseRuntimePolicy;
use Core\Request;

/**
 * Global HTTP mutation guard for licensed builds.
 *
 * Reads always remain available. Authentication, logout, license recovery and
 * Messenger socket-ticket refresh are non-durable recovery/session operations
 * and remain available in read-only mode. Every other unsafe HTTP method is
 * denied while the installation license is not valid.
 */
final class EnforceLicenseMutation
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /** @var list<string> */
    private const RECOVERY_MUTATIONS = [
        '/auth/login',
        '/auth/logout',
        '/system/license/activate',
        '/system/license/clear',
        // Backward-compatible Admin UI mutations while the Admin module is active.
        '/admin/license/activate',
        '/admin/license/clear',
        '/messenger/socket-ticket',
        // Generic fallback transport must also carry read-only Messenger actions.
        // MessengerActionDispatcher still enforces license/maintenance for every
        // mutating action, so this is not a mutation-policy bypass.
        '/messenger/transport/send',
    ];

    public function __construct(private ?LicenseRuntimePolicy $policy = null)
    {
        $this->policy ??= new LicenseRuntimePolicy();
    }

    public function handle(Request $request): bool
    {
        $method = strtoupper((string) $request->server('REQUEST_METHOD', 'GET'));
        if (in_array($method, self::SAFE_METHODS, true)) {
            return true;
        }

        $path = $this->applicationPath((string) $request->server('REQUEST_URI', '/'));
        if (in_array($path, self::RECOVERY_MUTATIONS, true)) {
            return true;
        }

        $state = $this->policy->state();
        if (!$state['enforced'] || $state['writable']) {
            return true;
        }

        $this->reject($request, $state);
        return false;
    }

    private function applicationPath(string $requestUri): string
    {
        $path = parse_url($requestUri, PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? '/' . trim($path, '/') : '/';

        $base = '/' . trim((string) (getenv('BASE_PATH') ?: '/'), '/');
        if ($base === '/') {
            return $path;
        }

        if ($path === $base) {
            return '/';
        }
        if (str_starts_with($path, $base . '/')) {
            $relative = substr($path, strlen($base));
            return $relative === '' ? '/' : $relative;
        }

        return $path;
    }

    /** @param array{enforced:bool,writable:bool,code:string,message:string} $state */
    private function reject(Request $request, array $state): void
    {
        http_response_code(403);
        header('Cache-Control: no-store');

        $message = $state['message'] !== ''
            ? $state['message']
            : 'Лицензия установки не подтверждена. Изменение данных заблокировано.';
        $licenseUrl = $this->localUrl('/system/license');

        if ($this->expectsJson($request)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'license_read_only',
                'code' => $state['code'],
                'message' => $message,
                'license_url' => $licenseUrl,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }

        header('Content-Type: text/html; charset=utf-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $safeLicenseUrl = htmlspecialchars($licenseUrl, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Режим только для чтения</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:720px;margin:8vh auto;padding:24px">'
            . '<h1>Режим только для чтения</h1><p>' . $safeMessage . '</p>'
            . '<p>Существующие данные доступны для просмотра и не изменяются.</p>'
            . '<p><a href="' . $safeLicenseUrl . '">Проверить лицензию</a></p></body></html>';
    }

    private function expectsJson(Request $request): bool
    {
        $accept = strtolower((string) $request->server('HTTP_ACCEPT', ''));
        $contentType = strtolower((string) $request->server('CONTENT_TYPE', ''));
        $requestedWith = strtolower((string) $request->server('HTTP_X_REQUESTED_WITH', ''));

        return str_contains($accept, 'application/json')
            || str_contains($contentType, 'application/json')
            || $requestedWith === 'xmlhttprequest';
    }

    private function localUrl(string $path): string
    {
        $base = trim((string) (getenv('BASE_PATH') ?: '/'), '/');
        $prefix = $base === '' ? '' : '/' . $base;
        return $prefix . '/' . ltrim($path, '/');
    }
}
