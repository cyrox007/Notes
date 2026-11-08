<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/SecurityHeaders.php';

use Core\SecurityHeaders;

$previous = $_SERVER['REQUEST_URI'] ?? null;
foreach ([
    '/messenger' => true,
    '/messenger/' => true,
    '/app/messenger' => true,
    '/app/messenger/?view=chat' => true,
    '/app/messenger/e2ee/sdk/index.mjs' => false,
    '/app/messenger/e2ee/client/matrix-room-keys.mjs' => false,
    '/app/messenger/group' => false,
    '/profile' => false,
    '/evil-messenger' => false,
    '/' => false,
] as $uri => $allowWasm) {
    $_SERVER['REQUEST_URI'] = $uri;
    $policy = SecurityHeaders::policy();
    $hasWasm = str_contains($policy, " 'wasm-unsafe-eval'");
    if ($hasWasm !== $allowWasm) {
        throw new RuntimeException('Некорректная политика WebAssembly для ' . $uri);
    }
    if (str_contains($policy, " 'unsafe-eval'")) {
        throw new RuntimeException('Запрещено включать произвольный JavaScript eval');
    }
    if (!str_contains($policy, "script-src 'self' 'nonce-")) {
        throw new RuntimeException('Потерян ключ безопасности скриптов');
    }
}
if ($previous === null) {
    unset($_SERVER['REQUEST_URI']);
} else {
    $_SERVER['REQUEST_URI'] = $previous;
}
echo "[OK] WebAssembly доступен только на странице Messenger без произвольного eval\n";
