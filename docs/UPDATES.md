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

The registry is intentionally empty until the production update-key ceremony is performed. With an empty registry, `bin/update.php` and `bin/update_remote.php` fail closed and signed updating remains disabled.

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

The signature protects the version, compatibility floor, source commit and package hash/size/name. Action paths refuse same-version/downgrade packages; read-only remote checks may report those signed states without downloading package bytes.

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

## Remote signed delivery

Remote delivery is a network-ingress layer in front of the same immutable staging contract. It does **not** enter maintenance, create an updater transaction, extract the package or mutate live files.

Recommended configuration:

```dotenv
UPDATE_FEED_URL=https://updates.example.com/workspace-organizer/stable/feed.json
UPDATE_CHANNEL=stable
UPDATE_STAGING_PATH=/var/lib/notes/update-staging
```

Check the signed feed without downloading the ZIP:

```bash
php bin/update_remote.php --check-only --json
```

A successful read-only check classifies the signed feed as `update_available`, `up_to_date`, `ahead_of_feed` or `update_incompatible`. All four states leave `package_downloaded=false` and `live_files_changed=false`.

Download, verify, audit and stage an installable update:

```bash
php bin/update_remote.php --json
```

The feed itself is discovery metadata, not a trust root. It identifies only same-directory manifest/signature leaf filenames. The exact manifest bytes must pass Ed25519 verification; the verified manifest then supplies the package filename, byte size and SHA-256. An unsigned package pointer in the feed has no authority.

The vendor-free HTTPS transport requires `openssl` and fails closed on plain HTTP, literal/private/reserved network targets, non-443 ports, redirects, transfer-encoded responses, non-identity content encoding, ambiguous/missing `Content-Length`, or TLS peer/certificate verification failure. DNS is resolved first, only a public address is accepted, and that checked address is pinned to the TLS socket while certificate verification still uses the configured DNS host.

Package download is streamed into a private external temporary directory and capped at 512 MiB in addition to the signed size contract. The transport requires HTTP `Content-Length` to equal the signed size and calculates SHA-256 while downloading. The existing local package verifier and ZIP inspector then run again, followed by the normal immutable `UpdatePackageStager`; temporary network ingress bytes are removed afterwards.

The package is never downloaded when `--check-only` is used, when a newer signed update is incompatible, or when the action compatibility gate rejects same-version/downgrade/source-floor/runtime conditions.

Detailed network, publishing and failure-boundary guidance is in `docs/UPDATE_REMOTE_DELIVERY.md`.

## Administrator check and staging UI

The administrator UI exposes the same non-destructive remote-delivery primitives under `/admin/updates` without creating a second updater implementation.

Access model:

- page and signed-feed check require `admin.settings.manage`;
- check is a GET/read-only operation and downloads no package bytes;
- package staging is POST + CSRF and additionally requires the `superadmin` role;
- the global license mutation guard still applies to staging;
- feed URL and channel are server configuration only and are never accepted from browser input.

The UI displays the installed version, configured channel/feed label, trust-root/runtime readiness and the signed check result (`update_available`, `up_to_date`, `ahead_of_feed`, `update_incompatible`). Release notes and package metadata come only from the verified manifest and are escaped before rendering.

When a compatible update is available, a superadmin may explicitly download, re-verify, ZIP-audit and publish it to immutable external staging. The controller stores only a safe summary in the session: target version, signing key id, package hash and archive counts. The absolute staging path is deliberately not persisted into web state or rendered.

This first UI slice stops at staging. It has no browser action for maintenance entry, transaction-journal creation, rollback backup, release-candidate extraction, migrations, live code switch, apply or recovery. Those destructive operations remain CLI/operator transaction boundaries until a separately reviewed browser transaction flow exists.

## Updater maintenance mode

Updater maintenance is file-backed and deliberately independent from MySQL. Its marker must live outside the application tree so it remains readable while database migrations or code replacement are in progress.

Recommended production configuration:

```dotenv
UPDATE_STAGING_PATH=/var/lib/notes/update-staging
UPDATE_STATE_PATH=/var/lib/notes/update-state
UPDATE_BACKUP_PATH=/var/lib/notes/update-backups
UPDATE_RELEASE_PATH=/var/lib/notes/update-releases
UPDATE_FEED_URL=https://updates.example.com/workspace-organizer/stable/feed.json
UPDATE_CHANNEL=stable
```

If explicit updater state/storage paths are omitted, updater components use safe subdirectories below `PRIVATE_STORAGE_PATH` where supported.

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

## Transaction journal and verified rollback backup

Before live-code switch or migrations, an updater transaction must own maintenance mode and be bound to one already verified staged artifact. The journal is stored outside both the live application tree and MySQL under `UPDATE_STATE_PATH/transactions`.

The journal records immutable transaction identity:

- transaction id;
- installed and target version/version code;
- signed package SHA-256;
- verified stage directory;
- transaction state/history;
- whether any live mutation has started;
- hashes and locations of rollback artifacts and the verified release candidate.

The same transaction id cannot silently be rebound to another package, stage, backup or candidate. Journal writes are serialized and atomically replaced.

Create the rollback checkpoint only after the same transaction has entered maintenance:

```bash
php bin/update_backup.php \
  --transaction=update-2026-001 \
  --stage-dir=/var/lib/notes/update-staging/<verified-stage>
```

Optional `--state-root` and `--backup-root` override `UPDATE_STATE_PATH` and `UPDATE_BACKUP_PATH`.

`bin/update_backup.php` re-verifies the staged manifest/signature, installed/target compatibility, package SHA-256 and ZIP structure before touching backup state. It then creates two rollback artifacts under an external temporary directory and atomically publishes them only after verification:

1. **Code snapshot.** Runtime/release files are copied with per-file SHA-256, size and mode metadata. The snapshot intentionally excludes `.env`, cache/compile, uploads, private storage and other mutable paths so rollback cannot overwrite secrets or user-owned files.
2. **MySQL dump.** The dump is generated through `mysqli` inside `START TRANSACTION WITH CONSISTENT SNAPSHOT`, so shared-hosting deployments do not depend on a `mysqldump` binary. Every base table is captured together with data and triggers. The backup fails closed if unsupported views/routines/events or non-InnoDB tables are present because such a snapshot would not be a complete/consistent rollback artifact.

The top-level `backup.json`, code manifest and SQL dump are hash-verified. Repeating the same backup transaction is idempotent only while the existing artifacts still verify byte-for-byte.

A successful backup checkpoint leaves maintenance active and journal state at `backup_verified` with `live_mutation_started=false`.

## Verified external release candidate

The verified ZIP is never extracted into the live application tree. After `backup_verified`, build a release candidate outside the application root:

```bash
php bin/update_candidate.php \
  --transaction=update-2026-001 \
  --candidate-root=/var/lib/notes/update-releases
```

The command re-verifies the same transaction/staged signed package and extracts into an external candidate directory. Every extracted file is checked against ZIP CRC/size, unsupported filesystem entries are rejected, the target `core/Version.php` is validated and a `.workspace-release-tree.json` SHA-256 tree manifest is written. Candidate creation does not switch live code and does not run migrations.

## Transactional live apply and automatic rollback

Apply a previously verified candidate only while the same transaction still owns maintenance:

```bash
php bin/update_apply.php \
  --transaction=update-2026-001 \
  --candidate-dir=/var/lib/notes/update-releases/<candidate> \
  --apply
```

Immediately before live mutation the command:

1. re-verifies rollback backup and candidate tree;
2. confirms the installed version still matches the checkpoint;
3. runs pre-update `bin/healthcheck.php --json`;
4. runs candidate `bin/migrate.php --dry-run` including migration checksum/schema validation;
5. re-verifies backup/candidate again;
6. records WebSocket running state;
7. writes `preflight_verified`, then durably records `live_mutation_started=true`.

The live tree is not overwritten file-by-file and the updater never unzips over it. Because the current installation is not a release-symlink layout, the updater prepares release-owned top-level entries in a private sibling directory on the same filesystem and activates them through controlled `rename()` operations. `.env` and configured mutable storage remain in place. Unsafe mutable paths nested under a release-owned top-level directory make apply fail before mutation.

After code switch the updater runs migrations, live healthcheck, exact target-version verification and `bin/migrate.php --status`. If native WebSocket was running before apply, it is restarted and checked before the transaction reaches `committed`. Maintenance is removed only after the committed installation is verified.

Any failure after `live_mutation_started` and before `committed` automatically restores code from the verified snapshot, restores MySQL from the verified consistent dump, verifies the exact pre-update version, healthcheck and migration status, and only then records `rollback_verified` and releases maintenance.

If rollback cannot verify, journal state becomes `rollback_failed` where possible and maintenance stays active. Recovery artifacts are preserved.

Crash/process-death recovery is explicit and phase-aware:

```bash
php bin/update_apply.php \
  --transaction=update-2026-001 \
  --recover
```

Recovery resumes from the durable journal (`rollback_started`, `code_restored`, `database_restored`, `rollback_failed`, `rollback_verified` or `committed`) rather than assuming the original PHP process survived. A failure to remove the maintenance marker after `committed` or `rollback_verified` never converts a verified terminal state into a destructive rollback.

Detailed operator and state-machine guidance is in `docs/UPDATER_LIVE_APPLY.md`.

## Current updater boundary

The signed-update stack now provides:

- signed update verification and trust-root separation;
- compatibility/package hash validation;
- non-extracting ZIP safety audit;
- public-HTTPS signed feed discovery with read-only status classification;
- exact signed package download with DNS/TLS/HTTP framing restrictions;
- administrator read-only signed-feed check and superadmin immutable staging UI;
- external immutable staging for local or remote ingress;
- DB-independent maintenance ownership/recovery;
- external transaction journal;
- verified code + MySQL rollback checkpoint;
- verified external release-candidate extraction/tree manifest;
- pre-healthcheck and migration dry-run/checksum gate;
- controlled live code switch while preserving installation/mutable state;
- migration execution under the same transaction;
- exact-version/post-health/schema verification;
- WebSocket restart verification when the updater owns that lifecycle;
- automatic code/MySQL rollback;
- crash-resumable recovery with fail-closed maintenance.

It still does **not**:

- expose destructive maintenance/backup/candidate/apply/recovery operations in the administrator UI;
- create the production license/update private keys (production key ceremony is intentionally still pending);
- automatically delete old verified backup/candidate/scratch recovery artifacts;
- replace an external process supervisor's own drain/restart policy;
- eliminate the requirement for the final real Beta4 -> 1.0 upgrade/rollback release drill.

Those remaining items are release-delivery/operations work. They must not weaken the signed transaction or introduce a direct “unzip over live” shortcut.

## Key rotation

Use overlapping public trust roots:

1. release A trusts old key;
2. release B trusts old + new keys;
3. sign subsequent updates with the new private key;
4. after the supported upgrade window, a later release may remove the old public key.

Removing an old public key too early can strand installations that have not yet crossed the rotation release.
