#!/usr/bin/env bash
set -Eeuo pipefail

DB_HOST="${DBHOST:-127.0.0.1}"
DB_PORT="${DBPORT:-3306}"
DB_USER="${DBUSER:-root}"
DB_PASS="${DBPASS:-root}"
DB_NAME="${DBNAME:-file_manager_http_test}"
HTTP_PORT="${FILE_ADMIN_RACE_PORT:-18085}"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"
PRIVATE_ROOT="${PRIVATE_STORAGE_PATH:-/tmp/workspace-file-admin-race-private}"
MIN_QUOTA=10485760
START_QUOTA=20971520
USER_NAME='file-race-user'
USER_PASSWORD='file-race-password'
ADMIN_NAME='file-race-admin'
ADMIN_PASSWORD='file-race-admin-password'
SERVER_PID=''
ENV_BACKUP=''
SERVER_LOG='/tmp/file-manager-admin-race-server.log'

mysql_cmd=(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "-p${DB_PASS}" "$DB_NAME")

cleanup() {
  set +e
  if [[ -n "$SERVER_PID" ]]; then
    pkill -TERM -P "$SERVER_PID" 2>/dev/null || true
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  "${mysql_cmd[@]}" -e 'DROP TRIGGER IF EXISTS ci_upload_first_sleep; DROP TRIGGER IF EXISTS ci_admin_first_sleep;' >/dev/null 2>&1 || true
  if [[ -n "$ENV_BACKUP" && -f "$ENV_BACKUP" ]]; then
    mv "$ENV_BACKUP" .env
  else
    rm -f .env
  fi
  rm -rf "$PRIVATE_ROOT"
}

on_error() {
  local status=$?
  echo "File Manager admin quota race failed near line ${BASH_LINENO[0]:-unknown} (exit ${status})" >&2
  [[ -f "$SERVER_LOG" ]] && cat "$SERVER_LOG" >&2 || true
  exit "$status"
}

trap on_error ERR
trap cleanup EXIT

checkpoint() {
  echo "[quota-race] $*"
}

if [[ -f .env ]]; then
  ENV_BACKUP="/tmp/workspace-file-admin-race-env-backup-$$"
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
RATE_LIMIT_STORAGE_PATH=${PRIVATE_ROOT}/rate-limit
UPLOAD_RATE_LIMIT_ATTEMPTS=200
UPLOAD_RATE_LIMIT_WINDOW_SECONDS=60
AUTH_RATE_LIMIT_ATTEMPTS=50
AUTH_RATE_LIMIT_WINDOW_SECONDS=60
MAX_UPLOAD_SIZE=10485760
EOF
chmod 600 .env

USER_HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_ARGON2ID);' "$USER_PASSWORD")"
ADMIN_HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_ARGON2ID);' "$ADMIN_PASSWORD")"

checkpoint 'seed regular user, active admin and starting quota'
"${mysql_cmd[@]}" <<SQL
DELETE FROM users WHERE username IN ('${USER_NAME}','${ADMIN_NAME}');
INSERT INTO users (uid,username,email,password_hash,firstname,lastname,role,is_active)
VALUES
('f2000000-0000-4000-8000-000000000001','${USER_NAME}','file-race-user@example.test','${USER_HASH}','Race','User',888,1),
('f2000000-0000-4000-8000-000000000002','${ADMIN_NAME}','file-race-admin@example.test','${ADMIN_HASH}','Race','Admin',111,1);
SET @uid=(SELECT id FROM users WHERE username='${USER_NAME}');
INSERT INTO user_storage_quotas (user_id,quota_bytes)
VALUES (@uid,${START_QUOTA})
ON DUPLICATE KEY UPDATE quota_bytes=VALUES(quota_bytes);
SQL

USER_ID="$("${mysql_cmd[@]}" -N -e "SELECT id FROM users WHERE username='${USER_NAME}'")"
test -n "$USER_ID"
LOCK_NAME="workspace-storage-quota-user-${USER_ID}"
USER_DIR="${PRIVATE_ROOT}/file_manager/${USER_ID}/files"
printf 'AAAA' > /tmp/file-race-a.txt
printf 'BBBB' > /tmp/file-race-b.txt

checkpoint 'start multi-worker application server'
: > "$SERVER_LOG"
PHP_CLI_SERVER_WORKERS=4 php -S "127.0.0.1:${HTTP_PORT}" index.php >"$SERVER_LOG" 2>&1 &
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
kill -0 "$SERVER_PID" 2>/dev/null

extract_csrf() {
  sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$1" | head -n1
}

login_session() {
  local username="$1"
  local password="$2"
  local jar="$3"
  local token_file="$4"
  local html="${jar}.login.html"
  local headers="${jar}.login.headers"
  rm -f "$jar" "$html" "$headers"
  curl -sS -c "$jar" -b "$jar" "${BASE_URL}/auth/login/" > "$html"
  local token
  token="$(extract_csrf "$html")"
  [[ -n "$token" ]]
  local status
  status="$(curl -sS -o /tmp/file-race-login.body -D "$headers" -w '%{http_code}' \
    -c "$jar" -b "$jar" \
    --data-urlencode "login=${username}" \
    --data-urlencode "password=${password}" \
    --data-urlencode "csrf_token=${token}" \
    "${BASE_URL}/auth/login/")"
  [[ "$status" = '302' ]]
  printf '%s' "$token" > "$token_file"
}

upload_file() {
  local jar="$1"
  local token="$2"
  local source="$3"
  local remote_name="$4"
  local body="$5"
  curl -sS -o "$body" -w '%{http_code}' \
    -c "$jar" -b "$jar" \
    -H 'Accept: application/json' \
    -H 'X-Requested-With: XMLHttpRequest' \
    -F "csrf_token=${token}" \
    -F 'parent_id=0' \
    -F "file=@${source};filename=${remote_name};type=text/plain" \
    "${BASE_URL}/files/upload/"
}

update_quota() {
  local jar="$1"
  local token="$2"
  local body="$3"
  curl -sS -o "$body" -w '%{http_code}' \
    -c "$jar" -b "$jar" \
    -H 'X-Requested-With: XMLHttpRequest' \
    --data-urlencode "csrf_token=${token}" \
    --data-urlencode "user_id=${USER_ID}" \
    --data-urlencode 'quota_mb=10' \
    "${BASE_URL}/admin/settings/user-quota"
}

wait_for_storage_lock() {
  for _ in {1..100}; do
    if [[ "$("${mysql_cmd[@]}" -N -e "SELECT IS_USED_LOCK('${LOCK_NAME}') IS NOT NULL")" = '1' ]]; then
      return 0
    fi
    sleep 0.04
  done
  echo "storage advisory lock did not become active: ${LOCK_NAME}" >&2
  return 1
}

login_session "$USER_NAME" "$USER_PASSWORD" /tmp/file-race-user.cookie /tmp/file-race-user.token
login_session "$ADMIN_NAME" "$ADMIN_PASSWORD" /tmp/file-race-admin.cookie /tmp/file-race-admin.token
USER_TOKEN="$(cat /tmp/file-race-user.token)"
ADMIN_TOKEN="$(cat /tmp/file-race-admin.token)"

checkpoint 'upload-first: admin quota update waits for active upload lock'
"${mysql_cmd[@]}" -e "DELETE FROM user_files WHERE user_id=${USER_ID}; UPDATE user_storage_quotas SET quota_bytes=${START_QUOTA} WHERE user_id=${USER_ID};"
rm -rf "$USER_DIR"
"${mysql_cmd[@]}" -e "INSERT INTO user_files (uid,user_id,parent_id,name,type,mime_type,size,path,extension,is_deleted) VALUES ('race-filler-a',${USER_ID},NULL,'race-filler-a','file','application/octet-stream',${MIN_QUOTA},NULL,'bin',0);"
"${mysql_cmd[@]}" <<'SQL'
DROP TRIGGER IF EXISTS ci_upload_first_sleep;
DELIMITER //
CREATE TRIGGER ci_upload_first_sleep
BEFORE INSERT ON user_files
FOR EACH ROW
BEGIN
  IF NEW.name = 'admin-race-upload-first' THEN
    DO SLEEP(2);
  END IF;
END//
DELIMITER ;
SQL

(
  code="$(upload_file /tmp/file-race-user.cookie "$USER_TOKEN" /tmp/file-race-a.txt admin-race-upload-first.txt /tmp/file-race-upload-first.body)"
  printf '%s' "$code" > /tmp/file-race-upload-first.code
) &
UPLOAD_PID=$!
wait_for_storage_lock
ADMIN_START="$(date +%s%3N)"
ADMIN_STATUS="$(update_quota /tmp/file-race-admin.cookie "$ADMIN_TOKEN" /tmp/file-race-admin-first.body)"
ADMIN_END="$(date +%s%3N)"
wait "$UPLOAD_PID"
UPLOAD_STATUS="$(cat /tmp/file-race-upload-first.code)"
[[ "$UPLOAD_STATUS" = '200' ]]
[[ "$ADMIN_STATUS" = '302' ]]
ADMIN_WAIT_MS=$((ADMIN_END - ADMIN_START))
if (( ADMIN_WAIT_MS < 1000 )); then
  echo "admin quota update did not wait for upload lock (${ADMIN_WAIT_MS}ms)" >&2
  exit 1
fi
[[ "$("${mysql_cmd[@]}" -N -e "SELECT quota_bytes FROM user_storage_quotas WHERE user_id=${USER_ID}")" = "$MIN_QUOTA" ]]
[[ "$("${mysql_cmd[@]}" -N -e "SELECT COALESCE(SUM(size),0) FROM user_files WHERE user_id=${USER_ID} AND is_deleted=0")" = "$((MIN_QUOTA + 4))" ]]
"${mysql_cmd[@]}" -e 'DROP TRIGGER IF EXISTS ci_upload_first_sleep'

checkpoint 'admin-first: upload waits, then observes lowered quota and is rejected'
"${mysql_cmd[@]}" -e "DELETE FROM user_files WHERE user_id=${USER_ID}; UPDATE user_storage_quotas SET quota_bytes=${START_QUOTA} WHERE user_id=${USER_ID};"
rm -rf "$USER_DIR"
"${mysql_cmd[@]}" -e "INSERT INTO user_files (uid,user_id,parent_id,name,type,mime_type,size,path,extension,is_deleted) VALUES ('race-filler-b',${USER_ID},NULL,'race-filler-b','file','application/octet-stream',$((MIN_QUOTA - 2)),NULL,'bin',0);"
"${mysql_cmd[@]}" <<'SQL'
DROP TRIGGER IF EXISTS ci_admin_first_sleep;
DELIMITER //
CREATE TRIGGER ci_admin_first_sleep
BEFORE UPDATE ON user_storage_quotas
FOR EACH ROW
BEGIN
  IF NEW.quota_bytes = 10485760 THEN
    DO SLEEP(2);
  END IF;
END//
DELIMITER ;
SQL

(
  code="$(update_quota /tmp/file-race-admin.cookie "$ADMIN_TOKEN" /tmp/file-race-admin-second.body)"
  printf '%s' "$code" > /tmp/file-race-admin-second.code
) &
ADMIN_PID=$!
wait_for_storage_lock
UPLOAD_START="$(date +%s%3N)"
UPLOAD_SECOND_STATUS="$(upload_file /tmp/file-race-user.cookie "$USER_TOKEN" /tmp/file-race-b.txt admin-race-upload-second.txt /tmp/file-race-upload-second.body)"
UPLOAD_END="$(date +%s%3N)"
wait "$ADMIN_PID"
ADMIN_SECOND_STATUS="$(cat /tmp/file-race-admin-second.code)"
[[ "$ADMIN_SECOND_STATUS" = '302' ]]
UPLOAD_WAIT_MS=$((UPLOAD_END - UPLOAD_START))
if (( UPLOAD_WAIT_MS < 1000 )); then
  echo "upload did not wait for admin quota lock (${UPLOAD_WAIT_MS}ms)" >&2
  exit 1
fi
if [[ "$UPLOAD_SECOND_STATUS" != '413' ]]; then
  echo "upload after in-flight quota reduction returned HTTP ${UPLOAD_SECOND_STATUS}" >&2
  cat /tmp/file-race-upload-second.body >&2 || true
  exit 1
fi
[[ "$("${mysql_cmd[@]}" -N -e "SELECT quota_bytes FROM user_storage_quotas WHERE user_id=${USER_ID}")" = "$MIN_QUOTA" ]]
[[ "$("${mysql_cmd[@]}" -N -e "SELECT COUNT(*) FROM user_files WHERE user_id=${USER_ID} AND name='admin-race-upload-second'")" = '0' ]]
"${mysql_cmd[@]}" -e 'DROP TRIGGER IF EXISTS ci_admin_first_sleep'

echo 'File Manager upload/admin quota-update race contract: OK'
