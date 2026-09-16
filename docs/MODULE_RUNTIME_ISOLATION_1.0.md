# Workspace Organizer 1.0 — Runtime Module Isolation

`module.json`/lifecycle alone is not runtime isolation. This document tracks the physical migration required before the 1.0 release gate can claim modularity.

## Runtime boundary

An isolated module declares:

```json
"runtime": {
  "mode": "isolated",
  "entrypoint": "runtime.php"
}
```

The entrypoint:

- must be a relative PHP path inside `modules/<id>/`;
- cannot traverse or escape the module root;
- is loaded only when the module belongs to the selected runtime composition;
- must return `Core\ModuleRuntimeProvider`;
- must report the same module ID as its manifest;
- boots in dependency-first order;
- owns registration of its routes.

A module must not be switched from `legacy` to `isolated` until its product runtime files and routes have actually moved behind that entrypoint.

## Transitional loader

`core.php` still contains a recursive `app/*` loader while bundled legacy modules exist. This is temporary compatibility infrastructure, not an accepted final boundary.

Migration of each module removes its owned controllers/services/models/socket handlers from shared `app/*`, moves its views/assets/runtime into `modules/<id>/`, removes its route block from `core/routerConfig.php`, then changes the manifest to `isolated`.

When the final bundled module is isolated, the recursive product loader is deleted. Shared authentication/session/security/platform primitives that remain core-owned are loaded explicitly as core/shared infrastructure.

## Migration order

1. Notes — reference implementation.
2. Tasks.
3. Files.
4. Profile.
5. Admin.
6. Messenger — last because its HTTP module boundary must converge with the native WebSocket runtime and protocol/origin hardening.

## Per-module completion criteria

A migrated module:

- is physically rooted under `modules/<id>/`;
- has no product controller/service/model/view route ownership left in shared legacy locations;
- owns its route provider;
- owns its storage/schema/migration metadata;
- can be omitted from runtime composition without its entrypoint or routes loading;
- fails closed on a missing/escaping/invalid entrypoint;
- uses declared contracts for cross-module integration;
- passes module-specific HTTP/browser/data regression tests;
- remains compatible with updater/package composition and lifecycle reconciliation.

## 1.0 release gate

Before tagging 1.0:

- every bundled production module (`admin`, `files`, `messenger`, `notes`, `profile`, `tasks`) must report `runtime.mode = isolated`;
- no bundled module may depend on the transitional recursive product loader;
- disabling/removing a module must not require editing another module's internal files;
- module package web roots remain non-public unless explicitly exposed through core routing/static asset policy.
