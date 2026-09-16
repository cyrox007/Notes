# Verified update release candidates

This layer prepares a signed Workspace Organizer update for a later live switch **without changing the live application tree or database**.

## Preconditions

Before running candidate extraction, all of the following must already be true:

- the update manifest/signature/package passed `bin/update.php` verification and external staging;
- the same transaction owns updater maintenance mode;
- `bin/update_backup.php` completed successfully;
- the transaction journal is in `backup_verified` state;
- `live_mutation_started` is still `false`.

If any precondition is not true, `bin/update_candidate.php` fails closed.

## Configuration

Recommended production path:

```dotenv
UPDATE_RELEASE_PATH=/var/lib/notes/update-releases
```

The path must be absolute, writable by PHP and outside the live application/document-root tree. If it is omitted, the updater falls back to `<PRIVATE_STORAGE_PATH>/update-releases`.

Do not serve this directory directly through the web server.

## Command

```bash
php bin/update_candidate.php \
  --transaction=update-2026-001
```

Optional overrides:

```bash
php bin/update_candidate.php \
  --transaction=update-2026-001 \
  --state-root=/var/lib/notes/update-state \
  --candidate-root=/var/lib/notes/update-releases \
  --json
```

The command re-verifies:

- maintenance ownership;
- `backup_verified` transaction journal state;
- `live_mutation_started=false`;
- staged manifest/signature against the current update public trust registry;
- package compatibility, signed filename/size/SHA-256 and ZIP safety contract;
- stage metadata against the transaction journal and signed package.

Only then is the archive extracted to a temporary external candidate directory.

## Extraction safety

`Core\UpdateReleaseCandidate` does not call shell `unzip` and does not depend on `ZipArchive`. It uses the narrow ZIP subset already accepted by `UpdateArchiveInspector` and supports only stored/deflated regular files and directories.

During extraction it re-checks local/central ZIP metadata, streams payloads, enforces declared uncompressed size and verifies CRC32 for every file. The extracted package must contain exactly one top-level bundle directory.

The candidate must contain at least:

- `index.php`
- `install.php`
- `core.php`
- `core/Version.php`
- `bin/migrate.php`
- `bin/healthcheck.php`
- `config/update_trusted_keys.php`

The candidate is rejected if it contains `.env`, `tools/vendor-license`, or `tools/vendor-update`.

`core/Version.php` inside the candidate must exactly match the signed manifest `version` and `version_code`.

## Tree verification

Every extracted regular file is recorded with SHA-256 and byte size in:

```text
.workspace-release-tree.json
```

The candidate directory is published by atomic rename only after this tree contract succeeds. Re-running extraction for the same package does not overwrite the candidate; it re-hashes the existing tree and fails if any file was modified.

## Current safety boundary

A successful command returns `candidate_verified`, but still:

- does not overwrite live application files;
- does not edit `.env`;
- does not run migrations;
- does not restart PHP/WebSocket processes;
- does not change the active release;
- does not release maintenance mode.

The next updater layer must re-verify this candidate immediately before any live switch. A historical `candidate_verified` result is not sufficient authorization by itself.
