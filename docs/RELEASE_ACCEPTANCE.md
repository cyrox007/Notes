# Workspace Organizer 1.0 final release acceptance

This document is the final cut checklist for `v1.0.0`. It deliberately separates repository correctness, CI evidence and operator-only actions. A release is accepted only on one exact commit; evidence from an older head does not transfer automatically.

## Release candidate identity

The accepted release candidate must report:

- version: `1.0.0`;
- version code: `10000`;
- status: `stable`;
- release branch: `1.0`;
- final release branch: `master`;
- tag: `v1.0.0`.

Record the exact 40-character commit SHA in the release notes before building the final bundle.

## Gate A — repository/source contract

Before final RC testing:

1. All intended 1.0 engineering PRs are merged into `1.0`.
2. No unrelated or unreviewed branches are merged for convenience.
3. `php bin/release_acceptance.php --json` reports no source-contract failures.
4. The release tree contains no private license/update signing keys.
5. README, CHANGELOG and `docs/releases/v1.0.0.md` describe the same version/status.
6. Migration manifest and module ownership metadata are current and historical applied SQL has not been rewritten.
7. The release-evidence harness is present before strict acceptance.

A `pending` result from the non-strict preflight is expected while external ceremony gates are still incomplete.

## Gate B — repository governance

Repository Settings are external to Git history. The repository owner/admin must verify:

- `1.0` is protected;
- pull requests are required;
- branch must be up to date before merge;
- the always-on `release-gate` is required;
- the final `master` policy requires the release gate plus Notes/Tasks/Files/Profile/Admin browser lifecycle and storage DB-failure checks listed in `.github/release-governance.json`;
- stale approvals are dismissed after new commits;
- force push and branch deletion are blocked;
- independent approval is required when another qualified reviewer exists.

Do not mark this gate complete merely because `.github/release-governance.json` is correct.

## Gate C — production trust roots

Follow `docs/PRODUCTION_TRUST_CEREMONY.md`.

Required evidence:

1. license and update signing use independent Ed25519 keypairs;
2. private keys exist only in controlled offline vendor storage;
3. only public keys are committed to `config/license_trusted_keys.php` and `config/update_trusted_keys.php`;
4. license canary verifies against the release candidate;
5. update-manifest canary verifies against the release candidate;
6. no private-key file or raw private key appears in Git, CI artifacts, release bundle or support/chat logs.

## Gate D — exact-head automated evidence

After the final source commit is frozen, run all release-relevant checks on that exact SHA.

Required evidence includes:

- Stable release gate;
- module isolation/runtime/database ownership;
- CSP;
- security observability;
- retention/permanent purge;
- data-key rotation;
- Notes/Tasks/Files/Profile/Admin browser lifecycle;
- HTTPS/WSS browser smoke;
- signed updater/staging/apply/backup/recovery;
- exact published `0.14.0-beta.4 -> 1.0.0` upgrade and rollback drill;
- hosting installer/package;
- cross-browser/mobile release evidence;
- authenticated load/soak release evidence.

No result from a previous commit may substitute for a failed, skipped or unrun check on the frozen release head.

## Gate E — operational acceptance

On a deployment representative of production:

1. create a fresh verified backup of MySQL + `PRIVATE_STORAGE_PATH`;
2. complete a restore drill into an isolated environment;
3. run `php bin/healthcheck.php --json`;
4. verify HTTPS and WSS through the production reverse proxy;
5. verify one encrypted Note and one encrypted Messenger message;
6. verify protected File Manager/Notes/Messenger media access;
7. review `php bin/observability.php --json` and retention preview;
8. confirm adequate DB/private-storage free space and writable external state paths.

## Gate F — defect acceptance

Before the release owner declares the RC accepted:

- no open P0/P1 data-loss defects;
- no open P0/P1 security defects;
- no unresolved release-blocking regression;
- any known lower-severity limitation is documented and consciously accepted.

Automated CI cannot fabricate this decision. If no representative human beta cohort was run, record that fact explicitly rather than claiming beta coverage.

## Gate G — immutable artifact and signing

Build the final upload-ready bundle from the exact accepted SHA. The `Build hosting package` workflow is deliberately **build-only**: for a manual pre-tag build, run it against the exact accepted commit/ref with `version=v1.0.0`. It verifies that version against `core/Version.php`, then stores the ZIP, its SHA-256 and the exact source SHA as one workflow artifact. It must not create or update a public GitHub Release before offline signing is complete.

Then:

1. download the exact workflow ZIP + checksum + source-SHA artifact; verify both the recorded bundle SHA-256 and source SHA against the accepted commit;
2. build the update manifest with the exact source commit/version/version-code;
3. sign the exact manifest bytes with the offline update-domain private key;
4. verify manifest signature and package hash with the public registry shipped in the bundle;
5. verify the bundle excludes `.env`, private storage, vendor signing tools, private signing keys and transient state;
6. do not modify/repack the ZIP after the recorded SHA-256 and signature are accepted.

## Gate H — final merge and tag

Only after Gates A-G are complete:

1. run the strict preflight with operator attestations:

```bash
php bin/release_acceptance.php --strict --json \
  --branch-protection-confirmed \
  --ci-green \
  --release-evidence-green \
  --backup-restore-current \
  --beta4-drill-green \
  --p0p1-clear \
  --trust-canaries-green \
  --artifact-signed
```

2. merge the exact accepted `1.0` head to `master` without introducing source changes;
3. verify `master` points at the intended release content;
4. create signed/annotated tag `v1.0.0` according to repository release policy;
5. publish the exact previously accepted ZIP together with the update manifest and detached signature; do not rebuild or repack the ZIP after signing;
6. verify the published download checksum and release metadata once more.

If any source change is required after RC acceptance, invalidate the previous exact-head evidence and repeat the affected gates on the new SHA.
