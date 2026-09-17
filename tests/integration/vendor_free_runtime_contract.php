<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function vendorFreeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
vendorFreeAssert(is_array($composer), 'composer.json is invalid');
vendorFreeAssert(array_keys((array) ($composer['require'] ?? [])) === ['php'], 'Composer runtime requirements must contain PHP only');

$lock = json_decode((string) file_get_contents($root . '/composer.lock'), true);
vendorFreeAssert(is_array($lock), 'composer.lock is invalid');
vendorFreeAssert(($lock['packages'] ?? null) === [], 'composer.lock still contains runtime packages');
vendorFreeAssert(($lock['packages-dev'] ?? null) === [], 'composer.lock still contains dev packages');

foreach (['core.php', 'install.php', 'ws_server/server.php'] as $entrypoint) {
    $source = (string) file_get_contents($root . '/' . $entrypoint);
    vendorFreeAssert(!str_contains($source, 'vendor/autoload.php'), "{$entrypoint} still loads vendor/autoload.php");
    vendorFreeAssert(!str_contains($source, 'Workerman\\'), "{$entrypoint} still references Workerman");
    vendorFreeAssert(!str_contains($source, 'Smarty\\'), "{$entrypoint} still references Smarty");
}

foreach ([
    'core/Environment.php',
    'core/NativeViewRenderer.php',
    'modules/messenger/socket/SocketConnection.php',
    'modules/messenger/socket/SocketHandshake.php',
    'modules/messenger/socket/SocketFrameCodec.php',
    'modules/messenger/socket/NativeSocketConnection.php',
    'modules/messenger/socket/NativeMessengerServer.php',
] as $required) {
    vendorFreeAssert(is_file($root . '/' . $required), "internal runtime component missing: {$required}");
}

foreach ([
    'core/LegacySmartyRenderer.php',
    'core/HybridViewRenderer.php',
    'modules/messenger/socket/WorkermanConnectionAdapter.php',
    'app/socket/NativeMessengerServer.php',
] as $legacy) {
    vendorFreeAssert(!is_file($root . '/' . $legacy), "legacy third-party or ownership adapter still exists: {$legacy}");
}

$socketDirectory = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/modules/messenger/socket'));
foreach ($socketDirectory as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    vendorFreeAssert(!str_contains($source, 'Workerman\\'), 'Workerman leaked into ' . $file->getFilename());
}

$core = (string) file_get_contents($root . '/core.php');
vendorFreeAssert(str_contains($core, '/core/NativeViewRenderer.php'), 'native view renderer is not bootstrapped');
$controller = (string) file_get_contents($root . '/core/controller.php');
vendorFreeAssert(str_contains($controller, 'new NativeViewRenderer('), 'HTTP controller does not use native view renderer');

$installer = (string) file_get_contents($root . '/install.php');
vendorFreeAssert(str_contains($installer, "'Native core runtime'"), 'installer does not verify native core runtime');
vendorFreeAssert(str_contains($installer, "'Native WebSocket runtime'"), 'installer does not verify native WebSocket runtime');

$server = (string) file_get_contents($root . '/ws_server/server.php');
vendorFreeAssert(str_contains($server, 'NativeMessengerServer'), 'native WebSocket server is not the active entrypoint');

fwrite(STDOUT, "[OK] Workspace Organizer runtime is self-contained and vendor-free\n");