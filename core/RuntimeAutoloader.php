<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

final class RuntimeAutoloader
{
    /** @var array<string,string> */
    private const PREFIXES = [
        'Core\\' => 'core',
        'App\\Controllers\\' => 'app/controllers',
        'App\\Middlewares\\' => 'app/middlewares',
        'App\\Models\\' => 'app/models',
        'App\\Services\\' => 'app/services',
    ];

    /** @var array<string,string> */
    private const CLASS_FILES = [
        'App\\Helpers\\CryptMethods' => 'app/handlers/CryptMethods.php',
        'App\\Helpers\\CryptographicFailure' => 'app/handlers/CryptMethods.php',
    ];

    private static bool $registered = false;

    public static function register(string $root): void
    {
        if (self::$registered) {
            return;
        }

        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false || !is_dir($resolvedRoot) || is_link($root)) {
            throw new RuntimeException('Runtime autoload root is missing or unsafe: ' . $root);
        }

        spl_autoload_register(static function (string $class) use ($resolvedRoot): void {
            $file = self::resolve($resolvedRoot, $class);
            if ($file !== null) {
                require_once $file;
            }
        });
        self::$registered = true;
    }

    public static function resolve(string $root, string $class): ?string
    {
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false || !is_dir($resolvedRoot) || is_link($root)) {
            throw new RuntimeException('Runtime autoload root is missing or unsafe: ' . $root);
        }

        if (isset(self::CLASS_FILES[$class])) {
            return self::resolveCandidate($resolvedRoot, self::CLASS_FILES[$class]);
        }

        foreach (self::PREFIXES as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            if ($relative === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $relative) !== 1) {
                return null;
            }

            $path = $directory . '/' . str_replace('\\', '/', $relative) . '.php';
            return self::resolveCandidate($resolvedRoot, $path);
        }

        return null;
    }

    /** @return list<string> */
    public static function sharedPrefixes(): array
    {
        return array_keys(self::PREFIXES);
    }

    private static function resolveCandidate(string $root, string $relativePath): ?string
    {
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $resolved = realpath($candidate);
        if ($resolved === false || !is_file($resolved) || is_link($candidate)) {
            return null;
        }

        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($resolved, $prefix)) {
            throw new RuntimeException('Runtime autoload candidate escaped application root.');
        }

        return $resolved;
    }
}
