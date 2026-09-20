# Workspace Organizer 1.0 release task ledger

This file is the stop/reopen ledger for the 1.0 stabilization work. It separates **source implementation**, **operator-only release actions**, and the **final exact-head test phase** so a completed engineering item is not reopened merely because release evidence is still pending.

The historical audit IDs are retained for continuity. A source item is reopened only for a new reproducible violation of its acceptance contract.

| Audit item | Source state | Source evidence | Remaining release gate | Status before final test phase |
|---|---|---|---|---|
| R01 Files baseline | Implemented | Files module quota asset/runtime fixes are merged; later Files lifecycle and HTTP workflows use module-owned paths | Exact-head browser/HTTP/durable regression | Source closed |
| R02 Profile migration | Implemented | PR #146 merged; Profile is isolated and its lifecycle workflow is current | Exact-head Profile lifecycle | Source closed |
| R03 module independence | Implemented | Admin/Messenger isolation, transitional loader removal, composition-aware DB ownership and Core recovery control plane are merged (#148–#152) | Exact-head composition/module regression | Source closed |
| R04 CI convergence | Implemented | 1.0.0 source convergence completed; post-tag module onboarding is merged and 1.0.1 adds signed user-seat licensing with updated release contracts | Re-run the complete exact-head gate set on the final 1.0.1 SHA | Source closed; 1.0.1 exact-head rerun pending |
| R05 GitHub merge governance | Implemented; operator re-application required | Release governance contract and protection applicator are merged. Repository visibility was switched private/public during CI recovery, so protection/rulesets must be re-applied and verified before publication | Re-apply checked-in protection to `1.0` and `master`, verify required checks plus force-push/deletion denial, then keep it enabled through publication | Operator action pending |
| R06 production trust roots | Public roots committed | Independent production license/update public Ed25519 roots are present under separate immutable key IDs; release CI verifies the registries are non-empty and independent | Confirm offline private-key custody and complete license/update canaries without exposing private material | Operator canaries pending |
| R07 remaining 1.0 obligations | Implemented | Data-key rotation, nonce CSP, security observability, retention/permanent purge and cross-browser/load evidence harness are merged (#153, #154, #158–#160) | Exact-head evidence and operational acceptance | Source closed |
| R08 final release acceptance | 1.0.1 release preparation in progress | `v1.0.0` remains an immutable historical cut; the maintenance candidate is `1.0.1` / version code `10001`, adding module onboarding, signed `max_users` licensing, durable user-action audit and legacy template cleanup | Exact-head 1.0.1 CI/evidence, restored branch protection, backup/restore + P0/P1 acceptance, immutable 1.0.1 bundle, offline update signature, `v1.0.1` tag and GitHub Release | Final publication pending |

## Final source-convergence correction

A later repository-wide release-path audit found stale CI/package assumptions that were not visible in the original ledger closure. These are treated as concrete reproducible source violations rather than reopening completed architecture work.

The final source-convergence patch corrects:

- pre-isolation Messenger, Notes, Profile and Files paths still referenced by release-relevant workflows;
- legacy 27-table expectations that survived after the composition-aware 32-table contract;
- legacy readiness fixtures that still asserted historical source layout/version state instead of compatibility guarantees;
- the hosting package path after Messenger isolation;
- automatic public GitHub Release publication before the offline update-signing ceremony;
- release-candidate provenance by recording ZIP SHA-256 plus exact source SHA;
- customer bundle exposure of repository-only `tests/` and `tools/`, plus Apache denial of `config/`, `tests/`, `tools/` and direct `core.php` access;
- the branch-protection applicator status-context serialization bug found during its first live application.

No self-hosted runner migration is part of the 1.0 source candidate. Release workflows remain on GitHub-hosted runners unless explicitly changed by a later accepted source task.

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
2. run all release-relevant checks on that exact SHA using the checked-in GitHub-hosted workflow configuration;
3. perform backup/restore and production-style operational acceptance;
4. verify production trust canaries;
5. build the immutable bundle, verify recorded SHA-256/source SHA, sign the update manifest and verify it;
6. merge the accepted 1.0.1 content to `master`, create `v1.0.1`, and publish only if every required gate is satisfied.

See `docs/RELEASE_ACCEPTANCE.md`, `docs/RELEASE_GOVERNANCE.md`, and `docs/PRODUCTION_TRUST_CEREMONY.md` for the authoritative procedures.
