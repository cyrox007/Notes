# Workspace Organizer 0.14 — Core Security & Module Boundary Audit

Status: first-pass architectural audit baseline for 0.14 beta-hardening.

## Goal

0.14 must turn the current application core into a minimal, fail-closed platform kernel that can safely load, disable, update, license and compose independent functional modules. The target is not cosmetic modularity: disabling or quarantining a module must remove its executable surface from HTTP, WebSocket, scheduled/background and asset loading paths without destabilizing the core.

This document records concrete findings from the current 0.13 baseline and the required remediation order.

## Scope of this pass

Reviewed core bootstrap, routing, request handling, database manager, current routing composition and package autoload metadata. Follow-up passes must still cover controllers/services, WebSocket dispatch, installer/migrations, filesystem, crypto/secrets, templates/CSP, authentication/session lifecycle, update transport and licensing.

## Confirmed architectural findings

### A-01 — Application modules are recursively executable at bootstrap

`core.php` recursively loads every PHP file under:

- `app/models/`
- `app/services/`
- `app/controllers/`
- `app/socket/`
- `app/handlers/`
- `app/middlewares/`

There is no manifest, registry state, compatibility check, signature/integrity check or module enable/disable gate before these files are executed.

Impact:

- a disabled/incompatible module cannot be guaranteed inert;
- one broken PHP file can break the entire application bootstrap;
- package composition cannot safely exclude a module except by physically removing files;
- module quarantine is impossible before code loading;
- update/licensing decisions happen too late if module code has already executed.

Required remediation:

1. replace recursive include with explicit autoload + module discovery of **data-only manifests**;
2. validate manifests before loading module executable code;
3. build a `ModuleRegistry` with states (`installed`, `enabled`, `disabled`, `incompatible`, `degraded`, `quarantined`);
4. load module providers only for modules that pass integrity, compatibility, dependency and entitlement gates;
5. keep the bootstrap itself independent of functional modules.

Severity: **architecture blocker for modular beta**.

### A-02 — Central router owns every functional module

`core/routerConfig.php` imports Notes, Tasks, Profile, Files, Messenger and Admin controllers/middlewares and registers their HTTP routes in one global file.

Impact:

- module removal requires editing core-owned route configuration;
- route existence is not coupled to module registry state;
- a disabled module may still leave HTTP entry points unless the central file changes;
- per-module capability/permission metadata cannot be derived from a module contract.

Required remediation:

- split platform/core routes from module routes;
- each enabled module exposes a route provider through its manifest/provider contract;
- registry validates unique route names and allowed prefixes before registration;
- core owns only core/auth/platform/admin-module-management endpoints.

Severity: **architecture blocker for composable packages**.

### A-03 — Composer autoload contract is absent

`composer.json` declares runtime dependencies but no project PSR-4 autoload mapping. The project relies on a custom path-derived autoloader plus explicit lowercase core includes.

Impact:

- namespace/filesystem casing assumptions are fragile on Linux;
- package boundaries cannot cleanly own namespaces;
- update/install tooling cannot validate class ownership reliably;
- recursive loading is retained partly to compensate for missing explicit autoload metadata.

Required remediation:

- introduce PSR-4 namespaces for `Core\\` and `App\\Modules\\...` (or equivalent final namespace scheme);
- preserve backward compatibility during a staged migration;
- add CI contract checking namespace/path casing and duplicate class ownership.

Severity: **P1 foundation**.

### S-01 — Request parsing mixes transport data with output encoding

`Core\\Request` recursively applies `htmlspecialchars()` to GET/POST/JSON/SERVER values. HTML escaping is output-context encoding, not input validation.

Impact:

- input semantics are mutated before domain validation;
- double-encoding and non-HTML-context mistakes become easier;
- signatures/tokens/search text may be changed unexpectedly;
- validation rules become harder to reason about because the transport object no longer contains raw request values.

Required remediation:

- `Request` returns raw typed transport values;
- domain validators normalize/validate explicitly;
- templates/renderers perform contextual output encoding;
- security regression tests prove existing XSS protections remain intact during migration.

Severity: **P1 security correctness**.

### S-02 — SQL diagnostic logging can include parameter fragments

`DatabaseManager::maskQuery()` substitutes bound values into a diagnostic SQL string and retains query log entries in memory/error logging. Values are truncated, but still expose fragments.

Potential sensitive categories include tokens, UIDs, search terms, email/user data and encrypted payload fragments.

Required remediation:

- production SQL logs must be metadata-only by default (query class/hash/template, duration, affected rows, correlation ID);
- no parameter values unless an explicit development-only redaction policy allows safe fields;
- sensitive-field denylist alone is insufficient because modules can introduce new secrets;
- add a contract test that known secrets never appear in DB logs.

Severity: **P1 data exposure hardening**.

### S-03 — Core singletons and mutable global process state complicate isolation

Router and DatabaseManager are global singletons and bootstrap relies on `SITEPATH`, environment globals and PHP superglobals. This is not immediately exploitable by itself, but weakens test isolation and makes module fault containment harder.

Required remediation:

- introduce a small application container/kernel context;
- pass registry/config/logger/db interfaces explicitly to module providers;
- do not expose unrestricted container/service-locator access to modules;
- define narrow capabilities instead of direct global singleton access.

Severity: **P1 architecture/security hardening**.

## Target core/module boundary

### Minimal core responsibilities

The core should contain only:

- bootstrap and trusted configuration loader;
- dependency injection/kernel context;
- Request/Response + Router primitives;
- session/authentication primitives and CSRF/security headers;
- database connection + transaction/migration coordination;
- module manifest parser, registry and lifecycle manager;
- update verification/transaction coordinator;
- license/entitlement verifier;
- logging/audit/health primitives;
- filesystem abstractions required by the platform;
- version/compatibility resolver.

Notes, Tasks, File Manager, Messenger and optional product features are modules, not core.

### Module package minimum contract

Every module must have a data-only manifest containing at least:

- immutable module ID;
- display name;
- semantic version;
- required core version range;
- dependency/conflict list;
- provider class name;
- route declaration/provider;
- migrations namespace/list;
- owned DB/storage namespaces;
- declared capabilities and required platform capabilities;
- permissions/settings schema;
- assets;
- healthcheck entry;
- license feature/edition mapping where applicable;
- package checksum/signature metadata outside the writable application trust domain.

Executable provider code is loaded only after manifest, package integrity, dependency and compatibility validation.

## Module isolation rules

1. A module does not directly read/write another module's tables unless the dependency contract explicitly grants a service/capability.
2. A module does not write outside its assigned private-storage namespace except through a core capability.
3. Disabled/quarantined modules register no routes, socket handlers, cron/background hooks or assets.
4. Module install/upgrade migrations are versioned and checksummed; already-applied migration content is immutable.
5. Core can boot into recovery/admin mode even if one optional module is corrupt or incompatible.
6. Module failure must be attributable in health/audit logs without exposing secrets.
7. Core updates cannot silently enable new module capabilities without manifest/compatibility review.

## Update-system security requirements

The updater is a privileged code-installation path and must be treated as a supply-chain boundary.

Mandatory properties:

- signed update metadata;
- signed package artifacts + SHA-256 (or stronger) digest verification;
- embedded/trusted **public** verification keys only; private signing keys never ship;
- rollback/replay protection through monotonically versioned metadata/release sequence;
- TLS verification is required but is not a substitute for package signature verification;
- preflight compatibility/dependency/disk/DB checks before mutation;
- maintenance lock for state-changing update phase;
- DB/storage backup/recovery checkpoint when required;
- staged extraction outside live code path;
- path traversal/symlink rejection while extracting packages;
- atomic/controlled switch to new files where platform permits;
- migration execution with fail-closed transaction/recovery contract;
- post-update health checks;
- recovery mode if the new core cannot complete normal bootstrap;
- append-only update audit history.

Critical core security updates must not be blocked solely because a commercial entitlement expired.

## Licensing security requirements

Licensing must restrict commercial entitlements without becoming a destructive dependency or a single remote kill switch.

Mandatory properties:

- centralized `EntitlementService`/`LicenseManager` contract;
- signed license payload verified locally with public keys;
- license binds explicitly to product/edition/features/modules and optional customer/installation identity;
- configurable offline/grace behavior;
- revocation/refresh state is auditable;
- expiration/revocation never deletes customer data;
- backup/export/recovery and installation security updates remain available;
- module code consumes an entitlement API instead of parsing license files directly;
- license server outage does not immediately disable a still-valid locally verified installation;
- signing key rotation supported through multiple trusted public key IDs.

## First implementation sequence

### PR 0.14-A — Core audit contract (this branch)

- freeze this audit baseline;
- add CI/static contracts preventing further recursive module coupling;
- define namespace/module manifest schemas;
- no behavior change yet.

### PR 0.14-B — Explicit autoload + Kernel skeleton

- PSR-4 or equivalent deterministic autoload;
- `Kernel`/container context;
- preserve legacy behavior behind compatibility adapter;
- no module disable yet.

### PR 0.14-C — ModuleManifest + ModuleRegistry

- data-only manifest parser/validator;
- registry states and dependency/core compatibility resolver;
- module inventory exposed to admin/health tooling;
- invalid module is quarantined before provider execution.

### PR 0.14-D — Route/provider extraction

- migrate Notes/Tasks/Files/Profile/Messenger route ownership out of central `routerConfig.php`;
- core routes stay central;
- module-disabled combination tests.

### PR 0.14-E — Storage/DB/capability boundaries

- explicit module namespaces and service contracts;
- cross-module access audit;
- failure isolation and ownership tests.

### PR 0.14-F — Update engine

- signed metadata/artifacts, compatibility resolver, staged install, recovery/rollback and audit log.

### PR 0.14-G — Licensing/entitlement engine

- signed offline-verifiable licenses;
- module/edition entitlements;
- safe expiry/revocation/grace rules;
- admin visibility and audit.

## Beta gate for this architecture

0.14 beta is not release-ready until CI proves at least:

- core boots with every optional functional module disabled;
- each supported package composition installs and upgrades cleanly;
- disabled/quarantined module exposes zero registered HTTP/socket surfaces;
- corrupt/incompatible module cannot break recovery-mode core boot;
- module dependency/conflict errors fail before code execution;
- update artifact signature failure leaves live installation untouched;
- interrupted update has deterministic recovery path;
- expired/invalid license does not destroy/block access to customer backup/recovery data;
- core security update path remains available according to the security-update policy;
- no application secret/request token appears in production DB/query logs;
- existing 0.13 browser/security/storage/WSS regression suite stays green.

## Audit status

This is a first-pass core boundary audit, not the final security verdict. The next pass must inspect every privileged subsystem and produce a finding ledger with severity, exploitability/preconditions, affected paths, remediation commit and regression test. No Critical/High finding may be silently deferred past beta without an explicit documented risk acceptance.
