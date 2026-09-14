## Scope

<!-- What changes, and what intentionally does not? -->

## Release checklist

- [ ] The PR has one focused responsibility and no unrelated refactor.
- [ ] `Master release gate` and relevant module/browser workflows pass on the current head.
- [ ] Browser-impacting behavior is covered by an existing lifecycle test or this PR adds/updates one.
- [ ] Root install and `BASE_PATH=/workspace/` behavior were considered for URLs, redirects and assets.
- [ ] User-visible success is emitted only after durable DB/storage work succeeds.
- [ ] DB changes follow `docs/DB_ARCHITECTURE.md`: canonical `*_schema.sql` for current shape, compatibility upgrade SQL only for installed legacy shapes.
- [ ] Storage lifecycle changes preserve DB↔filesystem reconciliation/cleanup guarantees.
- [ ] Security-sensitive changes preserve `role + is_active`, CSRF and ownership/access checks.
- [ ] Documentation/changelog impact was considered.

## Review policy

- [ ] Branch is up to date with `master` before merge.
- [ ] If another qualified participant exists, at least one independent approval is present.
- [ ] No administrator bypass is planned for a failing required check.

## Verification

<!-- List concrete CI jobs, local commands or browser flows used. -->
