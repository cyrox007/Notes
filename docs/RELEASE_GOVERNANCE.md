# Release governance

Workspace Organizer treats `master` as the release branch and `1.0` as the stable release-candidate branch. Repository code defines and verifies the intended policy, but **GitHub branch-protection settings live outside Git history** and must be enforced in repository Settings by a user with Administration permission.

## Required `1.0` protection

The stabilization branch must be protected before the final 1.0 release ceremony:

1. Require a pull request before merging.
2. Require the branch to be up to date before merging.
3. Require the always-on `release-gate` status check.
4. Dismiss stale pull-request approvals when new commits are pushed.
5. Block force pushes and branch deletion.
6. If another independent participant can review changes, require one approving review.

Only checks that run on **every** pull request to `1.0` may be configured as required repository checks. Path-filtered workflows remain mandatory evidence when they run, but making them repository-required would deadlock unrelated pull requests that legitimately do not trigger them.

## Required `master` protection

The target policy is:

1. Require a pull request before merging.
2. Require branches to be up to date before merging.
3. Require the status checks listed in `.github/release-governance.json`.
4. Dismiss stale pull-request approvals when new commits are pushed.
5. Block force pushes and branch deletion.
6. If the repository has another independent participant who can review changes, require one approving review. A PR author's own approval does not satisfy the independent-review requirement.

The required status checks are:

- `release-gate`
- `notes-browser-lifecycle`
- `tasks-browser-lifecycle`
- `file-manager-browser-lifecycle`
- `profile-browser-lifecycle`
- `admin-browser-lifecycle`
- `storage-db-failure`

These seven checks are deliberately configured to run on every pull request to `master`; none uses a pull-request path filter. This prevents GitHub branch protection from waiting forever for a required check that never started. Other release-relevant workflows may remain path-filtered, but they are not configured as repository-required contexts.

Source-level policy verification does not substitute for repository-side enforcement; apply the checked-in policy with the owner/admin command below.

## Merge rule

A PR targeting `1.0` or `master` is release-eligible only when:

- all release-relevant checks pass on the current head;
- the branch is up to date with its target;
- DB changes follow `docs/DB_ARCHITECTURE.md`;
- user-visible storage mutations do not report success before durable persistence;
- root and `BASE_PATH=/workspace/` behavior is not regressed;
- browser-impacting changes either extend an existing lifecycle test or explain why no lifecycle update is needed;
- an independent approval is present whenever another qualified reviewer exists.

Do not use administrator bypass to merge a red or stale PR for normal development. Emergency bypasses should be followed by a corrective PR and a written reason in the PR timeline.

## Applying the repository-side protection

The repository includes `tools/release/apply-github-protection.sh` for the owner/admin to apply the checked-in policy through the authenticated GitHub CLI.

From a trusted checkout of the current `1.0` branch:

```bash
bash tools/release/apply-github-protection.sh cyrox007/Notes
```

The script:

- reads required check IDs from `.github/release-governance.json`;
- protects both `1.0` and `master`;
- requires branches to be current before merge;
- blocks force-push and deletion;
- dismisses stale reviews;
- enforces the policy for administrators as well;
- detects whether another direct collaborator with write/maintain/admin permission exists and requires one approval only in that case;
- prints the resulting GitHub protection state for verification.

The script changes GitHub repository settings only. It does not create, store, or modify credentials beyond using the already authenticated `gh` session.

## Why the policy is split between code and Settings

GitHub Actions and repository files cannot safely grant themselves Administration permission. The repository therefore stores the expected protection contract in `.github/release-governance.json` and validates the parts that are observable from source. Repository-side enforcement remains an explicit owner/admin operation and is tracked separately from source correctness.

## Verification

`tests/integration/release_governance_contract.php` checks that:

- the policy file is valid and names both `master` and `1.0`;
- `1.0` requires the always-on `release-gate`;
- all required check IDs correspond to workflow job IDs present in the repository;
- every required master check is always-on for pull requests and has no path filter;
- the release gate runs on pull requests to both `master` and `1.0`;
- the pull-request template contains the release/browser/database review prompts;
- the release gate executes the governance contract itself.

This prevents policy documentation from silently drifting away from the workflows that are supposed to protect the release branch.
