#!/usr/bin/env bash
set -Eeuo pipefail

DB_HOST="${DBHOST:-127.0.0.1}"
DB_PORT="${DBPORT:-3306}"
DB_USER="${DBUSER:-root}"
DB_PASS="${DBPASS:-root}"
DB_NAME="${DBNAME:-file_manager_http_test}"
HTTP_PORT="${FILE_QUOTA_HTTP_PORT:-18086}"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"
PRIVATE_ROOT="${PRIVATE_STORAGE_PATH:-/tmp/workspace-file-quota-private}"
USERNAME='file-quota-user'
PASSWORD='file-quota-password'
MIN_QUOTA=10485760
USED_BYTES=3145728
SERVER_PID=''
ENV_BACKUP=''
SERVER_LOG='/tmp/file-manager-quota-server.log'
COOKIE='/tmp/file-quota.cookie'

mysql_cmd=(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "-p${DB_PASS}" "$DB_NAME")

cleanup() {
  set +e
  if [[ -n "$SERVER_PID" ]]; then
    pkill -TERM -P "$SERVER_PID" 2>/dev/null || true
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  if [[ -n "$ENV_BACKUP" && -f "$ENV_BACKUP" ]]; then
    mv "$ENV_BACKUP" .env
  else
    rm -f .env
  fi
  rm -rf "$PRIVATE_ROOT"
}
trap cleanup EXIT

if [[ -f .env ]]; then
  ENV_BACKUP="/tmp/workspace-file-quota-env-backup-$$"
  cp .env "$ENV_BACKUP"
fi

rm -rf "$PRIVATE_ROOT"
mkdir -p "$PRIVATE_ROOT"
chmod 700 "$PRIVATE_ROOT"

cat > .env <<EOF
DBDRIVER=mysql
DBHOST=${DB_HOST}
DBPORT=${DB_PORT}
DBUSER=${DB_USER}
DBPASS=${DB_PASS}
DBNAME=${DB_NAME}
SITEURL=${BASE_URL}
BASE_PATH=/
PRIVATE_STORAGE_PATH=${PRIVATE_ROOT}
AUTH_RATE_LIMIT_ATTEMPTS=50
AUTH_RATE_LIMIT_WINDOW_SECONDS=60
EOF
chmod 600 .env

php tests/support/ci_license_fixture.php

HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_ARGON2ID);' "$PASSWORD")"
"${mysql_cmd[@]}" <<SQL
DELETE FROM users WHERE username='${USERNAME}';
INSERT INTO users (uid,username,email,password_hash,firstname,lastname,role,is_active)
VALUES ('f3000000-0000-4000-8000-000000000001','${USERNAME}','file-quota-user@example.test','${HASH}','Quota','User',888,1);
SET @uid=(SELECT id FROM users WHERE username='${USERNAME}');
INSERT INTO user_storage_quotas (user_id,quota_bytes)
VALUES (@uid,${MIN_QUOTA})
ON DUPLICATE KEY UPDATE quota_bytes=VALUES(quota_bytes);
DELETE FROM user_files WHERE user_id=@uid;
INSERT INTO user_files (uid,user_id,parent_id,name,type,mime_type,size,path,extension,is_deleted)
VALUES ('quota-endpoint-usage',@uid,NULL,'quota-endpoint-usage','file','application/octet-stream',${USED_BYTES},NULL,'bin',0);
SQL

: > "$SERVER_LOG"
PHP_CLI_SERVER_WORKERS=2 php -S "127.0.0.1:${HTTP_PORT}" index.php >"$SERVER_LOG" 2>&1 &
SERVER_PID=$!
READY=0
for _ in {1..60}; do
  if curl -sS -o /dev/null "${BASE_URL}/auth/login/"; then
    READY=1
    break
  fi
  sleep 0.25
done
[[ "$READY" -eq 1 ]]

UNAUTH_STATUS="$(curl -sS -o /tmp/file-quota-unauth.body -D /tmp/file-quota-unauth.headers -w '%{http_code}' "${BASE_URL}/files/quota/")"
[[ "$UNAUTH_STATUS" = '302' ]]
grep -Eiq '^Location: .*/auth/login/' /tmp/file-quota-unauth.headers

curl -sS -c "$COOKIE" -b "$COOKIE" "${BASE_URL}/auth/login/" > /tmp/file-quota-login.html
TOKEN="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' /tmp/file-quota-login.html | head -n1)"
[[ -n "$TOKEN" ]]
LOGIN_STATUS="$(curl -sS -o /tmp/file-quota-login-post.body -w '%{http_code}' -c "$COOKIE" -b "$COOKIE" \
  --data-urlencode "login=${USERNAME}" \
  --data-urlencode "password=${PASSWORD}" \
  --data-urlencode "csrf_token=${TOKEN}" \
  "${BASE_URL}/auth/login/")"
[[ "$LOGIN_STATUS" = '302' ]]

STATUS="$(curl -sS -o /tmp/file-quota.body -D /tmp/file-quota.headers -w '%{http_code}' \
  -c "$COOKIE" -b "$COOKIE" -H 'Accept: application/json' -H 'X-Requested-With: XMLHttpRequest' \
  "${BASE_URL}/files/quota/")"
[[ "$STATUS" = '200' ]]
grep -Eiq '^Cache-Control: .*no-store' /tmp/file-quota.headers
php -r '
$d=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (($d["success"] ?? false) !== true) exit(1);
$s=$d["storage"] ?? [];
if (($s["used_bytes"] ?? -1) !== 3145728) exit(2);
if (($s["quota_bytes"] ?? -1) !== 10485760) exit(3);
if (($s["remaining_bytes"] ?? -1) !== 7340032) exit(4);
if (abs((float)($s["percent"] ?? -1) - 30.0) > 0.01) exit(5);
' /tmp/file-quota.body

echo 'File Manager quota endpoint real HTTP contract: OK'
