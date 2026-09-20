#!/usr/bin/env bash
set -Eeuo pipefail

DB_HOST="${DBHOST:-127.0.0.1}"
DB_PORT="${DBPORT:-3306}"
DB_USER="${DBUSER:-root}"
DB_PASS="${DBPASS:-root}"
DB_NAME="${DBNAME:-file_manager_http_test}"
HTTP_PORT="${FILE_HTTP_PORT:-18084}"
BASE_URL="http://127.0.0.1:${HTTP_PORT}"
PRIVATE_ROOT="${PRIVATE_STORAGE_PATH:-/tmp/workspace-file-http-private}"
PASSWORD='file-http-password'
USERNAME='file-http-user'
MIN_QUOTA=10485760
SERVER_PID=''
ENV_BACKUP=''
SERVER_LOG='/tmp/file-manager-http-server.log'

mysql_cmd=(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "-p${DB_PASS}" "$DB_NAME")

cleanup() {
  set +e
  if [[ -n "$SERVER_PID" ]]; then
    pkill -TERM -P "$SERVER_PID" 2>/dev/null || true
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  "${mysql_cmd[@]}" -e 'DROP TRIGGER IF EXISTS ci_fail_file_metadata' >/dev/null 2>&1 || true
  if [[ -n "$ENV_BACKUP" && -f "$ENV_BACKUP" ]]; then
    mv "$ENV_BACKUP" .env
  else
    rm -f .env
  fi
  rm -rf "$PRIVATE_ROOT"
}

on_error() {
  local status=$?
  local line="${BASH_LINENO[0]:-unknown}"
  echo "File Manager HTTP integration failed near line ${line} (exit ${status})" >&2
  if [[ -f "$SERVER_LOG" ]]; then
    echo '--- PHP server log ---' >&2
    cat "$SERVER_LOG" >&2
    echo '--- end PHP server log ---' >&2
  fi
  exit "$status"
}

trap on_error ERR
trap cleanup EXIT

checkpoint() {
  echo "[file-http] $*"
}

if [[ -f .env ]]; then
  ENV_BACKUP="/tmp/workspace-file-http-env-backup-$$"
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

php tests/support/ci_license_fixture.php

checkpoint 'seed user and valid minimum quota'
HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_ARGON2ID);' "$PASSWORD")"
"${mysql_cmd[@]}" <<SQL
DELETE FROM users WHERE username='${USERNAME}';
INSERT INTO users (uid,username,email,password_hash,firstname,lastname,role,is_active)
VALUES ('f1000000-0000-4000-8000-000000000001','${USERNAME}','file-http-user@example.test','${HASH}','File','Http',888,1);
SET @uid=(SELECT id FROM users WHERE username='${USERNAME}');
INSERT INTO user_storage_quotas (user_id,quota_bytes)
VALUES (@uid,${MIN_QUOTA})
ON DUPLICATE KEY UPDATE quota_bytes=VALUES(quota_bytes);
SQL

USER_ID="$("${mysql_cmd[@]}" -N -e "SELECT id FROM users WHERE username='${USERNAME}'")"
test -n "$USER_ID"
USER_DIR="${PRIVATE_ROOT}/file_manager/${USER_ID}/files"

printf 'abc' > /tmp/file-http-three.txt
printf 'xy' > /tmp/file-http-two.txt
printf 'FAIL' > /tmp/file-http-db-failure.txt
printf 'AAAA' > /tmp/file-http-concurrent-a.txt
printf 'BBBB' > /tmp/file-http-concurrent-b.txt

checkpoint 'start real HTTP server'
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
if [[ "$READY" -ne 1 ]] || ! kill -0 "$SERVER_PID" 2>/dev/null; then
  echo 'PHP test server did not become ready' >&2
  cat "$SERVER_LOG" >&2
  exit 1
fi

extract_csrf() {
  local token
  token="$(sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$1" | head -n1)"
  if [[ -z "$token" ]]; then
    echo 'csrf token not found' >&2
    return 2
  fi
  printf '%s' "$token"
}

login_session() {
  local jar="$1"
  local token_file="$2"
  local html="${jar}.login.html"
  local headers="${jar}.login.headers"
  local authenticated_html="${jar}.authenticated.html"
  rm -f "$jar" "$html" "$headers" "$authenticated_html"
  curl -sS -c "$jar" -b "$jar" "${BASE_URL}/auth/login/" > "$html"
  local anonymous_token
  anonymous_token="$(extract_csrf "$html")"
  local status
  status="$(curl -sS -o /tmp/file-http-login-post.html -D "$headers" -w '%{http_code}' \
    -c "$jar" -b "$jar" \
    --data-urlencode "login=${USERNAME}" \
    --data-urlencode "password=${PASSWORD}" \
    --data-urlencode "csrf_token=${anonymous_token}" \
    "${BASE_URL}/auth/login/")"
  if [[ "$status" != '302' ]]; then
    echo "login returned HTTP ${status}" >&2
    cat /tmp/file-http-login-post.html >&2 || true
    return 1
  fi
  grep -Eiq '^Location: /' "$headers"

  # Login intentionally rotates both the session ID and CSRF token. Fetch an
  # authenticated native page and use the fresh token exactly as a browser does.
  local authenticated_status
  authenticated_status="$(curl -sS -o "$authenticated_html" -w '%{http_code}' -c "$jar" -b "$jar" "${BASE_URL}/files/")"
  if [[ "$authenticated_status" != '200' ]]; then
    echo "authenticated Files page returned HTTP ${authenticated_status}" >&2
    cat "$authenticated_html" >&2 || true
    return 1
  fi
  local authenticated_token
  authenticated_token="$(extract_csrf "$authenticated_html")"
  if [[ "$authenticated_token" == "$anonymous_token" ]]; then
    echo 'CSRF token was not rotated across authentication boundary' >&2
    return 1
  fi
  printf '%s' "$authenticated_token" > "$token_file"
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

checkpoint 'unauthenticated upload is rejected by LoginRequared'
UNAUTH_STATUS="$(curl -sS -o /tmp/file-http-unauth.body -D /tmp/file-http-unauth.headers -w '%{http_code}' \
  -F 'file=@/tmp/file-http-three.txt;filename=unauth.txt;type=text/plain' \
  "${BASE_URL}/files/upload/")"
if [[ "$UNAUTH_STATUS" != '302' ]]; then
  echo "unauthenticated upload returned HTTP ${UNAUTH_STATUS}" >&2
  cat /tmp/file-http-unauth.body >&2 || true
  exit 1
fi
grep -Eiq '^Location: .*/auth/login/' /tmp/file-http-unauth.headers

checkpoint 'real login and CSRF session'
login_session /tmp/file-http-cookie-a /tmp/file-http-token-a
TOKEN_A="$(cat /tmp/file-http-token-a)"

checkpoint 'authenticated upload without CSRF is rejected'
NO_CSRF_STATUS="$(curl -sS -o /tmp/file-http-no-csrf.body -w '%{http_code}' \
  -c /tmp/file-http-cookie-a -b /tmp/file-http-cookie-a \
  -H 'Accept: application/json' -H 'X-Requested-With: XMLHttpRequest' \
  -F 'parent_id=0' \
  -F 'file=@/tmp/file-http-three.txt;filename=no-csrf.txt;type=text/plain' \
  "${BASE_URL}/files/upload/")"
if [[ "$NO_CSRF_STATUS" != '403' ]]; then
  echo "missing-CSRF upload returned HTTP ${NO_CSRF_STATUS}" >&2
  cat /tmp/file-http-no-csrf.body >&2 || true
  exit 1
fi
test "$("${mysql_cmd[@]}" -N -e "SELECT COUNT(*) FROM user_files WHERE user_id=${USER_ID}")" = '0'

checkpoint 'normal multipart upload persists metadata and file'
OK_STATUS="$(upload_file /tmp/file-http-cookie-a "$TOKEN_A" /tmp/file-http-three.txt ok-three.txt /tmp/file-http-ok.body)"
if [[ "$OK_STATUS" != '200' ]]; then
  echo "normal upload returned HTTP ${OK_STATUS}" >&2
  cat /tmp/file-http-ok.body >&2 || true
  exit 1
fi
php -r '$d=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if (($d["success"]??false)!==true) exit(1);' /tmp/file-http-ok.body
test "$("${mysql_cmd[@]}" -N -e "SELECT COALESCE(SUM(size),0) FROM user_files WHERE user_id=${USER_ID} AND is_deleted=0")" = '3'
OK_PATH="$("${mysql_cmd[@]}" -N -e "SELECT path FROM user_files WHERE user_id=${USER_ID} AND name='ok-three' AND is_deleted=0 LIMIT 1")"
test -n "$OK_PATH"
test -f "$OK_PATH"
test "$(stat -c '%a' "$OK_PATH")" = '600'

checkpoint 'quota overflow is rejected with a valid 10 MiB quota'
"${mysql_cmd[@]}" -e "UPDATE user_files SET size=$((MIN_QUOTA - 1)) WHERE user_id=${USER_ID} AND name='ok-three';"
OVER_STATUS="$(upload_file /tmp/file-http-cookie-a "$TOKEN_A" /tmp/file-http-two.txt over-two.txt /tmp/file-http-over.body)"
if [[ "$OVER_STATUS" != '413' ]]; then
  echo "overflow upload returned HTTP ${OVER_STATUS}" >&2
  cat /tmp/file-http-over.body >&2 || true
  exit 1
fi
test "$("${mysql_cmd[@]}" -N -e "SELECT COUNT(*) FROM user_files WHERE user_id=${USER_ID} AND name='over-two'")" = '0'

checkpoint 'exact remaining quota is accepted'
"${mysql_cmd[@]}" -e "UPDATE user_files SET size=$((MIN_QUOTA - 2)) WHERE user_id=${USER_ID} AND name='ok-three';"
EXACT_STATUS="$(upload_file /tmp/file-http-cookie-a "$TOKEN_A" /tmp/file-http-two.txt exact-two.txt /tmp/file-http-exact.body)"
if [[ "$EXACT_STATUS" != '200' ]]; then
  echo "exact-remaining upload returned HTTP ${EXACT_STATUS}" >&2
  cat /tmp/file-http-exact.body >&2 || true
  exit 1
fi
test "$("${mysql_cmd[@]}" -N -e "SELECT COALESCE(SUM(size),0) FROM user_files WHERE user_id=${USER_ID} AND is_deleted=0")" = "$MIN_QUOTA"

checkpoint 'parallel sessions cannot oversubscribe quota'
"${mysql_cmd[@]}" -e "DELETE FROM user_files WHERE user_id=${USER_ID};"
rm -rf "$USER_DIR"
"${mysql_cmd[@]}" -e "INSERT INTO user_files (uid,user_id,parent_id,name,type,mime_type,size,path,extension,is_deleted) VALUES ('http-quota-filler',${USER_ID},NULL,'quota-filler','file','application/octet-stream',$((MIN_QUOTA - 6)),NULL,'bin',0);"
login_session /tmp/file-http-cookie-b /tmp/file-http-token-b
TOKEN_B="$(cat /tmp/file-http-token-b)"

(
  code="$(upload_file /tmp/file-http-cookie-a "$TOKEN_A" /tmp/file-http-concurrent-a.txt concurrent-a.txt /tmp/file-http-concurrent-a.body)"
  printf '%s' "$code" > /tmp/file-http-concurrent-a.code
) &
PID_A=$!
(
  code="$(upload_file /tmp/file-http-cookie-b "$TOKEN_B" /tmp/file-http-concurrent-b.txt concurrent-b.txt /tmp/file-http-concurrent-b.body)"
  printf '%s' "$code" > /tmp/file-http-concurrent-b.code
) &
PID_B=$!
wait "$PID_A"
wait "$PID_B"

CODE_A="$(cat /tmp/file-http-concurrent-a.code)"
CODE_B="$(cat /tmp/file-http-concurrent-b.code)"
SORTED_CODES="$(printf '%s\n%s\n' "$CODE_A" "$CODE_B" | sort -n | tr '\n' ' ' | sed 's/ $//')"
if [[ "$SORTED_CODES" != '200 413' ]]; then
  echo "concurrent upload statuses were ${CODE_A} and ${CODE_B}" >&2
  cat /tmp/file-http-concurrent-a.body >&2 || true
  cat /tmp/file-http-concurrent-b.body >&2 || true
  exit 1
fi
test "$("${mysql_cmd[@]}" -N -e "SELECT COUNT(*) FROM user_files WHERE user_id=${USER_ID} AND name IN ('concurrent-a','concurrent-b') AND is_deleted=0")" = '1'
test "$("${mysql_cmd[@]}" -N -e "SELECT COALESCE(SUM(size),0) FROM user_files WHERE user_id=${USER_ID} AND is_deleted=0")" = "$((MIN_QUOTA - 2))"

checkpoint 'metadata DB failure returns 500 and cleans moved file'
"${mysql_cmd[@]}" -e "DELETE FROM user_files WHERE user_id=${USER_ID};"
rm -rf "$USER_DIR"
"${mysql_cmd[@]}" <<'SQL'
DROP TRIGGER IF EXISTS ci_fail_file_metadata;
DELIMITER //
CREATE TRIGGER ci_fail_file_metadata
BEFORE INSERT ON user_files
FOR EACH ROW
BEGIN
  IF NEW.name = 'force-db-failure' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CI forced File Manager metadata failure';
  END IF;
END//
DELIMITER ;
SQL

FAIL_STATUS="$(upload_file /tmp/file-http-cookie-a "$TOKEN_A" /tmp/file-http-db-failure.txt force-db-failure.txt /tmp/file-http-db-failure.body)"
if [[ "$FAIL_STATUS" != '500' ]]; then
  echo "forced DB-failure upload returned HTTP ${FAIL_STATUS}" >&2
  cat /tmp/file-http-db-failure.body >&2 || true
  exit 1
fi
test "$("${mysql_cmd[@]}" -N -e "SELECT COUNT(*) FROM user_files WHERE user_id=${USER_ID} AND name='force-db-failure'")" = '0'
if [[ -d "$USER_DIR" ]] && find "$USER_DIR" -type f -print -quit | grep -q .; then
  echo 'Metadata failure left an orphan physical file' >&2
  find "$USER_DIR" -type f -ls >&2
  exit 1
fi
"${mysql_cmd[@]}" -e 'DROP TRIGGER IF EXISTS ci_fail_file_metadata'

echo 'File Manager real HTTP auth/CSRF/quota/concurrency/failure contract: OK'
