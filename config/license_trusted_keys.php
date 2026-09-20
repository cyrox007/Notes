<?php

declare(strict_types=1);

/**
 * Workspace Organizer production license trust registry.
 *
 * Values are base64url-encoded raw 32-byte Ed25519 PUBLIC keys only.
 * Never place a private/secret signing key in this file, anywhere else in the
 * repository, in .env, CI artifacts, release bundles, support archives or a
 * customer installation.
 *
 * Key rotation: temporarily keep both the retiring and replacement public keys
 * here. New licenses should use the replacement key id. Remove the old public
 * key only after every license that depends on it has been replaced/expired.
 *
 * Example (documentation only; not a real production key):
 *   'prod-2026-01' => '<base64url-encoded-public-key>',
 *
 * @return array<string,string>
 */
return [
    'prod-license-2026-01' => 'IeudHzvZ-NemtyhPrbs8OsqDiieInl4MO4meFmqfel4',
];