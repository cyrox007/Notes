# Database architecture

## Decision

Workspace Organizer does **not** use migrations as the canonical source of the database schema.

The authoritative database contract for a fresh installation is the set of schema files in `database/`:

1. `messenger_schema.sql`
2. `notes_schema.sql`
3. `file_manager_schema.sql`
4. `user_fields_schema.sql`
5. `tasks_schema.sql`
6. `settings_schema.sql`

`install.php` imports these schema files directly. A clean installation must be reproducible from these files without consulting upgrade history.

## Existing installations

SQL files currently stored in `database/migrations/` are retained as **compatibility upgrade scripts** for installations created by older versions of Workspace Organizer. They are not the canonical description of the current database.

`bin/migrate.php` is therefore an upgrade runner retained for backward compatibility. Its `schema_migrations` table is an implementation detail used to prevent an already-applied compatibility script from being applied twice and to detect edited upgrade scripts by checksum.

The application runtime must never depend on:

- the number of rows in `schema_migrations`;
- a particular historical sequence length;
- `schema_migrations` being present on a fresh installation;
- reconstructing the current schema by replaying every historical upgrade script.

The runtime depends only on the current schema contract.

## Rules for schema changes

### Fresh-install contract

Every schema change must first be reflected in the appropriate canonical `database/*_schema.sql` file. Fresh installations are validated by importing the canonical schema files into an empty database.

### Upgrade contract

If an existing supported installation needs ALTER/backfill/reconciliation work, add an explicit compatibility SQL upgrade script. The script must:

- preserve existing user data unless the change explicitly documents otherwise;
- validate ambiguous/incompatible legacy state and fail closed rather than guess;
- be safe to execute through the compatibility upgrade runner;
- end with the database matching the same contract produced by a fresh install.

### CI contract

CI verifies outcomes, not history length. Tests may verify that a required compatibility upgrade was recorded, but must not assert an exact total number of historical scripts.

Required checks are:

- canonical schema imports successfully into an empty database;
- required tables, columns, indexes, foreign keys and seed values match the current contract;
- supported legacy fixtures upgrade to that same contract without data loss;
- a second upgrade run performs no additional schema/data mutation;
- modified already-applied compatibility scripts are rejected by checksum protection.

## Terminology

Use **schema** for the authoritative current database definition and **upgrade script** for compatibility SQL that transforms an older supported installation.

The historical file/directory names `bin/migrate.php`, `database/migrations/` and `schema_migrations` remain temporarily for compatibility. New documentation and CI should describe their purpose as database upgrades, not as the primary schema architecture. Renaming/removing those compatibility names would itself require an upgrade/deprecation cycle and is intentionally outside the 0.12 usable-baseline scope.
