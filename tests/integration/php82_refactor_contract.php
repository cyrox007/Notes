<?php

declare(strict_types=1);

function php82RefactorAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);

php82RefactorAssert(PHP_VERSION_ID >= 80200, 'Контракт 1.1 должен выполняться на PHP 8.2+');

$composer = json_decode(
    (string) file_get_contents($root . '/composer.json'),
    true,
    16,
    JSON_THROW_ON_ERROR
);
$lock = json_decode(
    (string) file_get_contents($root . '/composer.lock'),
    true,
    16,
    JSON_THROW_ON_ERROR
);

php82RefactorAssert(
    ($composer['require']['php'] ?? null) === '>=8.2',
    'composer.json должен закреплять минимальный PHP 8.2'
);
php82RefactorAssert(
    ($lock['platform']['php'] ?? null) === '>=8.2',
    'composer.lock должен закреплять минимальный PHP 8.2'
);

$expectedHash = md5(json_encode(
    ['require' => ['php' => '>=8.2']],
    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
));
php82RefactorAssert(
    ($lock['content-hash'] ?? null) === $expectedHash,
    'composer.lock должен быть согласован с composer.json'
);

$eventSource = (string) file_get_contents(
    $root . '/app/services/notifications/NotificationEvent.php'
);
php82RefactorAssert(
    str_contains($eventSource, 'final readonly class NotificationEvent'),
    'Новый код 1.1 должен использовать readonly class для неизменяемого события'
);

$plan = (string) file_get_contents($root . '/docs/plans/1.1-php82-refactor.md');
php82RefactorAssert(
    str_contains($plan, 'минимальную версию PHP с 8.1 до **8.2**'),
    'План полного рефакторинга PHP 8.2 должен быть зафиксирован'
);
php82RefactorAssert(
    str_contains($plan, 'пирамидальная структура'),
    'План должен явно запрещать пирамидальную структуру методов'
);

$audit = $root . '/tools/release/php82_refactor_audit.php';
php82RefactorAssert(is_file($audit), 'Должен существовать автоматический аудит PHP 8.2');

fwrite(STDOUT, "[OK] минимальный PHP 8.2 и контур полного рефакторинга 1.1\n");
