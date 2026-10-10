<?php

declare(strict_types=1);

namespace Core;

use JsonException;
use Throwable;

/**
 * Общий минимальный bootstrap CLI-команд.
 *
 * Команды сами подключают доменные сервисы; здесь остаются только окружение,
 * runtime-autoloader и единый формат аварийного завершения.
 */
final class CliRuntime
{
    public static function projectRoot(): string
    {
        return dirname(__DIR__);
    }

    public static function assertCli(int $exitCode = 2): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        fwrite(STDERR, "Команда доступна только из CLI.\n");
        exit($exitCode);
    }

    public static function loadEnvironment(?string $root = null): string
    {
        self::assertCli();
        $root ??= self::projectRoot();

        require_once __DIR__ . '/Environment.php';
        $envFile = $root . '/.env';
        if (is_file($envFile)) {
            Environment::load($envFile);
        }

        return $root;
    }

    public static function registerAutoloader(?string $root = null): string
    {
        $root ??= self::projectRoot();
        require_once __DIR__ . '/RuntimeAutoloader.php';
        RuntimeAutoloader::register($root);

        return $root;
    }

    /** @param array<string,mixed> $payload */
    public static function writeJson(array $payload, bool $pretty = false): void
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }

        try {
            fwrite(STDOUT, json_encode($payload, $flags) . PHP_EOL);
        } catch (JsonException $exception) {
            fwrite(STDERR, '[FAIL] Не удалось сформировать JSON: ' . $exception->getMessage() . PHP_EOL);
        }
    }

    public static function fail(
        Throwable $exception,
        bool $json,
        string $errorCode,
        int $exitCode = 1
    ): never {
        if ($json) {
            self::writeJson([
                'status' => 'fail',
                'error' => $errorCode,
                'message' => $exception->getMessage(),
            ]);
        } else {
            fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
        }

        exit($exitCode);
    }
}
