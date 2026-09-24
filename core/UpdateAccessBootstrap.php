<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/UpdateDownloadCredentials.php';
require_once __DIR__ . '/UpdateRemoteTransport.php';
require_once __DIR__ . '/Version.php';

/**
 * Автоматическая привязка установленной лицензии к серверу обновлений.
 *
 * Пользователь не работает с activation code, файлами credentials и путями.
 * Лицензионный токен отправляется только штатному HTTPS control plane, который
 * уже хранит этот же выпущенный токен и сверяет installation_id.
 */
final class UpdateAccessBootstrap
{
    public const DEFAULT_SERVER_BASE_URL = 'https://jsinteractive.ru/api/notes/v1/';
    public const DEFAULT_CHANNEL = 'stable';

    public function __construct(private ?UpdateHttpsTransport $transport = null)
    {
    }

    public static function channel(): string
    {
        $channel = strtolower(trim((string) getenv('UPDATE_CHANNEL')));
        if ($channel === '') {
            return self::DEFAULT_CHANNEL;
        }
        if (!in_array($channel, ['alpha', 'beta', 'stable'], true)) {
            throw new RuntimeException('UPDATE_CHANNEL должен быть alpha, beta или stable');
        }
        return $channel;
    }

    public static function serverBaseUrl(): string
    {
        $configured = trim((string) getenv('UPDATE_SERVER_URL'));
        if ($configured !== '') {
            UpdateDownloadCredentials::validateBaseUrl($configured);
            return $configured;
        }

        $feed = trim((string) getenv('UPDATE_FEED_URL'));
        $derived = self::baseUrlFromFeed($feed);
        if ($derived !== null) {
            return $derived;
        }

        return self::DEFAULT_SERVER_BASE_URL;
    }

    public static function feedUrl(): string
    {
        $configured = trim((string) getenv('UPDATE_FEED_URL'));
        if ($configured !== '') {
            return $configured;
        }

        return self::serverBaseUrl() . self::channel() . '/feed.json';
    }

    public static function automaticEnabled(): bool
    {
        try {
            return UpdateDownloadCredentials::accessMode() !== 'offline';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{status:string,source:string,path?:string}
     */
    public function ensure(string $installationId, string $licenseToken): array
    {
        if (UpdateDownloadCredentials::accessMode() === 'offline') {
            return ['status' => 'offline', 'source' => 'configuration'];
        }

        $feedUrl = self::feedUrl();
        $existing = $this->existingCredentials($installationId, $feedUrl);
        if ($existing !== null) {
            return [
                'status' => 'ready',
                'source' => 'existing',
                'path' => UpdateDownloadCredentials::credentialsPath(),
            ];
        }

        $licenseToken = trim($licenseToken);
        if ($licenseToken === '' || !str_starts_with($licenseToken, 'wo1.')) {
            throw new RuntimeException('Для автоматической настройки обновлений нужна действующая лицензия');
        }

        $baseUrl = self::serverBaseUrl();
        $transport = $this->transport ?? new UpdateHttpsTransport(5, 15);
        $result = $transport->activateWithLicense(
            $baseUrl,
            strtolower($installationId),
            $licenseToken,
            Version::VERSION,
            Version::VERSION_CODE,
            self::channel()
        );

        $credentials = new UpdateDownloadCredentials($result);
        if (!hash_equals(strtolower($installationId), $credentials->installationId())) {
            throw new RuntimeException('Сервер обновлений вернул credentials другой установки');
        }
        if (!hash_equals($baseUrl, $credentials->baseUrl())) {
            throw new RuntimeException('Сервер обновлений вернул неожиданный scope credentials');
        }

        $credentials->headersFor($feedUrl);
        $path = UpdateDownloadCredentials::store($result);

        return [
            'status' => 'ready',
            'source' => 'license',
            'path' => $path,
        ];
    }

    private function existingCredentials(string $installationId, string $feedUrl): ?UpdateDownloadCredentials
    {
        try {
            $credentials = UpdateDownloadCredentials::fromEnvironment();
            if ($credentials === null) {
                return null;
            }
            if (!hash_equals(strtolower($installationId), $credentials->installationId())) {
                return null;
            }
            $credentials->headersFor($feedUrl);
            return $credentials;
        } catch (Throwable) {
            return null;
        }
    }

    private static function baseUrlFromFeed(string $feed): ?string
    {
        if ($feed === '') {
            return null;
        }

        $suffixes = [
            'alpha/feed.json',
            'beta/feed.json',
            'stable/feed.json',
        ];
        foreach ($suffixes as $suffix) {
            if (!str_ends_with($feed, $suffix)) {
                continue;
            }

            $base = substr($feed, 0, -strlen($suffix));
            try {
                UpdateDownloadCredentials::validateBaseUrl($base);
                return $base;
            } catch (Throwable) {
                return null;
            }
        }
        return null;
    }
}
