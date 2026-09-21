# Workspace 0.12 — статус выполнения

## Рабочее состояние

- Roadmap: `docs/USABLE_BASELINE_0.12.md`.
- PR #61 (storage quotas/settings) объединён в `master`.
- PR #62 (data integrity + lifecycle File Manager) объединён в `master`.
- PR #63 (active sessions + hardening BASE_PATH) объединён в `master`.
- PR #64 (reconciliation partial/legacy migration storage quota) объединён в `master`.
- PR #65 (`usable-baseline-0.12-phase4`) содержит product browser lifecycle Notes и имеет полностью зелёную матрицу из 21 workflow после обновления CI-контракта количества migrations installer под reconciliation migration из #64.
- PR #66 (`usable-baseline-0.12-phase5`) содержит product browser lifecycle Tasks и отдельно наслаивается на #65; его browser check Tasks зелёный.
- Текущая рабочая ветка: `usable-baseline-0.12-phase6` — завершение product browser lifecycle File Manager, отдельно поверх phase5.

## Завершено — фундамент целостности данных

- `DatabaseManager::commit()` выполняет rollback и пробрасывает исходную ошибку вместо тихого возврата `false`.
- Поэтому существующие callers File Manager upload/create-folder/rename переходят в error path, а не сообщают ложный success после неудачной queued write.
- Registration больше не может перенаправить на login после failed queued insert.
- `tests/integration/database_queue_integrity.php` проверяет видимые DB errors, полный rollback и successful queued-write path.
- `tests/integration/queued_write_callers.sh` перечисляет всех оставшихся production callers queued write и ломает CI при появлении нового unchecked caller.

## Завершено — lifecycle File Manager и корректность quota

- `/files/delete/` проходит через `FileDeleteController` и `FileLifecycleService`.
- Metadata файла/каталога надёжно soft-delete до попытки физического удаления bytes.
- Удаление folder рекурсивно soft-delete всё дерево descendants по `parent_id`.
- Physical cleanup выполняется best-effort после DB commit; ошибки cleanup сообщаются как pending, а не откатывают пользовательские metadata обратно в inconsistent active state.
- Managed-path validation не позволяет reconciliation/deletion следовать storage paths вне настроенных private/legacy roots File Manager, включая symlink escapes.
- `bin/reconcile_file_storage.php` сообщает об active metadata с отсутствующими files, deleted metadata с leftover files и blocked paths; `--cleanup-deleted` удаляет безопасные leftovers.
- `tests/integration/file_lifecycle_integrity.php` покрывает recursive delete, physical cleanup, missing active files, deleted leftovers и outside-root protection.
- Реальные HTTP multipart tests проверяют unauthenticated access, CSRF rejection, успешный upload, exact/overflow quota, concurrent sessions, cleanup после forced metadata DB failure и locking между upload и admin quota update.
- Обычные пользователи видят used, quota, remaining bytes и percentage в File Manager.

## Завершено — active sessions и BASE_PATH

- `LoginRequared` и `IsAdmin` повторно проверяют `role` и `is_active` на защищённых requests; disabled accounts теряют существующие sessions.
- Общая browser URL generation использует BASE_PATH-aware helper `wspace.path()` вместе с server-side route/base helpers.
- Shared shell, auth pages, profile, File Manager и затронутые Messenger media/group-avatar flows больше не предполагают установку в root.
- Chromium E2E запускает приложение из `/workspace/` и проходит login, Notes, Tasks, Profile, quota UI и реальные операции File Manager create/upload/delete до отрисовки Messenger.
- `tests/integration/active_session_http.sh` доказывает, что уже авторизованный пользователь перенаправляется на prefixed login route после перехода `is_active` в `0`.

## Завершено — partial/legacy quota migration

- Уже опубликованный `20260913_system_settings_storage_quota.sql` остаётся immutable, сохраняя checksums применённых migrations.
- `20260914_storage_quota_legacy_reconcile.sql` выполняется до canonical storage migration: на fresh install это no-op, а на upgrades он согласует поддерживаемые partial legacy tables.
- Существующие administrator-defined `file_manager_default_quota_bytes`, unrelated settings и per-user quota values сохраняются.
- Missing typed-setting metadata, timestamps, single-user uniqueness и quota foreign key `users(id) ON DELETE CASCADE` добавляются только после успешной validation legacy core/data.
- Duplicate setting keys, duplicate quota rows, orphan quota rows, incompatible core columns и incompatible existing quota foreign keys завершаются fail-closed, а не угадываются/coerce.
- `tests/integration/storage_quota_legacy_migration.sh` запускает real migration runner на MySQL 8.4 и проверяет как supported reconciliation, так и отказ от ambiguous legacy state.

## Статус P0

Все запланированные P0-задачи 0.12 реализованы и покрыты автоматизированными contracts.

## Завершено — product browser lifecycle Notes

- Добавлен реальный Chromium lifecycle из `/workspace/`: login → create note → edit encrypted text → reopen/decrypt → multipart attachment upload → authenticated download → public share → anonymous view/download → unshare → проверка, что старый link возвращает 404 → delete note.
- Browser gate отклоняет page errors, неожиданные HTTP errors и requests, выходящие за настроенный `BASE_PATH`.
- Browser flow обнаружил две существующие production render failures: inline CSS в `notes_page/edit_view.tpl` и `notes_page/shared_view.tpl` интерпретировался как Smarty syntax. Оба style blocks теперь защищены `{literal}`.
- URLs attachments/share Notes стали BASE_PATH-aware в templates, controllers и `NoteAttachmentModel`.
- Исправлено malformed regular expression в `safeHeaderName()`, которое создавало PHP warning при streaming downloaded attachments.
- Удаление Note теперь в одной DB transaction выводит из обращения note, attachment metadata и active share state.
- Physical bytes attachments намеренно сохраняются в private storage после soft-delete Note в соответствии с текущей retention/backup policy вместо unlink во время user-visible delete operation.
- `tests/e2e/notes-lifecycle.mjs` и `.github/workflows/notes-browser-lifecycle.yml` покрывают полный product flow и durable post-delete state.
- Финальный head PR #65 `2ad7ccf90c4bb6ff0114f1dba1b7515f7f62c59f` проходит все 21 pull-request workflows.

## Завершено — product browser lifecycle Tasks

- Добавлен реальный Chromium lifecycle в `/workspace/`: login → create task через modal → edit title/description/status/priority → add subtask через реальный prompt-driven UI → complete subtask → complete main task → submit normal GET sort form → delete через real confirmation form.
- Исправлен оставшийся root-relative action формы сортировки Tasks: теперь он генерируется через named route `tasks`; root-relative AJAX calls остаются безопасными, потому что общий wrapper `fetch` нормализует их через `wspace.path()`.
- Browser contract отклоняет page errors, неожиданные HTTP errors и same-origin requests, выходящие за настроенный `BASE_PATH`.
- Workflow проверяет durable MySQL state после browser flow: deleted task остаётся soft-deleted с `status=completed` и заполненным `completed_at`, а созданная subtask остаётся completed со своим timestamp completion.
- `tests/e2e/tasks-lifecycle.mjs` и `.github/workflows/tasks-browser-lifecycle.yml` покрывают полный lifecycle task.
- GitHub Actions run `34836367800` полностью зелёный, включая real Chromium flow и durable verification состояния Tasks.

## Завершено — product browser lifecycle File Manager

- Добавлен реальный Chromium lifecycle в `/workspace/`: login → create folder → enter folder → multipart upload → read-only text preview → authenticated download → rename → повторный download с обновлённым filename → browser-visible quota rejection → delete file → delete folder.
- Quota scenario использует реальный minimum 10 MiB user quota и заранее созданное active usage, поэтому успешный upload действительно расходует remaining capacity, а следующий upload отклоняется `StorageQuotaLimit` с HTTP 413 и реальным user-facing message.
- Browser contract отклоняет page errors, неожиданные HTTP errors и same-origin requests, выходящие за `BASE_PATH`.
- Durable MySQL verification доказывает, что metadata folder/file soft-deleted, rejected upload не создал metadata row, active usage вернулось к seeded baseline, а physical uploaded file удалён после delete.
- Browser flow обнаружил production bug в `FileController::getFile()`: regex sanitization download filename был malformed, создавал PHP warning и сводил `Content-Disposition` к `file.txt`. Он заменён на явную sanitization CR/LF/quote/backslash.
- Workflow дополнительно сканирует PHP runtime logs и падает на warnings, fatal/parse errors или uncaught exceptions, не позволяя скрытым download/runtime defects пройти только потому, что HTTP bytes были возвращены.
- `tests/e2e/file-manager-lifecycle.mjs` и `.github/workflows/file-manager-browser-lifecycle.yml` покрывают полный product flow.
- GitHub Actions run `34837337161` полностью зелёный, включая Chromium, durable storage state и clean-runtime verification.

## Далее — product browser E2E

1. Lifecycle Profile: edit profile → avatar upload/remove.
2. Lifecycle Admin: user status → quota update.
3. Browser coverage с fault injection.
4. Затем passes usability, pagination/findability и governance hardening.
