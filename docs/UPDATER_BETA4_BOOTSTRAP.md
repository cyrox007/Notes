# Beta4 -> 1.0 trusted bootstrap update

`v0.14.0-beta.4` is a published release from before the signed updater runtime existed. The tag resolves to commit `743d9283f3bb4fca8f536ad133d542a7078af3a9`; that release does not contain `bin/update.php`, `UpdateManifestVerifier`, maintenance ownership, rollback backups, release candidates, or live apply/recovery.

For that reason the first supported upgrade from Beta4 to 1.0 cannot truthfully be a self-update started by the installed Beta4 tree. It requires one explicit trusted bootstrap step. After 1.0 is installed, normal future signed updates run from the installed updater runtime.

## Trust boundary

The bootstrap runner is `bin/update_bootstrap.php` from a separately trusted 1.0 release bundle extracted **outside** the live Beta4 application tree.

The runner:

- accepts no private signing key;
- trusts only public Ed25519 update keys in the runner's `config/update_trusted_keys.php`;
- requires the operator to pin the exact expected source version and version code;
- loads `Core\Version` from the live legacy installation, not from the runner, so the existing `updater_version_mismatch` protection remains bound to the source tree;
- reuses the production `UpdateManifestVerifier`, `UpdatePackageStager`, `UpdateArchiveInspector`, `MaintenanceModeService`, `UpdateTransactionJournal`, `UpdateBackupManager`, `UpdateReleaseCandidate`, and `UpdateApplyCommand` implementations;
- never writes a package directly over the live tree;
- keeps transaction journal, staging, rollback backup and release candidate outside the live application root.

The bootstrap runner is an orchestration boundary, not a second updater implementation.

## Required operator inputs

Before starting, obtain through the normal release channel:

1. the trusted 1.0 release/runner bundle;
2. the detached signed update manifest;
3. the detached manifest signature;
4. the exact ZIP package referenced by that signed manifest.

The production update public key must already be present in the trusted runner bundle. The private update-signing key must remain offline/vendor-side and is never supplied to the customer command.

Use external directories for all recovery artifacts. Example:

```bash
php /opt/workspace-1.0-bootstrap/bin/update_bootstrap.php \
  --app-root=/srv/workspace \
  --manifest=/opt/release/workspace-organizer.update.json \
  --signature=/opt/release/workspace-organizer.update.sig \
  --package=/opt/release/workspace-organizer-1.0.0.zip \
  --transaction=beta4-to-1-0-2026-001 \
  --expected-source-version=0.14.0-beta.4 \
  --expected-source-version-code=1404 \
  --stage-root=/var/lib/notes/update-staging \
  --state-root=/var/lib/notes/update-state \
  --backup-root=/var/lib/notes/update-backups \
  --candidate-root=/var/lib/notes/update-releases \
  --json
```

The command verifies the signed manifest/package before maintenance, then performs the same transaction pipeline used by 1.0:

`verify -> ZIP audit -> immutable external stage -> maintenance -> journal -> verified code/MySQL backup -> reverify stage -> external release candidate -> pre-health/migration dry-run -> live switch -> migrations -> post-health/version/schema verification -> commit -> maintenance off`

## Recovery

If the process dies after the destructive boundary, do not start a new transaction and do not delete recovery artifacts. Run recovery from the same trusted external runner and the same external state/backup roots:

```bash
php /opt/workspace-1.0-bootstrap/bin/update_bootstrap.php \
  --app-root=/srv/workspace \
  --transaction=beta4-to-1-0-2026-001 \
  --state-root=/var/lib/notes/update-state \
  --backup-root=/var/lib/notes/update-backups \
  --recover \
  --json
```

Recovery follows the durable journal. A transaction with failed/unverified rollback remains in maintenance; operators must not remove the marker simply to reopen the application.

## Release drill

`.github/workflows/beta4-upgrade-rollback-drill.yml` is the release gate for this boundary. It uses only ephemeral CI signing material and never production private keys.

The workflow proves all of the following against exact `v0.14.0-beta.4`:

- the published Beta4 tag/commit really lacks the signed updater runtime;
- two Beta4 installations are created through the real HTTP installer on MySQL;
- a current 1.0 candidate is packaged with drill target version `1.0.0 / 10000` and signed with an ephemeral Ed25519 update key;
- a normal transaction reaches `committed`, passes health/migration checks and preserves existing settings/data;
- a second signed candidate intentionally fails only at post-switch healthcheck, after migrations have run;
- automatic rollback restores Beta4 code and the pre-update MySQL snapshot, removes 1.0-only licensing state, reaches `rollback_verified`, and releases maintenance;
- an incorrect source-version pin is rejected before maintenance/backup creation.

The drill key is generated under `/tmp`, its public half is injected only into the CI runner copy of the trust registry, and the private key is deleted before either live transaction starts.
