# Workspace Organizer 0.12 usable-alpha release candidate

This branch is the final closure gate for the 0.12 Usable Baseline. It must not be merged while the readiness workflow is red.

## Prerequisite PRs

The release candidate depends on the preceding 0.12 work being present in `master`:

- #68 — DB architecture decision: canonical `*_schema.sql` + compatibility upgrade SQL. Already merged.
- #69 — Product Browser E2E: Profile, Admin and DB/storage fault injection.
- #70 — Usability: shared feedback, Notes draft protection and inline actions.
- #71 — Findability: bounded server-side search/pagination and URL state.
- #72 — Release governance: required-check policy and review contract.

The release branch intentionally does not duplicate those diffs. After the prerequisite PRs are merged, rebase this branch onto the new `master` and rerun `0.12 usable-alpha readiness`.

## Definition of Done evidence

The release is eligible only when all of the following are true in the same source tree:

1. Failed durable DB writes cannot produce a user-visible success response.
2. File storage mutations have a safe DB↔filesystem lifecycle and a reconciliation path.
3. Root and `/workspace/` installs pass the browser baseline.
4. Notes, Tasks, File Manager, Profile and Admin each have a real browser lifecycle.
5. Storage quota is exercised through a real upload flow.
6. Browser fault injection proves a real DB metadata failure does not leave false success or an orphan file.
7. Notes/Tasks/Admin list views have bounded server-side findability.
8. Shared usability feedback/draft protection/inline actions are integrated.
9. Release governance is represented by a machine-readable policy and checked by `Master release gate`.
10. `docs/USABLE_BASELINE_0.12.md` has no unchecked P1 items in the release scope.
11. Version, README and changelog all identify `0.12.0-alpha` with release date `2026-09-14`.

`tests/integration/usable_alpha_readiness.php` validates the source-level evidence and prints every missing prerequisite instead of returning a generic failure.

## Final release commit

Only after #69–#72 are merged and this branch is rebased:

- mark the completed 0.12 roadmap items as done;
- set `Core\\Version::VERSION` to `0.12.0-alpha`, `VERSION_CODE` to `1200`, and release date to `2026-09-14`;
- update the README release header;
- move the accumulated 0.12 changelog material from `Unreleased` into `## 0.12.0-alpha — 2026-09-14`;
- run the complete PR matrix including `Master release gate` and `0.12 usable-alpha readiness`.

No tag or GitHub Release should be created from this PR until all required checks are green on the final head.
