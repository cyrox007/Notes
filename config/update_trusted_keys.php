<?php

declare(strict_types=1);

/**
 * Workspace Organizer update-signing trust registry.
 *
 * SECURITY BOUNDARY:
 * - values are base64url-encoded raw 32-byte Ed25519 PUBLIC keys only;
 * - update signing keys are a separate cryptographic domain from license keys;
 * - private update-signing keys must never be committed, installed on customer
 *   servers, stored in .env/database settings, or included in release bundles;
 * - key ids are immutable. During rotation, ship old + new public keys together
 *   before retiring the old key in a later release.
 *
 * The registry intentionally remains empty until the production update-signing
 * key ceremony is completed on a controlled/offline vendor machine.
 *
 * @return array<string,string> key-id => base64url(raw Ed25519 public key)
 */
return [
    // 'update-prod-2026-01' => '<base64url-public-key>',
];
