#!/usr/bin/env bash
set -Eeuo pipefail

DB_HOST="${DBHOST:-127.0.0.1}"
DB_PORT="${DBPORT:-3306}"
DB_USER="${DBUSER:-root}"
DB_PASS="${DBPASS:-root}"
POSITIVE_DB="${LEGACY_STORAGE_POSITIVE_DB:-storage_legacy_reconcile_test}"
NEGATIVE_DB="${LEGACY_STORAGE_NEGATIVE_DB:-storage_legacy_reject_test}"

mysql_root=(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "-p${DB_PASS}")

cleanup() {
  set +e
  "${mysql_root[@]}" -e "DROP DATABASE IF EXISTS \`${POSITIVE_DB}\`; DROP DATABASE IF EXISTS \`${NEGATIVE_DB}\`;" >/dev/null 2>&1 || true
}
trap cleanup EXIT

checkpoint() {
  echo "[legacy-storage] $*"
}

create_database() {
  local db="$1"
  "${mysql_root[@]}" -e "DROP DATABASE IF EXISTS \`${db}\`; CREATE DATABASE \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
}

mysql_db() {
  local db="$1"
  shift
  mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "-p${DB_PASS}" "$db" "$@"
}

seed_canonical_non_storage_schema() {
  local db="$1"
  mysql_db "$db" < database/messenger_schema.sql
  mysql_db "$db" < database/notes_schema.sql
  mysql_db "$db" < database/file_manager_schema.sql
  mysql_db "$db" < database/user_fields_schema.sql
  mysql_db "$db" < database/tasks_schema.sql
}

seed_migration_history_before_storage() {
  local db="$1"
  mysql_db "$db" <<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(190) NOT NULL,
    checksum CHAR(64) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_schema_migrations_name (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL

  local migrations=(
    20260913_messenger_v2.sql
    20260913_user_contract_v2.sql
    20260913_messenger_membership_contract.sql
    20260913_messenger_attachments.sql
    20260913_messenger_delivery_receipts.sql
    20260913_messenger_reactions.sql
    20260913_messenger_saved_dialog.sql
    20260913_notes_private_attachments.sql
    20260913_user_fields_contract.sql
    20260913_tasks_contract.sql
  )

  local migration checksum
  for migration in "${migrations[@]}"; do
    checksum="$(sha256sum "database/migrations/${migration}" | awk '{print $1}')"
    mysql_db "$db" -e "INSERT INTO schema_migrations (migration,checksum) VALUES ('${migration}','${checksum}');"
  done
}

run_migrator() {
  local db="$1"
  DBHOST="$DB_HOST" DBPORT="$DB_PORT" DBUSER="$DB_USER" DBPASS="$DB_PASS" DBNAME="$db" php bin/migrate.php
}

checkpoint 'supported partial legacy schema is reconciled without losing values'
create_database "$POSITIVE_DB"
seed_canonical_non_storage_schema "$POSITIVE_DB"
seed_migration_history_before_storage "$POSITIVE_DB"

mysql_db "$POSITIVE_DB" <<'SQL'
CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_settings (setting_key,setting_value) VALUES
('file_manager_default_quota_bytes','2147483648'),
('legacy_banner','keep-me');

INSERT INTO users (uid,username,email,password_hash,firstname,lastname,role,is_active)
VALUES ('1e9a0000-0000-4000-8000-000000000001','legacy-storage-user','legacy-storage@example.test','not-used','Legacy','Storage',888,1);
SET @uid=(SELECT id FROM users WHERE username='legacy-storage-user');

CREATE TABLE user_storage_quotas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    quota_bytes BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO user_storage_quotas (user_id,quota_bytes) VALUES (@uid,3221225472);
SQL

run_migrator "$POSITIVE_DB" >/tmp/storage-legacy-positive.out 2>/tmp/storage-legacy-positive.err || {
  cat /tmp/storage-legacy-positive.out >&2 || true
  cat /tmp/storage-legacy-positive.err >&2 || true
  exit 1
}

# Existing administrator values and unrelated settings survive reconciliation.
test "$(mysql_db "$POSITIVE_DB" -N -e "SELECT setting_value FROM system_settings WHERE setting_key='file_manager_default_quota_bytes'")" = '2147483648'
test "$(mysql_db "$POSITIVE_DB" -N -e "SELECT setting_value FROM system_settings WHERE setting_key='legacy_banner'")" = 'keep-me'
test "$(mysql_db "$POSITIVE_DB" -N -e "SELECT quota_bytes FROM user_storage_quotas q JOIN users u ON u.id=q.user_id WHERE u.username='legacy-storage-user'")" = '3221225472'

# Canonical metadata was added/normalized.
test "$(mysql_db "$POSITIVE_DB" -N -e "SELECT CONCAT(setting_type,'|',category,'|',is_editable) FROM system_settings WHERE setting_key='file_manager_default_quota_bytes'")" = 'integer|file_manager|1'
test "$(mysql_db "$POSITIVE_DB" -N -e "SELECT CONCAT(setting_type,'|',category,'|',is_editable) FROM system_settings WHERE setting_key='legacy_banner'")" = 'string|general|1'
for column in setting_type category description is_editable created_at updated_at; do
  test "$(mysql_db "$POSITIVE_DB" -N -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_settings' AND column_name='${column}'")" = '1'
done
for column in created_at updated_at; do
  test "$(mysql_db "$POSITIVE_DB" -N -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='user_storage_quotas' AND column_name='${column}'")" = '1'
done

test "$(mysql_db "$POSITIVE_DB" -N -e "SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='system_settings' AND non_unique=0 AND column_name='setting_key'")" -ge 1
test "$(mysql_db "$POSITIVE_DB" -N -e "SELECT COUNT(DISTINCT index_name) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='user_storage_quotas' AND non_unique=0 AND column_name='user_id'")" -ge 1
test "$(mysql_db "$POSITIVE_DB" -N -e "SELECT COUNT(*) FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.constraint_schema=k.constraint_schema AND r.constraint_name=k.constraint_name WHERE k.table_schema=DATABASE() AND k.table_name='user_storage_quotas' AND k.column_name='user_id' AND k.referenced_table_name='users' AND k.referenced_column_name='id' AND r.delete_rule='CASCADE'")" = '1'

test "$(mysql_db "$POSITIVE_DB" -N -e "SELECT COUNT(*) FROM schema_migrations WHERE migration='20260914_storage_quota_legacy_reconcile.sql'")" = '1'
test "$(mysql_db "$POSITIVE_DB" -N -e "SELECT COUNT(*) FROM schema_migrations WHERE migration='20260913_system_settings_storage_quota.sql'")" = '1'

DBHOST="$DB_HOST" DBPORT="$DB_PORT" DBUSER="$DB_USER" DBPASS="$DB_PASS" DBNAME="$POSITIVE_DB" php bin/migrate.php --status \
  | grep -Fq 'Schema contract: OK'

checkpoint 'ambiguous duplicate legacy keys fail before any ALTER TABLE'
create_database "$NEGATIVE_DB"
seed_migration_history_before_storage "$NEGATIVE_DB"
mysql_db "$NEGATIVE_DB" <<'SQL'
CREATE TABLE system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO system_settings (setting_key,setting_value) VALUES
('duplicate-key','one'),
('duplicate-key','two');
SQL

set +e
run_migrator "$NEGATIVE_DB" >/tmp/storage-legacy-negative.out 2>/tmp/storage-legacy-negative.err
NEGATIVE_STATUS=$?
set -e
if [[ "$NEGATIVE_STATUS" -eq 0 ]]; then
  echo 'Ambiguous legacy schema unexpectedly migrated successfully' >&2
  cat /tmp/storage-legacy-negative.out >&2 || true
  exit 1
fi
grep -Fq 'Cannot reconcile duplicate system_settings.setting_key values' /tmp/storage-legacy-negative.err

# Prevalidation must happen before the first additive ALTER and the failed
# reconciliation must never be recorded as applied.
test "$(mysql_db "$NEGATIVE_DB" -N -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='system_settings' AND column_name='setting_type'")" = '0'
test "$(mysql_db "$NEGATIVE_DB" -N -e "SELECT COUNT(*) FROM schema_migrations WHERE migration='20260914_storage_quota_legacy_reconcile.sql'")" = '0'

echo 'Legacy storage quota migration reconciliation: OK'
