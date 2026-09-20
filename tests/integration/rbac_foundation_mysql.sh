#!/usr/bin/env bash
set -euo pipefail

MYSQL=(mysql -h 127.0.0.1 -uroot -proot -N -B)

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS rbac_fresh; CREATE DATABASE rbac_fresh CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
for schema in messenger_schema.sql notes_schema.sql file_manager_schema.sql user_fields_schema.sql tasks_schema.sql access_control_schema.sql settings_schema.sql; do
  "${MYSQL[@]}" rbac_fresh < "database/${schema}"
done

TABLE_COUNT=$("${MYSQL[@]}" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='rbac_fresh';")
test "$TABLE_COUNT" = "32"
test "$("${MYSQL[@]}" rbac_fresh -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='role_module_policies';")" = "1"
test "$("${MYSQL[@]}" rbac_fresh -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('task_boards','task_board_members','task_board_items','task_board_assignees');")" = "4"

ACCOUNT_COLUMN=$("${MYSQL[@]}" rbac_fresh -e "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='account_status' AND COLUMN_TYPE=\"enum('active','inactive','blocked')\";")
test "$ACCOUNT_COLUMN" = "1"

"${MYSQL[@]}" rbac_fresh <<'SQL'
INSERT INTO users (uid,username,email,password_hash,role,is_active,account_status) VALUES
('00000000-0000-4000-8000-000000000001','rbac_superadmin','rbac-super@example.test','x',1,1,'active'),
('00000000-0000-4000-8000-000000000002','rbac_admin','rbac-admin@example.test','x',111,1,'active'),
('00000000-0000-4000-8000-000000000003','rbac_user','rbac-user@example.test','x',888,1,'active'),
('00000000-0000-4000-8000-000000000004','rbac_blocked','rbac-blocked@example.test','x',888,1,'blocked'),
('00000000-0000-4000-8000-000000000005','rbac_inactive','rbac-inactive@example.test','x',888,0,'inactive');
SQL

ROLE_ROWS=$("${MYSQL[@]}" rbac_fresh -e "SELECT COUNT(*) FROM user_roles;")
test "$ROLE_ROWS" = "5"

test "$("${MYSQL[@]}" rbac_fresh -e "SELECT r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE u.username='rbac_superadmin';")" = "superadmin"
test "$("${MYSQL[@]}" rbac_fresh -e "SELECT r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE u.username='rbac_admin';")" = "admin"
test "$("${MYSQL[@]}" rbac_fresh -e "SELECT r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE u.username='rbac_user';")" = "user"

test "$("${MYSQL[@]}" rbac_fresh -e "SELECT COUNT(*) FROM permissions WHERE code IN ('admin.access','admin.users.manage','admin.settings.manage','admin.roles.manage','notes.use','tasks.use','files.use','messenger.use','profile.use');")" = "9"

test "$("${MYSQL[@]}" rbac_fresh -e "SELECT COUNT(*) FROM permissions p LEFT JOIN roles r ON 1=0 WHERE p.module_id NOT IN ('admin','notes','tasks','files','messenger','profile');")" = "0"

export DBDRIVER=mysql DBHOST=127.0.0.1 DBPORT=3306 DBUSER=root DBPASS=root DBNAME=rbac_fresh
php tests/integration/rbac_permission_runtime.php

# Legacy upgrade fixture. This intentionally contains only the user contract used
# by the RBAC migration so the migration cannot accidentally depend on product tables.
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS rbac_upgrade; CREATE DATABASE rbac_upgrade CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"${MYSQL[@]}" rbac_upgrade <<'SQL'
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  uid CHAR(36) NOT NULL,
  username VARCHAR(50) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role INT NOT NULL DEFAULT 888,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_uid(uid),
  UNIQUE KEY uq_users_username(username),
  UNIQUE KEY uq_users_email(email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO users (uid,username,email,password_hash,role,is_active) VALUES
('10000000-0000-4000-8000-000000000001','legacy_super','legacy-super@example.test','x',1,1),
('10000000-0000-4000-8000-000000000002','legacy_admin','legacy-admin@example.test','x',111,1),
('10000000-0000-4000-8000-000000000003','legacy_user','legacy-user@example.test','x',888,1),
('10000000-0000-4000-8000-000000000004','legacy_inactive','legacy-inactive@example.test','x',899,0),
('10000000-0000-4000-8000-000000000005','legacy_blocked','legacy-blocked@example.test','x',999,1);
SQL
"${MYSQL[@]}" rbac_upgrade < database/migrations/20260915_rbac_foundation.sql

test "$("${MYSQL[@]}" rbac_upgrade -e "SELECT GROUP_CONCAT(CONCAT(username,':',account_status) ORDER BY id SEPARATOR ',') FROM users;")" = "legacy_super:active,legacy_admin:active,legacy_user:active,legacy_inactive:inactive,legacy_blocked:blocked"

test "$("${MYSQL[@]}" rbac_upgrade -e "SELECT r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE u.username='legacy_super';")" = "superadmin"
test "$("${MYSQL[@]}" rbac_upgrade -e "SELECT r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE u.username='legacy_admin';")" = "admin"
test "$("${MYSQL[@]}" rbac_upgrade -e "SELECT r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE u.username='legacy_inactive';")" = "user"
test "$("${MYSQL[@]}" rbac_upgrade -e "SELECT r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE u.username='legacy_blocked';")" = "user"

# During the compatibility phase, legacy status writes update account_status but
# do not delete the independent RBAC assignment.
"${MYSQL[@]}" rbac_upgrade -e "UPDATE users SET role=999,is_active=1 WHERE username='legacy_admin';"
test "$("${MYSQL[@]}" rbac_upgrade -e "SELECT account_status FROM users WHERE username='legacy_admin';")" = "blocked"
test "$("${MYSQL[@]}" rbac_upgrade -e "SELECT r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE u.username='legacy_admin';")" = "admin"
"${MYSQL[@]}" rbac_upgrade -e "UPDATE users SET role=888,is_active=1 WHERE username='legacy_admin';"
test "$("${MYSQL[@]}" rbac_upgrade -e "SELECT account_status FROM users WHERE username='legacy_admin';")" = "active"
test "$("${MYSQL[@]}" rbac_upgrade -e "SELECT r.code FROM user_roles ur JOIN roles r ON r.id=ur.role_id JOIN users u ON u.id=ur.user_id WHERE u.username='legacy_admin';")" = "admin"

echo "RBAC fresh/install and legacy-upgrade contract OK"
