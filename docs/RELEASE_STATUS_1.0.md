# Workspace Organizer 1.0 release task ledger

This file is the stop/reopen ledger for the 1.0 stabilization work. It separates **source implementation**, **operator-only release actions**, and the **final exact-head test phase** so a completed engineering item is not reopened merely because release evidence is still pending.

The historical audit IDs are retained for continuity. A source item is reopened only for a new reproducible violation of its acceptance contract.

| Audit item | Source state | Source evidence | Remaining release gate | Status before final test phase |
|---|---|---|---|---|
| R01 Files baseline | Implemented | Files module quota asset/runtime fixes are merged; later Files lifecycle and HTTP workflows use module-owned paths | Exact-head browser/HTTP/durable regression | Source closed |
| R02 Profile migration | Implemented | PR #146 merged; Profile is isolated and its lifecycle workflow is current | Exact-head Profile lifecycle | Source closed |
| R03 module independence | Implemented | Admin/Messenger isolation, transitional loader removal, composition-aware DB ownership and Core recovery control plane are merged (#148–#152) | Exact-head composition/module regression | Source closed |
| R04 CI convergence | Implemented | Notes/Profile/Tasks/Files release coverage was updated; PR #164 aligns final `1.0 -> master` triggers, 32-table operations, Beta4 compatibility and fault injection | One final agreed release run on the frozen SHA | Source closed |
| R05 GitHub merge governance | Source implementation complete | Release governance contract plus `tools/release/apply-github-protection.sh` are merged (#156, #163, #164) | Owner/admin must apply and verify actual GitHub protection on `1.0` and `master` | Operator pending |
| R06 production trust roots | Tooling/runbook complete | Independent license/update trust ceremony and validation tooling are merged (#157) | Generate two independent offline production keypairs; commit **public keys only**; run canaries | Operator pending |
| R07 remaining 1.0 obligations | Implemented | Data-key rotation, nonce CSP, security observability, retention/permanent purge and cross-browser/load evidence harness are merged (#153, #154, #158–#160) | Exact-head evidence and operational acceptance | Source closed |
| R08 final release acceptance | Implemented | PR #161 adds release acceptance preflight/runbook and final source contract | Branch protection, trust roots, final CI/evidence, backup/restore, P0/P1 acceptance, signed immutable artifact and tag | Release phase pending |

## Current source freeze rule

Do not start the final release test phase while a new source task is still being added. Once R05 and R06 operator setup is complete, choose one exact `1.0` SHA as the release candidate and run the complete agreed evidence set on that SHA.

A failure during that phase must be classified before changing code:

1. product defect;
2. test/fixture defect;
3. CI environment defect;
4. stale workflow/base defect.

Only a reproducible product/source violation reopens the corresponding R item. Infrastructure failures do not turn already accepted architecture work back into an open design task.

## Operator boundary before final tests

Two release actions intentionally cannot be completed by repository code alone:

- apply the checked-in GitHub protection policy with an authenticated repository owner/admin;
- perform the production license/update Ed25519 key ceremony in controlled offline storage and commit only the public trust roots.

Private signing material must never be committed, uploaded to CI, included in the release bundle, installed on a customer system, or pasted into issue/chat logs.

## Final test phase

After the operator boundary is complete:

1. freeze the exact release-candidate SHA;
2. update the CI execution branch/runner configuration without changing product behavior;
3. run all release-relevant checks on that exact SHA;
4. perform backup/restore and production-style operational acceptance;
5. verify production trust canaries;
6. build the immutable bundle, record SHA-256, sign the update manifest and verify it;
7. merge the accepted content to `master`, create `v1.0.0`, and publish only if every required gate is satisfied.

See `docs/RELEASE_ACCEPTANCE.md`, `docs/RELEASE_GOVERNANCE.md`, and `docs/PRODUCTION_TRUST_CEREMONY.md` for the authoritative procedures.
