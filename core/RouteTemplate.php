<?php

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;
use RuntimeException;

/**
 * Pure route-template parser/formatter.
 *
 * Router owns registration/dispatch and middleware execution. This class owns
 * only path normalization, typed placeholder compilation and URL binding so the
 * routing grammar has one testable implementation.
 */
final class RouteTemplate
{
    public static function normalize(string $path): string
    {
        if (str_contains($path, "\0") || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidArgumentException('Route path contains control characters');
        }

        $path = trim($path, '/');
        $path = "/{$path}/";

        return (string) preg_replace('#/{2,}#', '/', $path);
    }

    public static function compile(string $path): string
    {
        $tokens = preg_split(
            '/(\{(?:int|str):[A-Za-z_][A-Za-z0-9_]*\})/',
            $path,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        if ($tokens === false) {
            throw new RuntimeException('Unable to compile route pattern');
        }

        $pattern = '';
        $names = [];

        foreach ($tokens as $token) {
            if (preg_match('/^\{(int|str):([A-Za-z_][A-Za-z0-9_]*)\}$/', $token, $matches) === 1) {
                $name = $matches[2];
                if (isset($names[$name])) {
                    throw new RuntimeException("Duplicate route parameter: {$name}");
                }
                $names[$name] = true;

                $valuePattern = $matches[1] === 'int' ? '\\d+' : '[A-Za-z0-9_-]+';
                $pattern .= '(?P<' . $name . '>' . $valuePattern . ')';
                continue;
            }

            $pattern .= preg_quote($token, '~');
        }

        return '~^' . $pattern . '$~D';
    }

    /**
     * @param array<string,mixed>|null $matched
     * @return array<string,mixed>
     */
    public static function typedParams(?array $matched, string $routePath): array
    {
        if ($matched === null) {
            return [];
        }

        $types = [];
        if (preg_match_all('/\{(int|str):([A-Za-z_][A-Za-z0-9_]*)\}/', $routePath, $definitions, PREG_SET_ORDER)) {
            foreach ($definitions as $definition) {
                $types[(string) $definition[2]] = (string) $definition[1];
            }
        }

        $filtered = array_filter(
            $matched,
            static fn ($key): bool => !is_int($key),
            ARRAY_FILTER_USE_KEY
        );

        foreach ($filtered as $key => $value) {
            if (($types[$key] ?? null) === 'int' && is_string($value) && ctype_digit($value)) {
                $filtered[$key] = (int) $value;
            }
        }

        return $filtered;
    }

    /**
     * @param array<string,mixed> $params
     */
    public static function bind(string $path, array $params): string
    {
        foreach ($params as $key => $value) {
            if (!is_string($key) || preg_match('/^[A-Za-z0-9_]+$/', $key) !== 1) {
                throw new InvalidArgumentException('Invalid route parameter name');
            }

            $pattern = '/\{(int|str):' . preg_quote($key, '/') . '\}/';
            if (preg_match($pattern, $path, $matches) !== 1) {
                throw new RuntimeException("Parameter {$key} not found in route path");
            }

            $type = $matches[1];
            if ($type === 'int') {
                if (!(is_int($value) || (is_string($value) && ctype_digit($value)))) {
                    throw new InvalidArgumentException("Route parameter {$key} must be an integer");
                }
                $replacement = (string) $value;
            } else {
                if (!(is_string($value) || is_int($value))) {
                    throw new InvalidArgumentException("Route parameter {$key} must be a scalar string identifier");
                }
                $replacement = (string) $value;
                if (preg_match('/^[A-Za-z0-9_-]+$/', $replacement) !== 1) {
                    throw new InvalidArgumentException("Route parameter {$key} contains invalid characters");
                }
            }

            $path = (string) preg_replace($pattern, $replacement, $path, 1);
        }

        if (preg_match('/\{(?:int|str):[A-Za-z_][A-Za-z0-9_]*\}/', $path) === 1) {
            throw new RuntimeException('Missing parameter for named route redirect');
        }

        return self::normalize($path);
    }
}
