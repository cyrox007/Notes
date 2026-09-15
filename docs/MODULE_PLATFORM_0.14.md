# Workspace Organizer 0.14 — Module Platform Contract

This document defines the target contract for independently distributable product modules. Phase 1 introduced the manifest/registry control plane. Phase 5 adds persisted lifecycle state while current product code remains explicitly marked `runtime.mode = legacy`.

## Core versus module

The core owns only platform primitives: bootstrap, request/router primitives, authentication/session/security primitives, database transaction/migration coordination, module registry, update verification, entitlement verification, health/observability and shared UI shell contracts.

Product capabilities such as Notes, Tasks, File Manager, Messenger, Profile and Administration are modules. A module must not become part of the trusted core merely because its PHP files exist on disk.

## Manifest location

Each installed module has exactly one manifest:

```text
modules/<module-id>/module.json
```

The directory name and manifest `id` must match. Module IDs and capability IDs are restricted identifiers. The manifest is validated before product application code is loaded.

Current schema version: `1`.

Required fields:

- `id`, `name`, `version`;
- `core.min`, `core.max_exclusive`;
- `dependencies`;
- globally unique `capabilities`;
- `package.bundled`, `package.default_enabled`;
- `license.feature` (central entitlement key; not a local license implementation);
- `runtime.mode` (`legacy` or `isolated`);
- `storage_namespaces`.

## Fail-closed rules

Discovery/startup fails when:

- the modules root or manifest is missing/invalid;
- a module directory is a symlink at the discovery boundary;
- manifest schema/types/identifiers are invalid;
- directory and manifest IDs differ;
- a declared dependency is absent;
- dependencies contain a cycle;
- two modules claim the same capability;
- a persisted module that is not explicitly `uninstalled` disappears from disk;
- the lifecycle table is missing or contains invalid state.

A **valid** manifest whose core range does not include the running core is different from a malformed package. It remains registered, keeps its configured lifecycle intent and reconciles to effective state `incompatible`. It is never runtime-enabled while incompatible. This allows a later compatible core update to restore the prior configured state without silently forgetting operator intent.

## Composition

`ModuleRegistry::resolveComposition()` is manifest-only: it takes a requested package set, adds required dependencies and returns deterministic dependency-first order. Package/distribution planning therefore does not change because one installation has a module disabled.

`ModuleRegistry::enabledComposition()` is runtime-state-aware: it returns only modules whose persisted **effective** state is `enabled`. Disabled, incompatible, degraded, quarantined and uninstalled modules are never silently enabled to satisfy a dependency.

Target supported package forms include:

- core + Notes;
- core + Tasks;
- core + Files;
- core + Messenger;
- curated bundles;
- full Workspace;
- licensed/custom enterprise composition.

A package builder must use the same manifest resolver as installer/update preflight so distribution cannot create a composition with missing or cyclic dependencies.

## Persisted lifecycle

Lifecycle state is stored in `module_lifecycle`. The platform separates two concepts:

- `configured_state` — persisted operator/package intent;
- `effective_state` — what the running core can actually expose after compatibility and dependency reconciliation.

Configured states are:

- `discovered`;
- `installed`;
- `enabled`;
- `disabled`;
- `degraded`;
- `quarantined`;
- `uninstalled`.

Effective state additionally includes `incompatible`.

Bundled modules are registered on first reconciliation using `package.default_enabled`. A newly discovered **non-bundled** package is always registered as `discovered`; `default_enabled` cannot self-activate third-party code.

When a configured `enabled` module loses an enabled dependency, its effective state becomes `degraded` while configured intent remains `enabled`. When compatibility/dependencies recover, reconciliation can return it to `enabled` without inventing new operator intent.

Lifecycle transitions are constrained. Enabling requires all dependencies to be effectively enabled and core-compatible. Disabling, quarantining or uninstalling a module is rejected while another effectively enabled module depends on it. Quarantine recovery requires an explicit transition to `disabled` before re-enabling.

Disabling and uninstall state changes are non-destructive: they do not purge customer data. Physical package removal, data retention/purge and signed update recovery remain separate explicit operations.

`manifest_hash` records the currently observed local manifest identity. Reconciliation may update it when deployed package contents change. It is **not** a publisher signature or authorization to execute downloaded code.

## Current runtime boundary

Phase 5 persists and reconciles lifecycle state before recursive `app/*` loading. The registry now exposes the effective runtime composition, but current modules are still `runtime.mode = legacy`; their PHP files/routes are not yet physically isolated by lifecycle state.

Therefore `disabled`/`quarantined` state is a control-plane contract in this phase, not a claim that all legacy code has already stopped being loaded. The next phase moves module-owned route/bootstrap providers behind `enabledComposition()` and proves isolation with a reference module.

## Isolation target

An `isolated` module will own its:

- route provider;
- controllers/services/domain models;
- migrations and schema ownership metadata;
- assets/templates;
- storage namespace;
- permissions/capabilities;
- healthcheck;
- update metadata;
- lifecycle hooks.

Cross-module access must go through a declared contract/capability/service. Direct writes into another module's tables/storage are not a supported integration boundary.

## Package integrity and signatures

The SHA-256 hash exposed by the manifest/lifecycle registry represents local manifest content identity only. It is **not** a cryptographic publisher signature.

Before remote installation/update is enabled, the platform must verify signed release metadata and package contents using trusted public verification keys. Package code must never execute before signature/integrity/compatibility verification. Private signing keys must never be distributed with customer installations.

## Licensing boundary

`license.feature` is only an entitlement identifier. Modules do not implement independent license checks. A later `LicenseManager/EntitlementService` will evaluate a signed package entitlement and expose a central capability decision.

License failure may disable commercial capability according to policy, but must never destroy customer data, prevent backup/recovery, or turn security updates into an unsafe state.

## Migration rule

Existing modules remain `legacy` until their complete runtime boundary moves behind the module contract. Changing the manifest to `isolated` without route/bootstrap/storage/migration ownership and regression coverage is prohibited.
