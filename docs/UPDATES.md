# Signed updates

Workspace Organizer 1.0 uses a cryptographically signed update pipeline. Update signing is intentionally isolated from installation licensing: **license keys cannot authorize code updates, and update keys cannot issue licenses**.

## Security model

An update consists of three artifacts:

1. the hosting ZIP package;
2. an update manifest JSON file;
3. a detached Ed25519 signature token for the exact manifest bytes.

Signature token format:

```text
wou1.<key-id>.<base64url-ed25519-signature>
```

The signature covers the exact bytes:

```text
WorkspaceOrganizerUpdateManifest/v1\n<manifest bytes>
```

This domain separation is deliberate. Never reuse the production license-signing key as the production update-signing key.

Runtime installations contain only update **public** keys in:

```text
config/update_trusted_keys.php
```

The registry is intentionally empty until the production update-key ceremony is performed. With an empty registry, `bin/update.php` fails closed and signed updating remains disabled.

## Manifest contract

A signed manifest contains at least:

```json
{
  "schema": 1,
  "product": "workspace-organizer",
  "version": "1.0.0",
  "version_code": 10000,
  "channel": "stable",
  "issued_at": 1780000000,
  "source_commit": "0123456789abcdef0123456789abcdef01234567",
  "min_source_version_code": 1404,
  "requires_php": "8.1.0",
  "package": {
    "filename": "workspace-organizer-v1.0.0.zip",
    "sha256": "<64 lowercase hex chars>",
    "size": 1234567,
    "format": "zip"
  }
}
```

The signature protects the version, compatibility floor, source commit and package hash/size/name. The updater refuses same-version/downgrade packages.

## Production update-key ceremony

Perform this only on a controlled/offline vendor machine.

1. Create a directory outside the repository for signing secrets.
2. Generate a dedicated update keypair:

```bash
php tools/vendor-update/keygen.php \
  --key-id=update-prod-2026-01 \
  --private-out=/secure/offline/workspace-update-prod-2026-01.update-secret
```

3. Store the private key in vendor secret storage. It must never enter GitHub source, Actions secrets/artifacts, a customer server, a support archive, `.env`, database settings or a release ZIP.
4. Add **only** the printed public key entry to `config/update_trusted_keys.php` in a reviewed PR.
5. Run the full release CI before shipping that trust root.

The private key file uses a separate update-key format and the tooling refuses to create/read it inside the repository tree. On Unix it must not be group/other accessible.

## Building and signing release metadata

After the final hosting ZIP exists, build the manifest from that exact file:

```bash
php tools/vendor-update/build-manifest.php \
  --package=/release/workspace-organizer-v1.0.0.zip \
  --version=1.0.0 \
  --version-code=10000 \
  --channel=stable \
  --source-commit=<full-40-char-release-commit> \
  --min-source-version-code=1404 \
  --requires-php=8.1.0 \
  --out=/release/workspace-organizer-v1.0.0.update.json
```

Then sign the **exact manifest bytes**:

```bash
php tools/vendor-update/sign-manifest.php \
  --private-key=/secure/offline/workspace-update-prod-2026-01.update-secret \
  --key-id=update-prod-2026-01 \
  --manifest=/release/workspace-organizer-v1.0.0.update.json \
  --signature-out=/release/workspace-organizer-v1.0.0.update.sig
```

Changing even one byte of the manifest after signing invalidates the signature.

## Customer-side verification and archive preflight

Verification only:

```bash
php bin/update.php \
  --manifest=/path/workspace-organizer-v1.0.0.update.json \
  --signature=/path/workspace-organizer-v1.0.0.update.sig \
  --package=/path/workspace-organizer-v1.0.0.zip \
  --verify-only
```

The command verifies:

- trusted update key id;
- Ed25519 signature over exact manifest bytes;
- manifest schema/product fields;
- issue time sanity;
- target is newer than the installed `VERSION_CODE`;
- installed version satisfies `min_source_version_code`;
- local PHP satisfies `requires_php`;
- package filename, byte size and SHA-256;
- ZIP central-directory and local-header consistency;
- safe relative UTF-8 entry paths;
- no path traversal, absolute/backslash/colon paths or NULs;
- no symlink/special Unix entries;
- no encrypted, multi-disk, ZIP64 or data-descriptor entries in the supported update subset;
- supported compression methods only;
- no duplicate/case-colliding paths;
- per-file/total uncompressed-size and compression-ratio safety limits;
- no overlapping local entry payload regions.

The structural audit is pure PHP and does **not** extract the archive. It accepts only explicit local regular files, not URLs or symlinks.

## External verified staging

To stage a verified update:

```bash
php bin/update.php \
  --manifest=/path/update.json \
  --signature=/path/update.sig \
  --package=/path/package.zip \
  --stage-root=/absolute/path/outside/application
```

If `--stage-root` is omitted, `UPDATE_STAGING_PATH` is used; otherwise the updater falls back to `<PRIVATE_STORAGE_PATH>/updates`.

The staging root must resolve outside the live application tree. The updater:

1. verifies signature, compatibility, package SHA-256 and ZIP structure;
2. locks the staging root against concurrent staging;
3. copies into a random temporary stage directory;
4. verifies SHA-256 again after copying;
5. stores the exact manifest/signature plus stage metadata;
6. atomically renames the temporary directory to its final immutable stage name.

Repeating the same signed artifact is idempotent and re-verifies the existing staged files.

## Updater maintenance mode

Updater maintenance is file-backed and deliberately independent from MySQL. Its marker must live outside the application tree so it remains readable while database migrations or code replacement are in progress.

Recommended production configuration:

```dotenv
UPDATE_STAGING_PATH=/var/lib/notes/update-staging
UPDATE_STATE_PATH=/var/lib/notes/update-state
```

If `UPDATE_STATE_PATH` is omitted, maintenance state falls back to `<PRIVATE_STORAGE_PATH>/updates`.

Operator CLI:

```bash
php bin/maintenance.php --action=status
php bin/maintenance.php --action=enter --transaction=update-2026-001 --reason='Обновление приложения'
php bin/maintenance.php --action=leave --transaction=update-2026-001
```

A valid transaction owns the marker. Concurrent/different transactions cannot replace that ownership. `enter`/`leave` transitions are serialized by a filesystem lock.

If the marker is corrupt, runtime fails closed and treats maintenance as active. Recovery is explicit:

```bash
php bin/maintenance.php --action=leave --force
```

While maintenance is active:

- `index.php` returns HTTP `503 Service Unavailable` with `Retry-After` **before database/module bootstrap**;
- an invalid/corrupt marker also returns 503 rather than silently reopening writes;
- already-open Messenger WebSocket connections cannot execute mutating actions because the shared runtime mutation policy rechecks maintenance state;
- the operator can still recover through the CLI even if HTTP or MySQL is unavailable.

The early HTTP gate is intentional. Do not move maintenance enforcement exclusively into a normal router middleware: that would be too late when the database is unavailable during an update.

## Current transaction-preflight boundary

The updater currently stops at a verified external stage plus maintenance/archive preflight.

It does **not**:

- download remote files;
- extract a ZIP into the live tree;
- edit `.env`;
- run database migrations as part of update apply;
- restart WebSocket/PHP services;
- overwrite application code;
- delete a previous release.

This is intentional. Live apply is not considered safe until the remaining transaction layer includes all of these together:

1. transaction journal tied to the signed staged artifact;
2. pre-update `bin/healthcheck.php`;
3. migration `--dry-run` and checksum validation;
4. database backup and verification;
5. application-code backup/release snapshot;
6. controlled traversal-safe extraction into a new release directory;
7. service drain/controlled code switch;
8. `bin/migrate.php`;
9. post-update `bin/healthcheck.php` and version verification;
10. explicit rollback path, including operator guidance for non-reversible database migrations.

Maintenance lock/drain and non-extracting ZIP safety audit are already present as preconditions, but they do not authorize live mutation by themselves.

Do not add a direct “unzip over live” path as a shortcut.

## Key rotation

Use overlapping public trust roots:

1. release A trusts old key;
2. release B trusts old + new keys;
3. sign subsequent updates with the new private key;
4. after the supported upgrade window, a later release may remove the old public key.

Removing an old public key too early can strand installations that have not yet crossed the rotation release.
