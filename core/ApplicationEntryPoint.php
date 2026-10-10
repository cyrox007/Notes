<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/SecurityHeaders.php';
require_once __DIR__ . '/CrawlerDefense.php';
require_once __DIR__ . '/ModuleManifest.php';
require_once __DIR__ . '/DatabaseOwnership.php';
require_once __DIR__ . '/SchemaReadiness.php';

/**
 * Координатор обычного HTTP-запуска после прохождения раннего recovery-барьера.
 *
 * Updater и maintenance намеренно не находятся здесь: index.php обязан уметь
 * восстановить прерванное пофайловое обновление даже если новый файл этого
 * класса ещё не успел появиться на диске.
 */
final class ApplicationEntryPoint
{
    public static function bootstrap(string $root): void
    {
        CrawlerDefense::handleEarlyRequest();

        $schemaState = SchemaReadiness::inspect($root);
        if (!$schemaState['ready']) {
            self::respondSchemaUpgradeRequired($schemaState);
        }

        require_once $root . '/core.php';
    }

    public static function dispatch(string $root): void
    {
        require_once $root . '/core/Router.php';

        $router = Router::getInstance();
        $router->addGlobalMiddleware(\App\Middlewares\EnforceMaintenanceMode::class);
        $router->add(
            'GET',
            '/module-assets',
            [ModuleAssetController::class, 'serve'],
            [],
            'module_asset'
        );

        require_once $root . '/core/routerConfig.php';
        ModuleRuntimeLoader::getInstance()->registerRoutes($router);
        $router->dispatch();
    }

    /**
     * @param array{ready:bool,missing_tables:list<string>,missing_user_columns:list<string>} $state
     */
    private static function respondSchemaUpgradeRequired(array $state): never
    {
        if (self::isMessengerLongPollRequest()) {
            self::respondSuspendedMessengerLongPoll();
        }

        http_response_code(503);
        header('Cache-Control: no-store');
        header('Retry-After: 60');

        if (self::wantsJson()) {
            self::respondSchemaUpgradeJson();
        }

        self::respondSchemaUpgradeHtml($state);
    }

    private static function respondSchemaUpgradeJson(): never
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error' => 'database_schema_update_required',
            'message' => 'Код приложения новее схемы базы данных. Завершите миграции.',
            'retry_after' => 60,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit;
    }

    /**
     * @param array{ready:bool,missing_tables:list<string>,missing_user_columns:list<string>} $state
     */
    private static function respondSchemaUpgradeHtml(array $state): never
    {
        header('Content-Type: text/html; charset=utf-8');

        $nonce = htmlspecialchars(SecurityHeaders::nonce(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $missingCount = count($state['missing_tables']) + count($state['missing_user_columns']);

        echo <<<HTML
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Требуется обновление базы данных</title>
    <style nonce="{$nonce}">
        :root { color-scheme: light dark; font-family: system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:center; padding:24px; background:#f4f6f9; color:#1f2937; }
        main { width:min(720px,100%); padding:28px; background:#fff; border:1px solid #dce2e9; border-radius:16px; box-shadow:0 18px 48px rgba(31,41,55,.10); }
        h1 { margin:0 0 10px; font-size:24px; }
        p { margin:8px 0; line-height:1.6; color:#667085; }
        code { display:block; margin-top:8px; padding:10px 12px; border:1px solid #e4e7ec; border-radius:8px; background:#f8fafc; color:#344054; white-space:pre-wrap; }
        .count { font-weight:700; color:#344054; }
        @media (prefers-color-scheme:dark) {
            body { background:#111318; color:#f3f4f6; }
            main { background:#191c22; border-color:#303640; }
            p { color:#aab2bf; }
            code { background:#111318; border-color:#303640; color:#e5e7eb; }
            .count { color:#e5e7eb; }
        }
    </style>
</head>
<body>
<main role="status">
    <h1>Требуется обновление базы данных</h1>
    <p>Файлы приложения уже обновлены, но схема базы данных ещё относится к предыдущей версии.</p>
    <p class="count">Обнаружено несоответствий: {$missingCount}.</p>
    <p>Перед продолжением сделайте резервную копию БД и выполните из корня Workspace:</p>
    <code>php bin/migrate.php --status
php bin/migrate.php
php bin/healthcheck.php --json</code>
    <p>После успешной миграции просто обновите страницу. Установщик запускать не нужно.</p>
</main>
</body>
</html>
HTML;
        exit;
    }

    private static function isMessengerLongPollRequest(): bool
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            return false;
        }

        $path = self::requestPath();
        return $path !== '' && preg_match('~(?:^|/)messenger/realtime/poll/?$~D', $path) === 1;
    }

    private static function wantsJson(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        return str_contains($accept, 'application/json');
    }

    private static function requestPath(): string
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return is_string($path) ? $path : '';
    }

    private static function respondSuspendedMessengerLongPoll(int $retryAfterMs = 3000): never
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Workspace-Realtime-Suspended: 1');

        $cursor = self::hashCursor($_GET['cursor'] ?? null);
        $activityCursor = self::hashCursor($_GET['activity_cursor'] ?? null);
        $revision = self::revision($_GET['revision'] ?? null);

        echo json_encode([
            'status' => 'ok',
            'transport' => 'long_poll',
            'changed' => false,
            'cursor' => $cursor,
            'revision' => $revision,
            'activity_cursor' => $activityCursor,
            'events' => [],
            'suspended' => true,
            'retry_after_ms' => max(500, min(10000, $retryAfterMs)),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit;
    }

    private static function hashCursor(mixed $value): string
    {
        $cursor = trim((string) $value);
        return preg_match('/^[a-f0-9]{64}$/D', $cursor) === 1 ? $cursor : '';
    }

    private static function revision(mixed $value): ?int
    {
        $revision = trim((string) $value);
        return ctype_digit($revision) ? (int) $revision : null;
    }
}
