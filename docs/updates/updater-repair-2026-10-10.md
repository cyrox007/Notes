# Updater repair, 2026-10-10

## Incident and version audit

Local Notes 1.0.13 failed preparing 1.0.14 with
`Existing stage manifest does not match the requested update`.
An earlier interrupted update also reached `rollback_failed` on Windows with
an open `.index.php.update-old-*` file. Recovery was completed using the
verified runtime outside the live application. User data was restored from
a separate full database snapshot, because the updater SQL backup omitted dates.

| Defect | Published 1.0.14 | Published 1.0.15 | This patch |
| --- | --- | --- | --- |
| Same ZIP, renewed signed manifest collides with an old stage | Present | Present | Signed identity included in stage directory name |
| MySQL `DEFAULT_GENERATED` dates omitted from backup INSERTs | Present | Present | Only VIRTUAL/STORED GENERATED columns omitted |
| Windows open live entry can prevent complete code rollback | Observed on legacy runtime | Frozen external web runtime improves normal path; fallback still requires Windows acceptance testing | Not repaired by this narrow patch |

Audit sources: v1.0.14 (`8e661e9cbae621369a8293b124ae38dc28b9e718`),
v1.0.15 (`64071e610e5acb848dfba44d76e77c41f002cff4`).
The existing signed ZIPs, tags and release assets remain unchanged.
GitHub release 1.0.15 was promoted to an ordinary release at the user's request;
this does not certify all updater failure scenarios.

## Apply without installing a release

1. Make a full independent database and private-storage backup. Old updater SQL
   backups may already lack original timestamps; this patch cannot recover them.
2. Extract the repair archive outside the live directory. Pause update actions
   and do not apply while an update or recovery is running.
3. Run with the same PHP version as the application:

   ```text
   php repair-updater-stage.php --root=/path/to/notes --yes
   ```

The standalone CLI script supports 1.0.13, 1.0.14 and 1.0.15. It checks the exact
source fragments, lints both replacements before writing, saves the originals
outside the live directory, and replaces only `core/UpdatePackageStager.php`
and `core/UpdateBackupManager.php`. It leaves `.env`, database, private files,
installed version, signed packages, previous stage directories and journals intact.
Repeated invocation is safe. Unexpected source is rejected before modification.

Retry preparation of the signed 1.0.14 package through the administration page.
Before confirming installation, verify that the displayed target is **1.0.14**.
This patch does not pin the update feed or automatically install a release.
On Windows use the verified external CLI executor; do not use a live entrypoint
to recover a transaction that has already begun replacing files.

**Reapply after installing original 1.0.14 or 1.0.15:** installation replaces
the updater files with their published, unpatched versions. Until the Windows
rollback scenario is verified, successful preparation alone is not evidence of
safe installation or rollback.

## Distribution and regression coverage

The `Standalone updater repair` GitHub Actions workflow publishes a ZIP artifact
with SHA-256 checksums, separately from releases (90-day artifact retention).
It does not publish a new application version or change either release.

Regression tests exercise re-signing identical package bytes while preserving
the old stage, identical-request reuse, and backup/restore of explicit historical
timestamps alongside a stored computed column. MySQL 8 is required to expose the
`DEFAULT_GENERATED` metadata that originally caused the data defect.
