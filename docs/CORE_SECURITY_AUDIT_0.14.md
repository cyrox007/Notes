# Workspace Organizer 0.14 — Core/Security Audit Ledger

Status: **active audit**. This file is the release evidence ledger for the 0.14 beta hardening cycle. It is intentionally not a one-time prose review: every security-sensitive surface must end in an explicit reviewed/fixed/regression-covered state before beta can be declared.

## Audit rule

A row is complete only when the relevant code has been reviewed against its threat model and any Critical/High finding is fixed with regression coverage. `reviewed` without evidence is not sufficient for beta.

Status values:

- `not-reviewed` — no 0.14 audit evidence yet;
- `reviewing` — audit in progress;
- `finding` — one or more unresolved findings;
- `fixed` — known finding fixed, regression test still pending or partial;
- `covered` — reviewed and protected by a repeatable contract/test where practical.

## Surface matrix

| Surface | Status | Required evidence |
|---|---|---|
| Bootstrap/autoload/startup errors | finding | explicit load boundary, no secret/path disclosure, fail-closed startup tests |
| Module discovery/manifest/registry | reviewing | schema validation, path confinement, dependency/cycle/core-version tests |
| Web-server/source/package exposure | fixed | production deny boundary mirrored by E2E router and regression coverage |
| Router/path parsing/redirects | not-reviewed | route parser fuzz/negative cases, safe redirects, method handling |
| Request parsing/input boundaries | not-reviewed | malformed JSON, oversized/nested input, raw-vs-escaped contract |
| Session/cookie lifecycle | not-reviewed | fixation, cookie flags, logout invalidation, concurrent session behavior |
| Authentication/registration | not-reviewed | brute force/rate limit, credential errors, invite lifecycle, password contract |
| Authorization/ACL/admin | not-reviewed | object-level access matrix, blocked/inactive revocation, privilege transitions |
| CSRF/origin/browser security | not-reviewed | unsafe methods, token rotation, SameSite/origin policy, CSP target |
| DatabaseManager/ORM | not-reviewed | injection boundaries, identifier allowlists, transaction/failure semantics |
| Migrations/installer/upgrade | not-reviewed | malicious/partial package behavior, checksum, interruption/recovery |
| Private storage/path handling | not-reviewed | traversal/symlink/race/MIME/ACL tests |
| File uploads/downloads | not-reviewed | parser abuse, size limits, executable content, quota/race behavior |
| Notes crypto | not-reviewed | key/AAD/error behavior, corruption/truncation, key rotation design |
| Messenger crypto/WSS | not-reviewed | ticket/origin/ACL/reconnect/replay/abuse boundaries |
| Secrets/config/proxy trust | not-reviewed | missing/weak secrets, forwarded headers, deployment fail-closed behavior |
| Dependencies/supply chain | not-reviewed | Composer audit plus package provenance/update signature design |
| Update manager | not-reviewed | signed metadata/package, downgrade protection, atomicity/recovery |
| License/entitlement manager | not-reviewed | signed entitlement, offline/grace behavior, tamper/failure behavior |
| Logging/observability | not-reviewed | no secret leakage, correlation IDs, audit events, alertable failures |
| Backup/restore/data retention | not-reviewed | restore drill, module disable/uninstall retention, purge semantics |
| Multi-node/concurrency | not-reviewed | locks/shared state/duplicate workers/idempotency |
| Browser/client JS boundaries | not-reviewed | DOM XSS, unsafe HTML, URL handling, CSP-compatible assets |

## Findings

### A14-001 — Bootstrap exception details exposed to HTTP client

**Severity:** Medium  
**State:** fixed in Phase 1; regression coverage to be added to the startup/security contract.

`index.php` logged the bootstrap exception and then passed the same exception message into the public HTML error page. Startup exceptions can contain filesystem/configuration details and occur exactly when normal application error handling is unavailable.

Phase 1 changes the public response to an opaque incident identifier while keeping the exception class/message in the server log. No stack trace or raw bootstrap exception is rendered to the browser.

### A14-002 — Implicit recursive `app/*` loading is an oversized execution boundary

**Severity:** Medium architectural/security risk  
**State:** finding / migration started.

`core.php` recursively requires PHP from models, services, controllers, socket handlers and middleware directories. The mechanism predates the modular distribution goal and makes package composition implicit: code presence on disk is effectively enough to join the runtime bootstrap.

This is not treated as a standalone remote-code-execution vulnerability: an attacker able to write arbitrary PHP into the application tree already has a strong primitive. It is nevertheless incompatible with signed module packages, deterministic composition, quarantine and license/update enforcement.

Phase 1 introduces validated module manifests and a fail-closed registry before legacy application loading. Later 0.14 phases must eliminate recursive product-module loading in favour of explicit isolated module bootstrap/routes.

### A14-003 — Current product modules have no cryptographically authenticated package identity

**Severity:** High for the future remote-update threat model; not yet an exposed remote-update vulnerability because an update channel is not implemented.  
**State:** finding / planned.

The Phase 1 manifest integrity hash detects manifest content identity inside the running installation but is **not** a package signature. Before any remote core/module update feature is enabled, 0.14 must verify signed release metadata and package content with a public verification key embedded in/trusted by the core. Private signing keys must never ship with the application.

### A14-004 — Central router and legacy runtime couple module availability to source presence

**Severity:** Architectural  
**State:** finding / planned.

`core/routerConfig.php` imports and registers every product controller centrally. A disabled/missing module therefore cannot yet disappear as a coherent capability. The module registry added in Phase 1 is metadata/control-plane groundwork only; route ownership will migrate to module-owned route providers in a separate PR.

### A14-005 — Module/source directories were not consistently modelled as non-public content

**Severity:** Medium  
**State:** fixed in Phase 1; browser/static regression coverage is provided by the existing E2E suites running through the hardened router.

The production Apache baseline already denied direct access to source/config directories, but the newly introduced `modules/` directory was not yet present in that deny list. In addition, `tests/e2e/router.php` served any existing repository file directly, so browser CI did not reproduce the production source boundary.

Phase 1 adds `modules` to the Apache private-source deny rule and makes the PHP E2E router deny the same source/package segments and sensitive root metadata before its static-file fast path. This prevents module manifests/future module code from being treated as browser assets and makes browser CI exercise the intended boundary.

## Phase 1 evidence

Phase 1 adds:

- `Core\\ModuleManifest` with strict JSON schema/type/identifier/core-version validation;
- `Core\\ModuleRegistry` with root confinement, symlink rejection at the module boundary, unique capability ownership, dependency existence checks and cycle rejection;
- deterministic dependency-aware composition resolution;
- explicit manifests for current Notes, Tasks, Files, Messenger, Profile and Administration product modules;
- explicit `runtime.mode = legacy` so the repository cannot pretend current modules are isolated before they actually are;
- dedicated `module-platform-contract` CI with real positive/negative registry fixtures;
- bootstrap validation of all module manifests before legacy application code is loaded;
- removal of raw bootstrap exception details from HTTP responses;
- Apache and browser-E2E denial of direct `modules/`/source-package access.

## Required next audit/implementation sequence

1. Harden Router/Request/session boundaries and add negative/fuzz-style contracts.
2. Introduce persisted module installation/lifecycle state (`installed/enabled/disabled/incompatible/degraded/quarantined`) and reconcile it with signed manifests.
3. Move route registration behind module providers; migrate one low-coupling module end-to-end as the reference isolated module.
4. Split module migrations/assets/storage/healthchecks into owned namespaces and enforce cross-module boundaries.
5. Implement signed package/update metadata and update transaction/recovery state machine.
6. Implement centralized signed entitlement verification after the module/update trust boundary is stable.
7. Complete the remaining audit matrix and close every Critical/High finding before `0.14.0-beta.1`.

## Beta blocking rule

`0.14.0-beta.1` must not be tagged while any audit row remains `not-reviewed`, or while any Critical/High finding remains unresolved. Medium findings require either a fix or an explicit documented risk acceptance with regression containment and a stable-target owner.
