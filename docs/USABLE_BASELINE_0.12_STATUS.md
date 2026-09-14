# Workspace 0.12 — execution status

## Working state

- Roadmap: `docs/USABLE_BASELINE_0.12.md`.
- PR #61 (storage quotas/settings) is merged into `master`.
- PR #62 (data integrity + File Manager lifecycle) is merged into `master`.
- PR #63 (active sessions + BASE_PATH hardening) is merged into `master`.
- PR #64 (partial/legacy storage quota migration reconciliation) is merged into `master`.
- PR #65 (`usable-baseline-0.12-phase4`) contains the Notes product browser lifecycle and has a fully green 21-workflow PR matrix after the installer migration-count CI contract was updated for the reconciliation migration added by #64.
- PR #66 (`usable-baseline-0.12-phase5`) contains the Tasks product browser lifecycle, stacked separately on #65; its Tasks browser PR check is green.
- Current work branch: `usable-baseline-0.12-phase6` — complete File Manager product browser lifecycle, stacked separately on phase5.

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

## Completed — partial/legacy quota migration

- The already shipped `20260913_system_settings_storage_quota.sql` remains immutable, preserving applied-migration checksums.
- `20260914_storage_quota_legacy_reconcile.sql` runs before the canonical storage migration: it is a no-op on fresh installs and reconciles supported partial legacy tables on upgrades.
- Existing administrator-defined `file_manager_default_quota_bytes`, unrelated settings and per-user quota values are preserved.
- Missing typed-setting metadata, timestamps, single-user uniqueness and the `users(id) ON DELETE CASCADE` quota foreign key are added only after legacy core/data validation succeeds.
- Duplicate setting keys, duplicate quota rows, orphan quota rows, incompatible core columns and incompatible existing quota foreign keys fail closed rather than being guessed/coerced.
- `tests/integration/storage_quota_legacy_migration.sh` executes the real migration runner against MySQL 8.4 and verifies both supported reconciliation and ambiguous legacy rejection.

## P0 status

All planned 0.12 P0 items are implemented and covered by automated contracts.

## Completed — Notes product browser lifecycle

- Added a real Chromium lifecycle running the application from `/workspace/`: login → create note → edit encrypted text → reopen/decrypt → multipart attachment upload → authenticated download → public share → anonymous view/download → unshare → verify old link returns 404 → delete note.
- The browser gate rejects page errors, unexpected HTTP errors and requests that escape the configured `BASE_PATH`.
- The browser flow found two existing production render failures: inline CSS in both `notes_page/edit_view.tpl` and `notes_page/shared_view.tpl` was parsed as Smarty syntax. Both style blocks are now protected with `{literal}`.
- Notes attachment and share URLs are BASE_PATH-aware in templates, controllers and `NoteAttachmentModel`.
- Fixed the malformed `safeHeaderName()` regular expression that emitted a PHP warning while streaming downloaded attachments.
- Note deletion now retires note, attachment metadata and active share state in one DB transaction.
- Physical attachment bytes are deliberately retained in private storage after note soft-delete, matching the current retention/backup policy rather than unlinking data during the user-visible delete operation.
- `tests/e2e/notes-lifecycle.mjs` and `.github/workflows/notes-browser-lifecycle.yml` cover the complete product flow and durable post-delete state.
- PR #65 final head `2ad7ccf90c4bb6ff0114f1dba1b7515f7f62c59f` passes all 21 pull-request workflows.

## Completed — Tasks product browser lifecycle

- Added a real Chromium lifecycle under `/workspace/`: login → create task through the modal → edit title/description/status/priority → add a subtask through the real prompt-driven UI → complete the subtask → complete the main task → submit the normal GET sort form → delete through the real confirmation form.
- Fixed the remaining root-relative Tasks sort form action by generating it through the named `tasks` route; root-relative AJAX calls remain safe because the shared `fetch` wrapper normalizes them through `wspace.path()`.
- The browser contract rejects page errors, unexpected HTTP errors and same-origin requests escaping the configured `BASE_PATH`.
- The workflow verifies durable MySQL state after the browser flow: the deleted task remains soft-deleted with `status=completed` and `completed_at` populated, while the created subtask remains completed with its own completion timestamp.
- `tests/e2e/tasks-lifecycle.mjs` and `.github/workflows/tasks-browser-lifecycle.yml` cover the full task lifecycle.
- GitHub Actions run `34836367800` is fully green, including the real Chromium flow and durable Tasks state verification.

## Completed — File Manager product browser lifecycle

- Added a real Chromium lifecycle under `/workspace/`: login → create folder → enter folder → multipart upload → read-only text preview → authenticated download → rename → re-download with updated filename → browser-visible quota rejection → delete file → delete folder.
- The quota scenario uses the real minimum 10 MiB user quota and pre-seeded active usage, so the successful upload consumes genuine remaining capacity and the following upload is rejected by `StorageQuotaLimit` with HTTP 413 and the real user-facing message.
- The browser contract rejects page errors, unexpected HTTP errors and same-origin requests escaping `BASE_PATH`.
- Durable MySQL verification proves the folder/file metadata is soft-deleted, the rejected upload created no metadata row, active usage returns to the seeded baseline and the physical uploaded file is removed after delete.
- The browser flow exposed a production bug in `FileController::getFile()`: the download filename sanitization regex was malformed, emitted a PHP warning and collapsed `Content-Disposition` to `file.txt`. It is replaced with explicit CR/LF/quote/backslash sanitization.
- The workflow additionally scans PHP runtime logs and fails on warnings, fatal/parse errors or uncaught exceptions, preventing hidden download/runtime defects from passing just because HTTP bytes were returned.
- `tests/e2e/file-manager-lifecycle.mjs` and `.github/workflows/file-manager-browser-lifecycle.yml` cover the full product flow.
- GitHub Actions run `34837337161` is fully green, including Chromium, durable storage state and clean-runtime verification.

## Next — Product browser E2E

1. Profile lifecycle: edit profile → avatar upload/remove.
2. Admin lifecycle: user status → quota update.
3. Fault-injection browser coverage.
4. Then usability, pagination/findability and governance hardening passes.
