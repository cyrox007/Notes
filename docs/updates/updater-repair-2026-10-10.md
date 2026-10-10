# Full updater repair, 2026-10-10 (revision 2)

The original two-file repair fixed preparation and backup dates only. It did
not fix installation on Windows. **Use the full archive and `repair-updater.php`**
described below; the old two-file archive is superseded.

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
| Windows open live entry prevents complete code rollback | Legacy live HTTP path remains | External HTTP runtime is available, but legacy sources need the full bridge | Full runtime and browser runner transferred; native Windows install and forced rollback verified |
| Frozen 1.0.15 ownership requires future migration while inspecting 1.0.14 | Appears when overlaying a newer updater | Present in the published overlay | Ownership determined from inspected package version; missing required 1.0.15 migrations still rejected |

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
   php repair-updater.php --root=/path/to/notes --yes
   ```

The full CLI bridge supports exact versions 1.0.13, 1.0.14 and 1.0.15. It transfers
the complete updater dependency set, the migration command and browser runner,
including the external HTTP entry, signed staging identity, corrected SQL backups
and package version ownership. It refuses active maintenance, checks the complete
payload before copying, saves originals outside live and rolls file changes back
if installation fails. It leaves `.env`, database, private files,
installed version, signed packages, previous stage directories and journals intact.
Repeated invocation is supported. `core/Version.php` in the archive identifies
the source payload; the installer never copies it into the live application.
Never unpack the archive over the application directory.

Retry preparation of the signed 1.0.14 package through the administration page.
Before confirming installation, verify that the displayed target is **1.0.14**.
This patch does not pin the update feed or automatically install a release.
Reload the administration page after applying the bridge so the browser loads
the new runner. Installation continues at a generated `/update-continuations/`
HTTP entry using the verified runtime outside the live tree; `index.php` is not
held open by the request replacing application files.

**Reapply after installing original 1.0.14 or 1.0.15:** installation replaces
the updater files with their published, unpatched versions. Preparation alone
does not verify every installation failure scenario. Automatic recovery through a live boot entry
after an expired capability lease remains a separate acceptance scenario.

## Distribution and regression coverage

The `Standalone updater repair` GitHub Actions workflow publishes the full ZIP artifact
with SHA-256 checksums, separately from releases (90-day artifact retention).
It does not publish a new application version or change either release.

Regression tests exercise re-signing identical package bytes while preserving
the old stage, identical-request reuse, and backup/restore of explicit historical
timestamps alongside a stored computed column. MySQL 8 is required to expose the
`DEFAULT_GENERATED` metadata that originally caused the data defect.

Native Windows PHP 8.1 / MySQL 8 verification on 2026-10-10:

- Original signed 1.0.14 package installed from a bridged 1.0.13 copy: committed,
  resulting version 1.0.14, maintenance cleared.
- A required table was removed in an isolated test database after code switch:
  rollback restored code and database, resulting version 1.0.13, maintenance cleared.
- Full bridge applied twice to each 1.0.13/14/15 fixture: version and `.env`
  unchanged, complete dependency payload present; failed bridge installation
  restored original files.

Production release signing keys and user credentials are not part of this archive.
