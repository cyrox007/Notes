<?php

declare(strict_types=1);

namespace Core;

use Exception;

/**
 * Stateless SQL inspection helpers used by DatabaseManager.
 *
 * Connection lifecycle and transaction semantics stay in DatabaseManager;
 * query classification, safe identifier validation and diagnostic rendering
 * live here so they can be tested independently.
 */
final class DatabaseSqlInspector
{
    public static function queryType(string $query): string
    {
        if (!preg_match('/^\s*([A-Za-z]+)/', $query, $matches)) {
            return 'UNKNOWN';
        }

        return strtoupper($matches[1]);
    }

    /**
     * Render a bounded diagnostic query string.
     *
     * This is intentionally not SQL interpolation and must never be executed.
     * Longer placeholder names are replaced first so :id cannot corrupt :id2.
     *
     * @param array<int|string,mixed> $params
     */
    public static function diagnosticQuery(string $query, array $params): string
    {
        $masked = $query;
        $renderedParams = [];

        foreach ($params as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $rendered = '[complex]';
            } elseif ($value === null) {
                $rendered = 'NULL';
            } elseif (is_bool($value)) {
                $rendered = $value ? '1' : '0';
            } else {
                $rendered = (string) $value;
                if (strlen($rendered) > 20) {
                    $rendered = substr($rendered, 0, 20) . '...';
                }
            }

            $placeholder = is_int($key) ? '?' : (string) $key;
            $renderedParams[] = [$placeholder, $rendered];
        }

        usort(
            $renderedParams,
            static fn (array $a, array $b): int => strlen($b[0]) <=> strlen($a[0])
        );

        foreach ($renderedParams as [$placeholder, $rendered]) {
            $masked = str_replace($placeholder, $rendered, $masked);
        }

        return $masked;
    }

    public static function assertIdentifier(string $identifier): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new Exception('Unsafe SQL identifier: ' . $identifier);
        }
    }
}
