# Installation-wide licensing

Workspace Organizer 1.0 uses one offline-verifiable license per installation.

## Trust model

- Every installation has a stable `installation_id` stored in `system_settings`.
- A license is an Ed25519-signed token bound to exactly that `installation_id`.
- Runtime installations contain **public verification keys only**.
- The Ed25519 private signing key must never be committed to this repository, copied into a hosting bundle, written to `.env`, or installed on a customer server.
- Verification is offline. Normal license checks do not contact a licensing server.
- Invalid, missing, expired, or foreign-installation licenses must never delete, rewrite, encrypt, or otherwise damage user data.

The current foundation deliberately keeps enforcement separate from verification/activation. This lets activation, migration and recovery semantics be tested before access restrictions are introduced.

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

The token carries a `key-id`. `LicenseVerifier` accepts a map of trusted public keys, so a future release can contain both the old and new public key during a rotation window. Existing licenses do not need to be re-signed until the old public key is intentionally retired.

## Production signing-key ceremony

Before licensing enforcement can be enabled for the stable release:

1. Generate the Ed25519 keypair on an offline/controlled signing machine.
2. Back up the **private key** only in the vendor's secure secret storage/signing environment.
3. Export only the raw public key, base64url-encoded.
4. Add the public key to `LicenseVerifier::TRUSTED_PUBLIC_KEYS` under a short immutable key id such as `prod-2026-01`.
5. Run the licensing contract and full release CI.
6. Issue customer tokens outside the distributed application using the private key.

Do not generate a production private key inside the application or CI job, and do not place a private key in GitHub source, Actions artifacts, release ZIPs, support archives, or application backups.

## Administration

`/admin/license` shows the stable Installation ID and current verification state. Users with `admin.settings.manage` may view the state; activation/removal additionally requires the actual `superadmin` role. Activation verifies the signature, installation binding and time window **before** replacing the stored token.

Removing a token clears only `workspace_license_token`; user content and the installation identifier remain untouched.
