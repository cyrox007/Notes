# Workspace Organizer 0.14 — Module Platform Contract

This document defines the target contract for independently distributable product modules. Phase 1 implements the manifest/registry control plane while current product code remains explicitly marked `runtime.mode = legacy`.

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

Startup/module resolution fails when:

- the modules root or manifest is missing/invalid;
- a module directory is a symlink at the discovery boundary;
- manifest schema/types/identifiers are invalid;
- directory and manifest IDs differ;
- a module is incompatible with the running core version;
- a declared dependency is absent;
- dependencies contain a cycle;
- two modules claim the same capability.

A malformed or incompatible package is therefore not silently loaded.

## Composition

`ModuleRegistry::resolveComposition()` takes a requested module set, adds required dependencies and returns deterministic dependency-first load order.

Phase 1 only computes composition. A later phase will connect it to persisted lifecycle state and runtime route/bootstrap loading.

Target supported package forms include:

- core + Notes;
- core + Tasks;
- core + Files;
- core + Messenger;
- curated bundles;
- full Workspace;
- licensed/custom enterprise composition.

A package builder must use the same resolver as runtime/installer checks so distribution cannot create a composition that runtime would reject.

## Runtime states (next phase)

The persisted registry will distinguish at least:

- `installed`;
- `enabled`;
- `disabled`;
- `incompatible`;
- `degraded`;
- `quarantined`;
- `update-pending` / `recovery-required` where applicable.

Disabling is non-destructive. Data purge/uninstall is a separate explicit operation with dependency and retention checks.

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

The SHA-256 hash exposed by Phase 1 represents local manifest content identity only. It is **not** a cryptographic publisher signature.

Before remote installation/update is enabled, the platform must verify signed release metadata and package contents using trusted public verification keys. Package code must never execute before signature/integrity/compatibility verification. Private signing keys must never be distributed with customer installations.

## Licensing boundary

`license.feature` is only an entitlement identifier. Modules do not implement independent license checks. A later `LicenseManager/EntitlementService` will evaluate a signed package entitlement and expose a central capability decision.

License failure may disable commercial capability according to policy, but must never destroy customer data, prevent backup/recovery, or turn security updates into an unsafe state.

## Migration rule

Existing modules remain `legacy` until their complete runtime boundary moves behind the module contract. Changing the manifest to `isolated` without route/bootstrap/storage/migration ownership and regression coverage is prohibited.
