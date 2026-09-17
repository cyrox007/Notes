#!/usr/bin/env bash
set -euo pipefail

MYSQL=(mysql -h 127.0.0.1 -uroot -proot -N -B)

"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS registration_policy; CREATE DATABASE registration_policy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
for schema in messenger_schema.sql settings_schema.sql access_control_schema.sql; do
  "${MYSQL[@]}" registration_policy < "database/${schema}"
done

"${MYSQL[@]}" registration_policy <<'SQL'
INSERT INTO users (uid,username,email,password_hash,firstname,lastname,role,is_active,account_status) VALUES
('31000000-0000-4000-8000-000000000001','registration-admin','registration-admin@example.test','x','Registration','Admin',888,1,'active'),
('31000000-0000-4000-8000-000000000002','registration-user','registration-user@example.test','x','Registration','User',888,1,'active');

DELETE ur FROM user_roles ur JOIN users u ON u.id=ur.user_id
WHERE u.username IN ('registration-admin','registration-user');

INSERT INTO user_roles (user_id,role_id)
SELECT u.id,r.id FROM users u JOIN roles r ON r.code='admin'
WHERE u.username='registration-admin';

INSERT INTO user_roles (user_id,role_id)
SELECT u.id,r.id FROM users u JOIN roles r ON r.code='user'
WHERE u.username='registration-user';
SQL

export DBDRIVER=mysql DBHOST=127.0.0.1 DBPORT=3306 DBUSER=root DBPASS=root DBNAME=registration_policy
export UNIQUE_KEY='registration-policy-contract-unique-key-2026'
export PRIVATE_STORAGE_PATH=/tmp/registration-policy-private
rm -rf "$PRIVATE_STORAGE_PATH"
mkdir -m 700 -p "$PRIVATE_STORAGE_PATH"
unset REGISTRATION_INVITE_CODE || true

php tests/integration/registration_policy_runtime.php

# Security/routing contract: public POST keeps rate-limit + CSRF, while admin
# provisioning/settings require both dedicated RBAC middleware and CSRF.
grep -Fq "'/registration', [AuthController::class, 'registration'], [], 'registration'" core/routerConfig.php
grep -Fq "'/registration', [AuthController::class, 'registration'], [AuthRateLimit::class, CSRFMiddleware::class], 'register_submit'" core/routerConfig.php
grep -Fq "'/users/create', [UserProvisioningController::class, 'create'], [LoginRequared::class, RequireAdminUsersManage::class, CSRFMiddleware::class]" modules/admin/AdminRuntimeProvider.php
grep -Fq "'/registration', [RegistrationSettingsController::class, 'index'], [LoginRequared::class, RequireAdminSettingsManage::class]" modules/admin/AdminRuntimeProvider.php
grep -Fq "'/registration/mode', [RegistrationSettingsController::class, 'saveMode'], [LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class]" modules/admin/AdminRuntimeProvider.php
grep -Fq "'/registration/invites/create', [RegistrationSettingsController::class, 'createInvite'], [LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class]" modules/admin/AdminRuntimeProvider.php
grep -Fq "'/registration/invites/revoke', [RegistrationSettingsController::class, 'revokeInvite'], [LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class]" modules/admin/AdminRuntimeProvider.php

grep -Fq "registration_mode == 'open'" app/views/login_page/login_view.tpl
grep -Fq "registration_mode == 'invite'" app/views/login_page/login_view.tpl
grep -Fq "admin_create_user" modules/admin/views/index.php
grep -Fq "admin_registration_invite_create" modules/admin/views/registration.php
grep -Fq "hash('sha256', \$code)" app/services/RegistrationPolicyService.php
! grep -Fq "password_hash" modules/admin/views/registration.php

for file in \
  app/services/UserProvisioningService.php \
  app/services/RegistrationPolicyService.php \
  modules/admin/controllers/UserProvisioningController.php \
  modules/admin/controllers/RegistrationSettingsController.php \
  app/controllers/AuthController.php \
  tests/integration/registration_policy_runtime.php; do
  php -l "$file"
done

echo "Registration policy MySQL/static contract OK"
