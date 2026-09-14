# Workspace 0.12 — execution status

## Working state

- Roadmap: `docs/USABLE_BASELINE_0.12.md`
- Working branch: `usable-baseline-0.12`
- Pull request: #62
- Branch is rebased onto `master` after PR #61 (storage quotas) was merged.

## Completed — data integrity foundation

- `DatabaseManager::commit()` rolls back and propagates the original error instead of returning a silent `false`.
- Existing File Manager upload/create-folder/rename callers therefore enter their error path instead of returning false success after a failed queued write.
- Registration can no longer redirect to login after a failed queued insert.
- `tests/integration/database_queue_integrity.php` checks visible DB errors, full rollback and the successful queued-write path.

## Completed — File Manager lifecycle

- `/files/delete/` is routed through `FileDeleteController` and `FileLifecycleService`.
- File/folder metadata is soft-deleted durably before any physical file removal is attempted.
- Folder deletion recursively soft-deletes the complete descendant tree by `parent_id`.
- Physical cleanup is best-effort after DB commit; cleanup failures are reported as pending instead of rolling user-visible metadata back into an inconsistent active state.
- Managed-path validation prevents reconciliation/deletion from following storage paths outside configured private/legacy File Manager roots, including symlink escapes.
- `bin/reconcile_file_storage.php` reports active metadata with missing files, deleted metadata with leftover files and blocked paths; `--cleanup-deleted` removes safe leftovers.
- `tests/integration/file_lifecycle_integrity.php` covers recursive delete, physical cleanup, missing active files, deleted leftovers and outside-root protection.
- Master release gate now runs the queued-write and File Manager lifecycle integration contracts.

## Repository event handled

PR #61 was merged into `master` while this work was in progress. The #62 branch was reset to that new master and the integrity work was reapplied on top, preserving the merged quota routes/settings/schema. The fail-closed commit change now also closes the known PR #61 false-success upload blocker in the actual merged code path.

## Next slice

1. Audit every remaining queued-write caller and either remove the legacy queue API or make the fail-closed contract explicit everywhere.
2. Add a real HTTP multipart File Manager upload test through router + auth + CSRF + `StorageQuotaLimit` + controller.
3. Add quota concurrency/upload-vs-admin-update end-to-end coverage.
4. Show `used / quota / remaining` to ordinary users in File Manager.
5. Harden partial/legacy settings quota migration compatibility.
