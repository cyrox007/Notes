#!/usr/bin/env bash
set -euo pipefail

MYSQL=(mysql -h 127.0.0.1 -uroot -proot -N -B)

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS role_policy_beta4; CREATE DATABASE role_policy_beta4 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"${MYSQL[@]}" role_policy_beta4 < database/messenger_schema.sql
"${MYSQL[@]}" role_policy_beta4 < database/access_control_schema.sql

TABLE_EXISTS=$("${MYSQL[@]}" role_policy_beta4 -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='role_module_policies';")
test "$TABLE_EXISTS" = "1"

POLICY_PK=$("${MYSQL[@]}" role_policy_beta4 -e "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='role_module_policies' AND INDEX_NAME='PRIMARY';")
test "$POLICY_PK" = "role_id,module_id,policy_key"

"${MYSQL[@]}" role_policy_beta4 <<'SQL'
INSERT INTO users (uid,username,email,password_hash,role,is_active,account_status) VALUES
('20000000-0000-4000-8000-000000000001','policy_superadmin','policy-super@example.test','x',1,1,'active'),
('20000000-0000-4000-8000-000000000002','policy_user','policy-user@example.test','x',888,1,'active');
SQL

test "$("${MYSQL[@]}" role_policy_beta4 -e "SELECT r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE u.username='policy_superadmin';")" = "superadmin"
test "$("${MYSQL[@]}" role_policy_beta4 -e "SELECT r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE u.username='policy_user';")" = "user"

export DBDRIVER=mysql DBHOST=127.0.0.1 DBPORT=3306 DBUSER=root DBPASS=root DBNAME=role_policy_beta4
php tests/integration/role_policy_runtime.php

# Prove compatibility migration can create the same contract on an already-RBAC database.
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS role_policy_upgrade; CREATE DATABASE role_policy_upgrade CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"${MYSQL[@]}" role_policy_upgrade < database/messenger_schema.sql
"${MYSQL[@]}" role_policy_upgrade < database/migrations/20260915_rbac_foundation.sql
"${MYSQL[@]}" role_policy_upgrade < database/migrations/20260915_role_module_policies.sql

test "$("${MYSQL[@]}" role_policy_upgrade -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='role_module_policies';")" = "1"

# The migration runner must know about the Beta 4 migration and final schema contract.
grep -Fq "20260915_role_module_policies.sql" bin/migrate.php
grep -Fq "'role_module_policies'" bin/migrate.php
grep -Fq "EnforceFileUploadPolicy::class" core/routerConfig.php
grep -Fq "EnforceFileFolderPolicy::class" core/routerConfig.php

echo "Beta 4 role policy schema/runtime contract OK"
