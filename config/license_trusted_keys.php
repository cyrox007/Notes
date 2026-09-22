<?php

declare(strict_types=1);

/**
 * Production trust registry лицензий Workspace Organizer.
 *
 * Здесь допускаются только raw 32-byte ПУБЛИЧНЫЕ ключи Ed25519 в base64url.
 * Никогда не помещайте private/secret signing key в этот файл, в другие файлы
 * репозитория, .env, CI artifacts, release bundles, support archives или
 * customer installation.
 *
 * Ротация ключей: временно храните здесь и выводимый из эксплуатации, и новый
 * публичный ключ. Новые лицензии должны использовать новый key ID. Удаляйте
 * старый public key только после замены или истечения всех зависящих от него
 * лицензий.
 *
 * Пример только для документации, это не реальный production key:
 *   'prod-2026-01' => '<base64url-encoded-public-key>',
 *
 * @return array<string,string>
 */
return [
    'prod-license-2026-01' => 'IeudHzvZ-NemtyhPrbs8OsqDiieInl4MO4meFmqfel4',
];
