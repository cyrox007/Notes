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
- owns registration of its routes;
- exports concrete service objects for exactly the capabilities declared in `module.json`.

A module must not be switched from `legacy` to `isolated` until its product runtime files and routes have actually moved behind that entrypoint.

## Cross-module capabilities

Isolated modules do not import another module's internal PHP files or look up its classes by path. Cross-module services are discovered through `Core\ModuleCapabilityRegistry`.

Rules:

- capability identifiers are declared in the provider module manifest;
- the runtime provider must export exactly the same capability set;
- only modules in the effective runtime composition can provide capabilities;
- one active capability has exactly one active provider; duplicate registration fails closed instead of depending on boot order;
- after module boot the registry is sealed and cannot be mutated during request dispatch;
- consumers request a capability service through `ModuleRuntimeLoader::getInstance()->capabilities()`;
- consumers may require an expected interface/class, and a type mismatch fails closed;
- the registry exposes provider ownership for diagnostics without exposing provider filesystem paths.

Example future integration:

```php
$player = ModuleRuntimeLoader::getInstance()
    ->capabilities()
    ->require('media.playback', MediaPlayback::class);
```

A Files/Notes/Messenger module can therefore use media playback without depending on the implementation module's internal controller/service/model layout.

The **registry itself is 1.0 platform infrastructure** because it removes a direct cross-module dependency pattern while modules are being isolated. The actual `media.playback` provider, codec/streaming implementation and player UI remain post-1.0 product work under the feature freeze.

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

Current `1.0` migration state after the Admin step:

- isolated: `notes`, `tasks`, `files`, `profile`, `admin`;
- remaining legacy bundled module: `messenger`.

Profile keeps only narrow compatibility view bridges in `app/views/profile_page/*.php` while its existing controllers retain the historical template names. Those bridges contain no Profile product UI/business logic: product views/assets and runtime code are owned by `modules/profile`, and the module owns its routes/capability. They can be removed later by changing the controller template identifiers to `@profile/*` without changing the runtime boundary.

## Per-module completion criteria

A migrated module:

- is physically rooted under `modules/<id>/`;
- has no product controller/service/model/view route ownership left in shared legacy locations;
- owns its route provider;
- owns its storage/schema/migration metadata;
- can be omitted from runtime composition without its entrypoint or routes loading;
- fails closed on a missing/escaping/invalid entrypoint;
- exports only capabilities declared in its manifest;
- uses capability contracts instead of direct access to another module's internals;
- passes module-specific HTTP/browser/data regression tests;
- remains compatible with updater/package composition and lifecycle reconciliation.

## 1.0 release gate

Before tagging 1.0:

- every bundled production module (`admin`, `files`, `messenger`, `notes`, `profile`, `tasks`) must report `runtime.mode = isolated`;
- no bundled module may depend on the transitional recursive product loader;
- disabling/removing a module must not require editing another module's internal files;
- duplicate or undeclared capability providers must fail closed;
- module package web roots remain non-public unless explicitly exposed through core routing/static asset policy.
