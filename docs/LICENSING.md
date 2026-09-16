# Installation-wide licensing

Workspace Organizer 1.0 uses one offline-verifiable license per installation.

## Trust model

- Every installation has a stable `installation_id` stored in `system_settings`.
- A license is an Ed25519-signed token bound to exactly that `installation_id`.
- Runtime installations contain **public verification keys only**.
- The Ed25519 private signing key must never be committed to this repository, copied into a hosting bundle, written to `.env`, installed on a customer server, attached to a CI artifact, or placed in a support archive/application backup.
- Verification is offline. Normal license checks do not contact a licensing server.
- Invalid, missing, expired, or foreign-installation licenses never delete, rewrite, encrypt, or otherwise damage user data.
- Runtime enforcement is dormant while the release public trust registry is empty. Once a trusted public key is shipped, invalid license state is recovery-safe read-only: reads/login/logout/license recovery remain available while data mutations are blocked.

## Public trust registry

Production public keys live in the data-only file:

```text
config/license_trusted_keys.php
```

The registry returns a map of immutable key id to base64url-encoded raw 32-byte Ed25519 **public** key. `LicenseVerifier` loads this registry by default; tests may inject ephemeral public keys directly.

Do not put key generation, private key paths, tokens, customer secrets, or signing credentials into that file. A normal customer release must contain the public registry but must not contain `tools/vendor-license/` or any private-key file.

An empty registry is intentional before the production key ceremony. It keeps enforcement disabled rather than silently trusting a generated/local key.

## Token format

```text
wo1.<key-id>.<base64url-json-payload>.<base64url-ed25519-signature>
```

The signature covers the exact ASCII bytes:

```text
wo1.<key-id>.<base64url-json-payload>
```

Required payload fields:

```json
{
  "v": 1,
  "license_id": "lic-...",
  "installation_id": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
  "issued_at": 1700000000,
  "expires_at": 1730000000,
  "edition": "standard"
}
```

`expires_at` may be `null` for a perpetual license. Optional fields currently understood by the verifier are `not_before`, `customer`, and `features`.

## Key rotation

The token carries a `key-id`. `LicenseVerifier` accepts a map of trusted public keys, so a release can contain both the retiring and replacement public key during a rotation window.

Rotation procedure:

1. Generate the replacement pair in the offline vendor signing environment.
2. Add only the replacement public key to `config/license_trusted_keys.php` while keeping the retiring public key.
3. Release that dual-trust build first.
4. Start issuing new licenses with the replacement key id.
5. Reissue/allow expiry of licenses that still depend on the retiring key.
6. Remove the retiring public key only in a later release after its dependency window is closed.

Removing a public key immediately makes licenses signed only by that key unverifiable, so key retirement is a release-management action, not routine cleanup.

## Production signing-key ceremony

Vendor-only CLI helpers live in `tools/vendor-license/` in the source repository. The hosting-package workflow explicitly excludes that directory from customer release ZIPs. These helpers contain no production secret themselves.

### 1. Generate the pair on the controlled/offline signing machine

Use an absolute private-key path outside the repository tree:

```bash
php tools/vendor-license/keygen.php \
  --key-id=prod-2026-01 \
  --private-out=/secure/offline/workspace-prod-2026-01.license-secret
```

The command:

- refuses to write the private key inside the repository tree;
- refuses to overwrite an existing private key;
- writes the private key with mode `0600` on Unix-like systems;
- prints only the public key/registry entry, never the private key;
- zeroes in-process secret buffers before exiting.

Back up the private key only in the vendor's secure offline/secret storage according to the organization's recovery policy.

### 2. Add only the public key to the release registry

Copy the printed registry entry into `config/license_trusted_keys.php`, for example:

```php
return [
    'prod-2026-01' => '<base64url-public-key>',
];
```

Commit/review the public-key-only change and run the licensing plus full release CI. Inspect the built customer ZIP and confirm it includes the public registry but not `tools/vendor-license/`, `*.license-secret`, or any other signing material.

### 3. Issue a license offline

Copy the customer's exact Installation ID from `/admin/license` and run on the offline signing machine:

```bash
php tools/vendor-license/issue.php \
  --private-key=/secure/offline/workspace-prod-2026-01.license-secret \
  --key-id=prod-2026-01 \
  --installation-id=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx \
  --license-id=lic-customer-001 \
  --edition=standard \
  --expires-at=1798761599 \
  --customer='Customer name' \
  --features=notes,tasks,files,messenger
```

If `--expires-at` is omitted the issued license is perpetual. Optional `--not-before` is a Unix timestamp. The issuer writes only the final `wo1...` token to stdout, derives the public key from the external private key, self-verifies the generated token with `LicenseVerifier`, and zeroes the loaded secret before exit.

Do not pipe issuer stdout to shared CI logs or ticketing systems: a license token is not a signing secret, but it is still customer-specific entitlement material.

## Runtime enforcement and recovery

Once at least one trusted production public key is present, invalid/missing/expired license state places the application into recovery-safe read-only mode:

- GET/HEAD/OPTIONS and normal read views remain available subject to RBAC/ACL;
- login and logout remain available;
- `/admin/license` activation/removal remains available for recovery;
- ordinary HTTP mutations are denied;
- Messenger may reconnect/read/search, while message/reaction/receipt/media/dialog/group mutations are denied;
- open WebSocket connections re-check the license before each mutating action, so expiry cannot be bypassed by keeping a socket open.

A license verification failure never performs destructive data actions.

## Administration

`/admin/license` shows the stable Installation ID and current verification state. Users with `admin.settings.manage` may view the state; activation/removal additionally requires the actual `superadmin` role. Activation verifies the signature, installation binding and time window **before** replacing the stored token.

Removing a token clears only `workspace_license_token`; user content and the installation identifier remain untouched.
