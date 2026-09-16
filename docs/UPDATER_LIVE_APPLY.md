# Transactional live apply and rollback

This document covers the destructive half of the Workspace Organizer 1.0 signed updater. It assumes the signed artifact has already passed verification, ZIP audit, immutable external staging, maintenance entry, transaction-journal initialization, verified code/MySQL rollback backup and verified external release-candidate extraction.

The live apply layer does **not** download updates and never performs `unzip` over the active application tree.

## Safety boundary

Before live mutation, the transaction must be in the external journal and the same transaction must own maintenance mode. `bin/update_apply.php --apply` first acquires a non-blocking transaction-scoped operation lock under the external updater state root. That lock is held for the entire apply/recover command, including maintenance validation, live mutation, rollback and maintenance release. A second apply/recover process for the same transaction fails with `operation_busy` instead of entering the destructive path concurrently.

The apply path then executes these gates:

1. re-verify the rollback backup manifest, code snapshot and MySQL dump;
2. re-hash the complete release candidate tree;
3. confirm the currently installed version still matches the journal checkpoint;
4. run the current `bin/healthcheck.php --json`;
5. run candidate `bin/migrate.php --dry-run` so migration checksums/schema policy are validated against the live database without applying migrations;
6. re-verify candidate and backup again immediately before the destructive boundary;
7. record WebSocket running state;
8. move the journal to `preflight_verified` and then durably record `live_mutation_started=true`.

Once `live_mutation_started` is recorded, any process failure requires `--recover`; a fresh `--apply` is refused.

## Controlled code switch

The current installation layout is not a release-symlink layout, so replacing the entire application root would also replace installation-specific state such as `.env` and mutable storage. Instead the updater creates a private sibling scratch directory on the same filesystem and prepares release-owned top-level entries there.

The switch uses filesystem `rename()` for those top-level release entries. It does not copy files one-by-one over the running tree.

The following installation/mutable roots are preserved rather than replaced:

- `.env` and `.env.*`;
- `.git`;
- `vendor` (legacy/excluded; the 1.0 runtime itself is vendor-free);
- `cache`;
- `compile`;
- `uploads`;
- `notes-private-storage`;
- `.logs`.

Configured mutable paths such as `PRIVATE_STORAGE_PATH`, upload locations, updater state/staging/backup/release roots, log path and WebSocket PID path are checked before apply. If a mutable path is nested under a release-owned top-level directory, apply fails before mutation because such a layout cannot be switched safely.

Scratch containers remain private (`0700`), but directories that are promoted into live runtime are created as `0755`; file modes are retained from the verified candidate/snapshot. A private `0700` backup directory therefore cannot accidentally make the restored application inaccessible to the web/PHP service account.

## Apply sequence

After the destructive boundary:

```text
live_mutation_started
  -> code_switched
  -> migrations_applied
  -> postcheck_verified
  -> committed
```

The operational sequence is:

```text
controlled code switch
-> live bin/migrate.php
-> live healthcheck
-> exact target Version.php verification
-> bin/migrate.php --status
-> WebSocket restart if it was running before the switch
-> committed
-> maintenance release
```

`committed` is a terminal success state. If removing the maintenance marker fails after commit, the updater does **not** roll back a healthy committed release. It reports `maintenance_release_failed` and leaves maintenance active; the operator reruns `--recover`, which re-verifies the committed version, health and migration status before releasing maintenance.

## Automatic rollback

Any failure after `live_mutation_started` but before `committed` starts rollback while maintenance stays active:

```text
rollback_started
  -> code_restored
  -> database_restored
  -> rollback_verified
```

After the destructive boundary the verified pre-update backup is the authoritative recovery artifact. The release candidate is **not** required for rollback and may already have been deleted or damaged. Rollback performs the following:

1. re-verifies the external rollback backup, including the code manifest/files and MySQL dump metadata;
2. enumerates the current live release-owned top-level entries, quarantines the failed release tree, and restores code from the verified pre-update snapshot while leaving `.env` and preserved mutable roots untouched;
3. restores MySQL from the verified consistent snapshot, including removal of objects introduced by a failed migration;
4. verifies the exact pre-update `Version.php`;
5. runs the restored healthcheck;
6. runs restored `bin/migrate.php --status`;
7. restarts WebSocket if it was running before apply;
8. records `rollback_verified`;
9. only then releases maintenance.

Because mutable paths under release-owned top-level directories are rejected before apply, rollback can safely treat every non-preserved live top-level entry as release-owned. This allows target-only entries to be removed without consulting the candidate tree.

If any rollback step cannot be verified, the journal records `rollback_failed` where possible and maintenance remains active. Recovery artifacts are not deleted.

## Crash recovery

Recovery does not rely on the original PHP process surviving. Run:

```bash
php bin/update_apply.php \
  --transaction=update-2026-001 \
  --recover
```

Optional `--state-root` and `--backup-root` override the configured external updater locations.

The journal is the durable source of truth. Recovery is phase-aware:

- `backup_verified`, `candidate_verified`, `preflight_verified` with `live_mutation_started=false`: no live mutation occurred, so maintenance can be released;
- `live_mutation_started`, `code_switched`, `migrations_applied`, `postcheck_verified`: start rollback from the verified checkpoint;
- `rollback_started`: repeat/finish code restore, which is intentionally idempotent when the previous process died before the phase marker was written;
- `code_restored`: continue with database restore rather than trying to restart the state graph;
- `database_restored`: continue with restored-version/health/schema verification;
- `rollback_failed`: retry rollback from the verified checkpoint;
- `rollback_verified`: re-verify the restored installation and release maintenance;
- `committed`: re-verify the target installation and release maintenance.

If recovery itself fails, do not force maintenance off merely to reopen the UI. Inspect the external transaction journal and preserve the verified backup. The original candidate is useful for diagnostics but is not a rollback dependency after `live_mutation_started`.

## CLI

Apply a candidate already attached to the same verified updater transaction:

```bash
php bin/update_apply.php \
  --transaction=update-2026-001 \
  --candidate-dir=/var/lib/notes/update-releases/<candidate> \
  --apply
```

Machine-readable output:

```bash
php bin/update_apply.php ... --apply --json
```

Recovery:

```bash
php bin/update_apply.php \
  --transaction=update-2026-001 \
  --recover --json
```

The command intentionally requires maintenance to already be active and owned by the same transaction. The earlier staging/backup/candidate commands remain separate checkpoints so an operator can inspect artifacts before crossing the destructive boundary.

Only one live apply/recover command may own a transaction at a time. If another process already holds the transaction operation lock, the command exits with `operation_busy` and makes no updater-state transition.

## WebSocket lifecycle

Before mutation the updater records whether the native WebSocket process is running. If it was running, a successful apply or rollback uses:

```bash
php ws_server/server.php restart -d
```

and confirms `status` afterwards. Daemon restart requires Unix `pcntl`. If WebSocket is running but the updater cannot safely restart it, apply fails **before** `live_mutation_started`. Installations managed by an external process supervisor may instead stop/drain the socket service through that supervisor before apply; the updater then records it as not running and does not invent a restart mechanism it cannot verify.

## Recovery artifacts and cleanup

The apply/rollback critical path deliberately does not delete:

- verified staged package;
- verified release candidate;
- verified rollback backup;
- transaction journal;
- sibling switch/rollback scratch retained after the operation.

Cleanup/retention is a separate post-commit maintenance concern. The rollback backup and transaction journal must not disappear merely because the update reached a terminal state. Candidate retention remains desirable for diagnostics/reproducibility, but rollback correctness does not depend on it after the destructive boundary.

## Required validation before merge/release

The live-apply gate must pass on supported PHP versions and real MySQL. It covers:

- candidate re-hashing;
- controlled release-owned code switch;
- preservation of `.env`, cache and uploads;
- rejection of mutable paths nested below release-owned roots;
- directory permission contract;
- transaction-scoped single-owner apply/recover locking;
- verified code rollback with the original candidate absent;
- removal/quarantine of target-only top-level entries from a failed release;
- complete MySQL rollback including removal of a failed-migration table;
- trigger/data restoration;
- journal transition and rollback retry contract;
- CLI destructive-boundary invariants.

A later release drill must still exercise a complete signed Beta4 -> 1.0 upgrade and an injected post-mutation failure against a real installed application before the 1.0 release gate is considered complete.
