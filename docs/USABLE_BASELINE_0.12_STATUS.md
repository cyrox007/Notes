# Workspace 0.12 — execution status

## Working state

- Roadmap: `docs/USABLE_BASELINE_0.12.md`
- Parent data-integrity branch / PR: `usable-baseline-0.12` / #62.
- Active-session + BASE_PATH stack: `usable-baseline-0.12-phase2` / #63.
- Current migration-completion branch: `usable-baseline-0.12-phase3`.
- PR #61 (storage quotas/settings) is already merged into `master`; #62 was rebuilt on top of that merge before the later stacks were created.

## Completed — data integrity foundation

- `DatabaseManager::commit()` rolls back and propagates the original error instead of returning a silent `false`.
- Existing File Manager upload/create-folder/rename callers therefore enter their error path instead of returning false success after a failed queued write.
- Registration can no longer redirect to login after a failed queued insert.
- `tests/integration/database_queue_integrity.php` checks visible DB errors, full rollback and the successful queued-write path.
- `tests/integration/queued_write_callers.sh` enumerates every remaining production queued-write caller and fails CI if a new unchecked caller appears.

## Completed — File Manager lifecycle and quota correctness

- `/files/delete/` is routed through `FileDeleteController` and `FileLifecycleService`.
- File/folder metadata is soft-deleted durably before any physical file removal is attempted.
- Folder deletion recursively soft-deletes the complete descendant tree by `parent_id`.
- Physical cleanup is best-effort after DB commit; cleanup failures are reported as pending instead of rolling user-visible metadata back into an inconsistent active state.
- Managed-path validation prevents reconciliation/deletion from following storage paths outside configured private/legacy File Manager roots, including symlink escapes.
- `bin/reconcile_file_storage.php` reports active metadata with missing files, deleted metadata with leftover files and blocked paths; `--cleanup-deleted` removes safe leftovers.
- `tests/integration/file_lifecycle_integrity.php` covers recursive delete, physical cleanup, missing active files, deleted leftovers and outside-root protection.
- Real HTTP multipart coverage exercises unauthenticated access, CSRF rejection, successful upload, exact/overflow quota, concurrent sessions, forced metadata DB failure cleanup and upload-vs-admin-quota-update locking.
- Ordinary users see used, quota, remaining bytes and percentage in File Manager.

## Completed — active sessions and BASE_PATH

- `LoginRequared` and `IsAdmin` re-check `role` and `is_active` on protected requests; disabled accounts lose existing sessions.
- Shared browser URL generation uses a BASE_PATH-aware `wspace.path()` helper alongside server-side route/base helpers.
- Shared shell, auth pages, profile, File Manager and affected Messenger media/group-avatar flows no longer assume a root install.
- Chromium E2E runs the application from `/workspace/` and exercises login, Notes, Tasks, Profile, quota UI and real File Manager create/upload/delete operations before rendering Messenger.
- `tests/integration/active_session_http.sh` proves an already authenticated user is redirected to the prefixed login route after `is_active` becomes `0`.
- Phase2 GitHub Actions gate is green on `edb0ae942a7baf83a4a39140324f61569ac30f78`.

## Completed — partial/legacy quota migration

- The already shipped `20260913_system_settings_storage_quota.sql` is left byte-for-byte unchanged, preserving applied-migration checksums.
- `20260914_storage_quota_legacy_reconcile.sql` runs before the canonical storage migration in the manifest: it is a no-op on fresh installs and reconciles supported partial legacy tables on upgrades.
- Existing administrator-defined `file_manager_default_quota_bytes`, unrelated settings and per-user quota values are preserved.
- Missing typed-setting metadata, timestamps, single-user uniqueness and the `users(id) ON DELETE CASCADE` quota foreign key are added only after legacy core/data validation succeeds.
- Duplicate setting keys, duplicate quota rows, orphan quota rows, incompatible core columns and incompatible existing quota foreign keys fail closed rather than being guessed/coerced.
- `tests/integration/storage_quota_legacy_migration.sh` executes the real migration runner against MySQL 8.4, verifies value preservation and the final schema contract, and proves ambiguous duplicate keys fail before additive schema changes are recorded as applied.
- `File Manager HTTP integrity` run `34824601434` is fully green with legacy reconciliation plus all existing HTTP/quota/concurrency contracts.

## P0 status

All planned 0.12 P0 items are now implemented and covered by automated contracts. The next workstream is product-level browser lifecycle coverage required by the 0.12 Definition of Done.

## Next — Product browser E2E

1. Notes lifecycle: create → edit → attachment → share → delete.
2. Tasks lifecycle: create → edit → subtask → status → delete.
3. Complete File Manager lifecycle: preview/download → rename → delete → browser-visible quota error.
4. Profile lifecycle: edit profile → avatar upload/remove.
5. Admin lifecycle: user status → quota update.
6. Fault-injection browser coverage, then usability/pagination/governance passes.
