<?php
/**
 * Центральное хранилище версии приложения.
 * Полная история изменений ведётся в /CHANGELOG.md.
 */

declare(strict_types=1);

namespace Core;

class Version
{
    public const VERSION = '0.10.0-alpha';
    public const PRODUCT_NAME = 'Workspace Organizer';
    public const STATUS = 'alpha';
    public const VERSION_CODE = 1000;
    public const RELEASE_DATE = '2026-09-13';

    public static function getFullVersion(): string
    {
        return self::VERSION;
    }

    /** @return array<string,string> */
    public static function getProductInfo(): array
    {
        return [
            'name' => self::PRODUCT_NAME,
            'version' => self::VERSION,
            'status' => self::STATUS,
            'version_code' => (string) self::VERSION_CODE,
            'release_date' => self::RELEASE_DATE,
        ];
    }

    public static function isAlpha(): bool
    {
        return self::STATUS === 'alpha';
    }

    public static function compare(string $version): int
    {
        return version_compare(self::VERSION, $version);
    }
}
