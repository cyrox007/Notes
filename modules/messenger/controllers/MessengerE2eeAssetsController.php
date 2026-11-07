<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Controller;
use Core\Request;

/**
 * Раздаёт только разрешённые модули E2EE из текущей установленной версии Notes.
 * Внешние источники JavaScript и произвольные пути к файлам недоступны.
 */
final class MessengerE2eeAssetsController extends Controller
{
    private const CLIENT_FILES = [
        'matrix-device-bootstrap.mjs',
        'matrix-http-transport.mjs',
        'matrix-device-trust.mjs',
        'matrix-sdk-trust.mjs',
        'matrix-transport-bridge.mjs',
        'matrix-room-keys.mjs',
        'matrix-room-messages.mjs',
        'matrix-incoming.mjs',
        'matrix-conversation.mjs',
        'e2ee-file-crypto.mjs',
    ];

    public function client(Request $request, string $filename): void
    {
        $this->serve($filename, self::CLIENT_FILES, dirname(__DIR__) . '/crypto');
    }

    public function sdk(Request $request, string $filename): void
    {
        $this->serve($filename, ['index.mjs'], dirname(__DIR__) . '/crypto/sdk');
    }

    public function sdkPackage(Request $request, string $filename): void
    {
        $this->serve(
            $filename,
            ['matrix_sdk_crypto_wasm_bg.js', 'matrix_sdk_crypto_wasm_bg.wasm'],
            dirname(__DIR__) . '/crypto/sdk/pkg'
        );
    }

    /** @param list<string> $allowlist */
    private function serve(string $filename, array $allowlist, string $root): void
    {
        if (!in_array($filename, $allowlist, true)) {
            http_response_code(404);
            return;
        }
        $folder = realpath($root);
        $path = realpath($root . DIRECTORY_SEPARATOR . $filename);
        if ($folder === false || $path === false
            || dirname($path) !== $folder || !is_file($path) || !is_readable($path)) {
            http_response_code(404);
            return;
        }
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $type = $extension === 'wasm' ? 'application/wasm' : 'text/javascript; charset=utf-8';
        header('Content-Type: ' . $type);
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header('Cross-Origin-Resource-Policy: same-origin');
        header('Content-Length: ' . filesize($path));
        readfile($path);
    }
}
