# Workspace Organizer 1.0.x release status ledger

> **Status update — 22 September 2026.** `v1.0.1` is the published baseline. The active maintenance candidate is `1.0.2` / version code `10002`. This file is the current stop/reopen ledger for the final 1.0.2 release ceremony; historical 1.0.0/1.0.1 source tasks stay closed unless a new reproducible regression violates their acceptance contract.

## Current release topology

- published baseline: `v1.0.1` at the exact published commit pinned by the upgrade drill;
- active source branch: `dev`;
- release-candidate promotion PR: #204, `dev -> 1.0`;
- final release target after acceptance: `master` + tag `v1.0.2`;
- feature work for `1.1.0` remains out of scope until the 1.0.2 release gates below are complete.

Do not freeze or sign an RC while another accepted 1.0.2 source change is still pending. Any source change after exact-head acceptance invalidates the affected evidence and requires the relevant gates to be repeated.

## Source convergence

The original 1.0 audit items are source-closed. Current 1.0.2 stabilization adds operational/release hardening rather than reopening that architecture work.

| Area | Current source state | Evidence / implementation | Remaining gate |
|---|---|---|---|
| Module isolation / Core control plane | Implemented | module platform/isolation, lifecycle, router/security and browser lifecycle gates are current | exact-head rerun after final source freeze |
| Signed updater / rollback | Implemented | unified operator flow, readiness doctor, remote delivery, external candidate, verified code+MySQL backup, automatic rollback/recovery and retention | final production-signed artifact ceremony + exact final upgrade acceptance |
| Exact 1.0.1 -> 1.0.2 upgrade | Implemented in CI | published `v1.0.1` is installed through the real installer; signed synthetic 1.0.2 success and forced post-switch DB/code rollback are exercised | repeat required operator acceptance on final immutable production-signed artifacts |
| Windows compatibility | Implemented in CI | PHP 8.1/8.3 Windows updater/path/runtime contracts | final manual OSPanel 5.2.2 acceptance on exact final artifacts |
| Realtime Messenger | Implemented | native WebSocket fast path + automatic authenticated HTTP long-poll fallback; shared DB revision bridge; fresh-ticket recovery; worker/session-lock hardening | final OSPanel/browser transport acceptance (#173) |
| WebSocket diagnostics | Implemented in current source line; final translation port pending acceptance | detailed startup preflight, CLI PHP diagnostics, bind-confirmed `[RUNNING]`, `ws_doctor`, single remote WS-node runbook | merge/accept the fresh Russian diagnostics port or explicitly exclude it before freeze |
| Release documentation | Updated for 1.0.2 | release notes, hosting/deployment/operations/production docs describe WebSocket-first + HTTP fallback and current 34-table install contract | keep synchronized with the final frozen source |
| Product visual system | Source implementation present | structural light/dark work and later UX fixes are in the 1.0 line | live visual/operator acceptance on exact final RC (#172) |

## Current manual/operator gates

### G1 — repository governance

Before publication, verify repository-side protection for `1.0` and `master` against `.github/release-governance.json` / `docs/RELEASE_GOVERNANCE.md`:

- PR-required merge flow;
- required always-on checks;
- up-to-date branch requirement;
- stale approval dismissal;
- force-push/deletion denial;
- independent approval when another qualified reviewer exists.

Repository settings live outside Git history. Source contracts cannot substitute for this verification. If the current GitHub integration cannot read branch-protection administration endpoints, record verification from an owner/admin `gh` session rather than inferring the state.

### G2 — final source freeze and exact-head CI

After all accepted 1.0.2 source PRs are merged:

1. record one exact `dev`/PR #204 head SHA as the final source candidate;
2. stop adding source changes;
3. run every release-relevant workflow on that exact head;
4. do not substitute an older green result for a failed/skipped/unrun check;
5. classify any failure as product defect, test/fixture defect, CI environment defect or stale workflow/base defect before changing source.

### G3 — manual visual acceptance (#172)

Repeat live visual/operator QA on the exact frozen RC/artifact. Cover:

- Home, Notes, Tasks, Files, Messenger, Profile and Admin;
- light, dark and system themes;
- compact laptop / narrow desktop layout;
- previously fixed Messenger composer and global unread/notification behavior;
- current Messenger connection states `WebSocket · в сети` and `Long Poll · резервный канал`.

CI/DOM checks are supporting evidence, not a replacement for this decision.

### G4 — OSPanel 5.2.2 realtime + upgrade acceptance (#173)

On the real target stack and exact final artifacts:

1. prove the local/browser WebSocket fast path reaches `101 Switching Protocols` + application `Authorized`;
2. verify normal message delivery with state `WebSocket · в сети`;
3. stop/block the WS endpoint and verify automatic `Long Poll · резервный канал` durable synchronization without reload;
4. restore WS and verify automatic return to `WebSocket · в сети`;
5. execute the final 1.0.1 -> 1.0.2 updater acceptance from `docs/WINDOWS_OSPANEL_ACCEPTANCE.md`;
6. record PHP/OSPanel version, exact source SHA, bundle SHA-256, signing key ID, update result and healthcheck result.

Previously recorded CLI smoke is useful history but does not replace this exact-final-artifact browser acceptance.

### G5 — backup / restore / operational acceptance

On a representative deployment:

- create a fresh verified MySQL + `PRIVATE_STORAGE_PATH` backup;
- complete an isolated restore drill;
- run `php bin/healthcheck.php --json`;
- verify protected Notes/Messenger/File Manager data;
- review observability and retention preview;
- confirm writable external updater/private state and adequate free space.

### G6 — production trust and immutable artifacts

Private signing material stays offline and must never be committed, uploaded to CI, included in a customer bundle or pasted into logs/chat.

For the exact accepted SHA:

1. build the final hosting ZIP once;
2. verify recorded source SHA + ZIP SHA-256;
3. build the update manifest for version `1.0.2` / code `10002`;
4. sign the exact manifest bytes with the offline update-domain private key;
5. verify package hash/signature with the public trust registry shipped in the bundle;
6. run production license/update trust canaries;
7. do not repack or otherwise modify the accepted ZIP after checksum/signature acceptance.

## Final acceptance command

Only after the preceding evidence exists, run the strict acceptance preflight with truthful operator attestations:

```bash
php bin/release_acceptance.php --strict --json \
  --branch-protection-confirmed \
  --ci-green \
  --release-evidence-green \
  --backup-restore-current \
  --operational-acceptance-green \
  --visual-acceptance-green \
  --ospanel-acceptance-green \
  --one-zero-one-drill-green \
  --p0p1-clear \
  --trust-canaries-green \
  --artifact-signed
```

Do not pass an attestation merely to make the command green.

## Final merge / publication sequence

After strict acceptance succeeds:

1. merge the exact accepted `dev -> 1.0` release-candidate content without introducing source changes;
2. verify the resulting `1.0` content is the accepted candidate;
3. promote that exact accepted content to `master` through the protected PR flow;
4. create the signed/annotated tag `v1.0.2`;
5. publish the exact previously accepted ZIP together with `update.json` and detached signature;
6. verify published checksum, source provenance, update feed and download metadata;
7. retain rollback/recovery evidence for the release window.

If source changes after any acceptance/signing step, invalidate the affected evidence and repeat the corresponding gates.

## Stop rule

Do not reopen completed 1.0 architecture tasks because of historical audit text. Reopen only a concrete reproducible violation of the current contract. Conversely, do not close #172, #173, governance verification or production signing by inference: those are explicit human/operator boundaries.

Authoritative procedures:

- `docs/RELEASE_ACCEPTANCE.md`
- `docs/RELEASE_GOVERNANCE.md`
- `docs/PRODUCTION_TRUST_CEREMONY.md`
- `docs/WINDOWS_OSPANEL_ACCEPTANCE.md`
- `docs/releases/v1.0.2.md`
