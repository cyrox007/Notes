# 0.12 Database architecture audit

## Finding

Workspace Organizer historically provisions its database by importing canonical SQL schema files directly. The current `install.php` still follows that model and imports `database/*.sql`; it does not bootstrap the database by replaying `database/migrations/`.

The versioned runner `bin/migrate.php`, `database/migrations/` and the `schema_migrations` ledger were introduced later as compatibility tooling for upgrades from older database shapes. Treating the total number of those files/ledger rows as part of the application contract was architectural drift.

## 0.12 decision

For 0.12 the project uses:

- **canonical schema** — `database/*_schema.sql`, authoritative for a clean installation;
- **compatibility upgrade SQL** — historical scripts under `database/migrations/`, used only to bring supported older installations to the canonical contract;
- **upgrade ledger** — `schema_migrations`, retained only to prevent reapplication and protect checksums of already-applied upgrade scripts.

No runtime feature may depend on the history count.

## Audit changes

- documented the database architecture in `docs/DB_ARCHITECTURE.md`;
- kept existing compatibility SQL intact to avoid invalidating already-deployed upgrade history;
- changed installer/upgrade CI to assert schema outcomes and specific required upgrades rather than exactly `12` ledger rows;
- added an explicit fresh-install assertion that canonical schema import does not create `schema_migrations`;
- preserved checksum immutability and idempotent upgrade checks.

## Follow-up policy

New database work must update the canonical schema first. Add compatibility SQL only when an already-installed supported version requires ALTER/backfill/reconciliation work.

A future major release may rename the historical `migrations` paths and ledger, but doing so in 0.12 would create compatibility churn without improving the actual database contract.
