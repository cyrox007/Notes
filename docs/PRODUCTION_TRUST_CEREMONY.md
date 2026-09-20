# Production trust ceremony

This ceremony creates the two production Ed25519 trust roots used by Workspace Organizer 1.0:

- installation license signing;
- signed update-manifest signing.

The two domains MUST use independent keypairs. Never reuse the same private/public key material across the license and update domains.

## Security boundary

Run the ceremony on a controlled offline workstation or equivalent isolated vendor signing environment.

Never commit, upload to CI, copy into a release bundle, install on a customer server, place in .env, or paste into issue/chat logs any private signing key.

Only the base64url public keys are committed to:

- `config/license_trusted_keys.php`;
- `config/update_trusted_keys.php`.

Private key files remain outside the repository and should be protected by the vendor's offline secret storage and backup controls.

## 1. Prepare two independent external secret paths

Example:

```bash
umask 077
mkdir -p /secure/workspace-signing
chmod 700 /secure/workspace-signing
```

Use different files and different key IDs:

- license: `prod-license-2026-01`;
- update: `update-prod-2026-01`.

Do not use a dot in the update key ID because the update signature token uses dot separators.

## 2. Generate the license signing keypair

From a trusted source checkout:

```bash
php tools/vendor-license/keygen.php \
  --key-id=prod-license-2026-01 \
  --private-out=/secure/workspace-signing/prod-license-2026-01.license-secret
```

Record only the printed public registry entry. The private file must remain mode 0600 outside the repository.

## 3. Generate the update signing keypair

```bash
php tools/vendor-update/keygen.php \
  --key-id=update-prod-2026-01 \
  --private-out=/secure/workspace-signing/update-prod-2026-01.update-secret
```

Again, record only the public registry entry.

The license public key and update public key must be different. The repository contract also rejects reused public key material even when the key IDs differ.

## 4. Commit only public trust roots

Add the license public entry to `config/license_trusted_keys.php`.

Add the update public entry to `config/update_trusted_keys.php`.

Open a dedicated PR. Never commit either `*.license-secret` or `*.update-secret`.

The stable release gate for `master` intentionally fails while either production public registry is empty.

## 5. License canary

Use a non-production test installation ID and the offline license private key:

```bash
php tools/vendor-license/issue.php \
  --private-key=/secure/workspace-signing/prod-license-2026-01.license-secret \
  --key-id=prod-license-2026-01 \
  --installation-id=11111111-2222-4333-8444-555555555555 \
  --license-id=lic-release-canary-001 \
  --edition=standard \
  --features=notes,tasks,files,messenger
```

Verify the resulting token against a build containing the committed public registry. Do not commit the canary token.

## 6. Update-signing canary

Create a disposable ZIP package outside the repository or use the exact release-candidate bundle, then build a manifest:

```bash
php tools/vendor-update/build-manifest.php \
  --package=/secure/release/workspace-organizer-v1.0.0.zip \
  --version=1.0.0 \
  --version-code=10000 \
  --channel=stable \
  --source-commit=<FULL_40_HEX_RELEASE_COMMIT> \
  --min-source-version-code=1404 \
  --requires-php=8.1.0 \
  --out=/secure/release/update.json
```

Sign the exact manifest bytes with the separate update key:

```bash
php tools/vendor-update/sign-manifest.php \
  --private-key=/secure/workspace-signing/update-prod-2026-01.update-secret \
  --key-id=update-prod-2026-01 \
  --manifest=/secure/release/update.json \
  --signature-out=/secure/release/update.sig
```

The updater must accept the manifest only when the corresponding public key is present in `config/update_trusted_keys.php`.

## 7. Acceptance before master release

Before merging the final release candidate to `master`:

1. both public registries are non-empty;
2. key IDs do not overlap;
3. public key fingerprints do not overlap;
4. production private keys exist only in the offline vendor environment;
5. a canary license verifies;
6. a canary update manifest verifies;
7. the full Stable release gate is green on the exact release head;
8. the final release artifact is signed with the update-domain key after its SHA-256 is final.

If any private signing material appears in Git history, CI artifacts, customer packages, support archives or chat/log output, treat that keypair as compromised and replace it before release.
