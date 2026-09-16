# Notes module runtime ownership

This directory is being migrated from the shared legacy `app/*` runtime into the isolated module contract. Until the migration PR is complete, `module.json` must not be changed to `runtime.mode = isolated`.

Owned runtime surfaces:

- note list/create/edit/delete HTTP routes;
- share/unshare/public share routes;
- attachment upload/download/delete/shared-download routes;
- Notes controllers/services/models/policies;
- Notes views and module assets;
- `notes` private-storage namespace;
- notes schema/migration ownership metadata.

Cross-module dependencies must remain explicit platform/module contracts rather than direct inclusion of another module's internal files. Module-owned views/assets move behind the same isolated runtime boundary rather than remaining globally coupled through `app/views`.
