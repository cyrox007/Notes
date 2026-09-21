# Windows / OSPanel release acceptance for 1.0.2

This checklist complements the automated `windows-latest` CI. Passing GitHub-hosted Windows proves PHP/filesystem/updater path compatibility on Windows, but it is **not** a substitute for the final OSPanel 5.2.2 acceptance on the actual target stack.

## Automated Windows gate

The workflow `.github/workflows/windows-hosting-compat.yml` runs on PHP 8.1 and PHP 8.3 and verifies:

- Windows drive-letter, slash/backslash, UNC and case-insensitive updater path boundaries;
- signed manifest/package staging;
- remote signed update delivery with an in-memory transport;
- external release-candidate extraction and re-verification;
- updater recovery-artifact retention;
- native view/module runtime contracts;
- release-package/deployment surface contracts;
- availability of `update_doctor.php`, `update_run.php` and `update_retention.php`.

No production signing secret is used by this gate.

## Final OSPanel 5.2.2 acceptance

Run this only on a disposable copy of the installation and database, or after making a verified backup. Do not use a production dataset for the intentional rollback drill.

### 1. Baseline

Start from the exact published `v1.0.1` package.

Record:

```powershell
php -r "require 'core/Version.php'; echo Core\Version::VERSION, PHP_EOL;"
php -r "require 'core/Version.php'; echo Core\Version::VERSION_CODE, PHP_EOL;"
php bin/healthcheck.php --json
```

Expected source identity:

```text
1.0.1
10001
```

Confirm the application works through the OSPanel hostname and that the database/private storage contain disposable test data that can be checked after the upgrade.

### 2. Prepare final 1.0.2 artifacts

Use only the final release artifacts:

- `workspace-organizer-v1.0.2.zip`;
- its published SHA-256;
- `update.json`;
- `update.sig`.

Verify the ZIP checksum before extracting a temporary runner.

The temporary 1.0.2 runner directory and all updater state directories must be outside the live 1.0.1 application tree. Example layout:

```text
D:\OSPanel\domains\notes.local
D:\OSPanel\update-runner\workspace-1.0.2
D:\OSPanel\private\notes\update-staging
D:\OSPanel\private\notes\update-state
D:\OSPanel\private\notes\update-backups
D:\OSPanel\private\notes\update-releases
```

### 3. Upgrade exact 1.0.1 through the trusted external bootstrap

Run the following from PowerShell using the PHP binary/environment selected by OSPanel (shown as one line intentionally, so no PowerShell continuation escaping is required):

```powershell
php D:\OSPanel\update-runner\workspace-1.0.2\bin\update_bootstrap.php --app-root="D:\OSPanel\domains\notes.local" --manifest="D:\OSPanel\update-release\update.json" --signature="D:\OSPanel\update-release\update.sig" --package="D:\OSPanel\update-release\workspace-organizer-v1.0.2.zip" --transaction=update-1-0-1-to-1-0-2 --expected-source-version=1.0.1 --expected-source-version-code=10001 --stage-root="D:\OSPanel\private\notes\update-staging" --state-root="D:\OSPanel\private\notes\update-state" --backup-root="D:\OSPanel\private\notes\update-backups" --candidate-root="D:\OSPanel\private\notes\update-releases" --json
```

The JSON result must report `committed`.

### 4. Post-upgrade checks

From the live application directory:

```powershell
php -r "require 'core/Version.php'; echo Core\Version::VERSION, PHP_EOL;"
php -r "require 'core/Version.php'; echo Core\Version::VERSION_CODE, PHP_EOL;"
php bin/healthcheck.php --json
php bin/migrate.php --status
php bin/update_doctor.php --json
php bin/update_retention.php --json
```

Expected identity:

```text
1.0.2
10002
```

Also verify through the browser:

- login still works;
- previously created Notes/Tasks/Files/Profile data is present;
- Admin -> Updates opens without PHP/HTTP errors;
- the application works under the configured OSPanel hostname/base path;
- no updater maintenance marker remains active after commit.

### 5. Rollback evidence

The automated Linux release drill forces a post-switch database mutation plus failed healthcheck and proves automatic code/database rollback to exact 1.0.1.

For final OSPanel evidence, repeat destructive rollback testing only on a disposable cloned application plus cloned database. Never intentionally inject a failed candidate into the primary local or production copy.

## Acceptance record

Record:

- OSPanel version;
- selected PHP version;
- source `v1.0.1` commit;
- final 1.0.2 release commit;
- ZIP SHA-256;
- update signing key ID reported by verification;
- bootstrap JSON result;
- post-upgrade healthcheck result;
- browser smoke-check result.

Only after this manual row is green should the release notes say that OSPanel 5.2.2 acceptance has passed.
