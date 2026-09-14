# Workspace 0.12 — six-PR execution plan

The remaining 0.12 work is intentionally split into six independently reviewable branches/PRs:

1. `0.12-db-architecture` — canonical schema + compatibility upgrade contract.
2. `0.12-product-e2e` — Profile, Admin and fault-injection browser lifecycles.
3. `0.12-usability` — shared feedback/confirmation UX, fewer disruptive reloads and module usability improvements.
4. `0.12-findability` — server-side search, pagination and URL-preserved sorting/filtering for Notes, Tasks and Admin users.
5. `0.12-governance` — release/browser checks and documented review/merge policy.
6. `0.12-release-alpha` — final 0.12 usable-alpha release closure after the preceding contracts land.

All six branches start from the same `master` baseline so review scope stays isolated. The release branch is a final integration/release gate and must be refreshed after the first five PRs merge.
