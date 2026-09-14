# Release governance

Workspace Organizer treats `master` as the release branch. Repository code defines and verifies the intended policy, while GitHub ruleset / branch-protection settings live outside Git history and must be enforced in repository Settings by a user with Administration permission.

## Required `master` protection

The target policy is:

1. Require a pull request before merging.
2. Require branches to be up to date before merging.
3. Require the status checks listed in `.github/release-governance.json`.
4. Dismiss stale pull-request approvals when new commits are pushed.
5. Block force pushes and branch deletion.
6. If the repository has another independent participant who can review changes, require one approving review. A PR author's own approval does not satisfy the independent-review requirement.

The baseline required status checks are:

- `release-gate`
- `notes-browser-lifecycle`
- `tasks-browser-lifecycle`
- `file-manager-browser-lifecycle`

The Product Browser E2E checks recorded by the policy are:

- `profile-browser-lifecycle`
- `admin-browser-lifecycle`
- `storage-db-failure`

All of these workflows now exist in the repository. Moving every recorded check into the actually enforced GitHub ruleset is part of beta release-governance hardening; source-level policy verification does not substitute for repository-side enforcement.

Do not require a status check before its workflow exists on `master`, otherwise GitHub can make every PR permanently unmergeable.

## Merge rule

A PR targeting `master` is release-eligible only when:

- all release-relevant checks pass on the current head;
- the branch is up to date with `master`;
- DB changes follow `docs/DB_ARCHITECTURE.md`;
- user-visible storage mutations do not report success before durable persistence;
- root and `BASE_PATH=/workspace/` behavior is not regressed;
- browser-impacting changes either extend an existing lifecycle test or explain why no lifecycle update is needed;
- an independent approval is present whenever another qualified reviewer exists.

Do not use administrator bypass to merge a red or stale PR for normal development. Emergency bypasses should be followed by a corrective PR and a written reason in the PR timeline.

## Why the policy is split between code and Settings

GitHub Actions and repository files cannot safely grant themselves Administration permission. The repository therefore stores the expected protection contract in `.github/release-governance.json` and validates the parts that are observable from source. Repository-side enforcement remains an explicit owner/admin operation and is tracked separately from source correctness.

## Verification

`tests/integration/release_governance_contract.php` checks that:

- the policy file is valid and names `master`;
- all currently recorded check IDs correspond to workflow job IDs present in the repository;
- the pull-request template contains the release/browser/database review prompts;
- the master release gate executes the governance contract itself.

This prevents policy documentation from silently drifting away from the workflows that are supposed to protect the release branch.
