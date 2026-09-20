#!/usr/bin/env bash
set -euo pipefail

MYSQL=(mysql -h 127.0.0.1 -uroot -proot -N -B)

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS rbac_enforcement; CREATE DATABASE rbac_enforcement CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
for schema in messenger_schema.sql file_manager_schema.sql access_control_schema.sql settings_schema.sql; do
  "${MYSQL[@]}" rbac_enforcement < "database/${schema}"
done

"${MYSQL[@]}" rbac_enforcement <<'SQL'
INSERT INTO users (uid,username,email,password_hash,firstname,lastname,role,is_active,account_status) VALUES
('20000000-0000-4000-8000-000000000001','runtime-rbac-admin','runtime-rbac-admin@example.test','x','Runtime','Admin',888,1,'active'),
('20000000-0000-4000-8000-000000000002','runtime-legacy-admin','runtime-legacy-admin@example.test','x','Legacy','Admin',111,1,'active'),
('20000000-0000-4000-8000-000000000003','runtime-target','runtime-target@example.test','x','Runtime','Target',888,1,'active'),
('20000000-0000-4000-8000-000000000004','runtime-privileged-target','runtime-privileged-target@example.test','x','Privileged','Target',888,1,'active');

-- Deliberately invert legacy role metadata and RBAC identity. Production
-- authorization must follow user_roles/permissions, not users.role.
DELETE ur FROM user_roles ur JOIN users u ON u.id=ur.user_id
WHERE u.username IN ('runtime-rbac-admin','runtime-legacy-admin','runtime-privileged-target');

INSERT INTO user_roles (user_id,role_id)
SELECT u.id,r.id FROM users u JOIN roles r ON r.code='admin'
WHERE u.username IN ('runtime-rbac-admin','runtime-privileged-target');

INSERT INTO user_roles (user_id,role_id)
SELECT u.id,r.id FROM users u JOIN roles r ON r.code='user'
WHERE u.username='runtime-legacy-admin';
SQL

export DBDRIVER=mysql DBHOST=127.0.0.1 DBPORT=3306 DBUSER=root DBPASS=root DBNAME=rbac_enforcement
export PRIVATE_STORAGE_PATH=/tmp/rbac-enforcement-private
rm -rf "$PRIVATE_STORAGE_PATH"
mkdir -m 700 -p "$PRIVATE_STORAGE_PATH/users"

php tests/integration/rbac_enforcement_runtime.php

# Static wiring is part of the security contract: there must be no fallback to
# the legacy numeric role helper at HTTP or WebSocket authentication boundaries.
! grep -Fq 'Config::canAuthenticate' app/controllers/AuthController.php
! grep -Fq 'Config::canAuthenticate' app/middlewares/LoginRequared.php
! grep -Fq 'Config::canAuthenticate' ws_server/server.php
! grep -Fq 'IsAdmin::class' core/routerConfig.php

grep -Fq 'RequireAdminAccess::class' modules/admin/AdminRuntimeProvider.php
grep -Fq 'RequireAdminUsersManage::class' modules/admin/AdminRuntimeProvider.php
grep -Fq 'RequireAdminSettingsManage::class' modules/admin/AdminRuntimeProvider.php
grep -Fq "admin.users.manage" modules/admin/services/AdminUserService.php
grep -Fq "admin.settings.manage" app/services/StorageQuotaService.php
grep -Fq "messenger.use" modules/messenger/socket/NativeMessengerServer.php
grep -Fq "account_status" app/controllers/AuthController.php
grep -Fq "account_status" app/middlewares/LoginRequared.php

if grep -Eq 'UPDATE users SET[^\n]*role[[:space:]]*=' modules/admin/services/AdminUserService.php; then
  echo 'AdminUserService still rewrites legacy role during status changes' >&2
  exit 1
fi

echo "RBAC enforcement MySQL/static contract OK"
