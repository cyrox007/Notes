#!/usr/bin/env bash
set -Eeuo pipefail

DB_HOST="${DBHOST:-127.0.0.1}"
DB_PORT="${DBPORT:-3306}"
DB_USER="${DBUSER:-root}"
DB_PASS="${DBPASS:-root}"
DB_NAME="${DBNAME:-phase2_hardening}"
BASE_URL="${ACTIVE_SESSION_BASE_URL:-http://127.0.0.1:18088/workspace}"
USERNAME='active-session-user'
PASSWORD='ActiveSessionPassword123!'
COOKIE='/tmp/active-session.cookies'
LOGIN_HTML='/tmp/active-session-login.html'
LOGIN_HEADERS='/tmp/active-session-login.headers'
PROTECTED_HEADERS='/tmp/active-session-protected.headers'
LOGIN_POST_HTML='/tmp/active-session-login-post.html'
BEFORE_HTML='/tmp/active-session-before.html'
AFTER_HTML='/tmp/active-session-after.html'
SECOND_HTML='/tmp/active-session-second.html'

mysql_cmd=(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "-p${DB_PASS}" "$DB_NAME")

cleanup() {
  set +e
  rm -f "$COOKIE" "$LOGIN_HTML" "$LOGIN_HEADERS" "$PROTECTED_HEADERS" \
    "$LOGIN_POST_HTML" "$BEFORE_HTML" "$AFTER_HTML" "$SECOND_HTML"
  "${mysql_cmd[@]}" -e "DELETE FROM users WHERE username='${USERNAME}'" >/dev/null 2>&1 || true
}
trap cleanup EXIT

extract_csrf() {
  sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$1" | head -n1
}

# Derive the expected application prefix from BASE_URL so this contract remains
# valid for both the default /workspace fixture and any future subdirectory name.
BASE_PATH="$(php -r '$p=parse_url($argv[1], PHP_URL_PATH); echo "/" . trim((string)$p, "/");' "$BASE_URL")"
if [[ "$BASE_PATH" == '/' ]]; then
  EXPECTED_LOGIN='/auth/login'
else
  EXPECTED_LOGIN="${BASE_PATH}/auth/login"
fi

assert_prefixed_login_redirect() {
  local headers="$1"
  local location
  location="$(tr -d '\r' < "$headers" | awk 'BEGIN{IGNORECASE=1} /^Location:/{sub(/^[^:]*:[[:space:]]*/, ""); print; exit}')"
  if [[ "$location" != "$EXPECTED_LOGIN" && "$location" != "${EXPECTED_LOGIN}/" ]]; then
    echo "Inactive session was not redirected to the prefixed login route: ${location:-<missing>}" >&2
    cat "$headers" >&2
    exit 1
  fi
}

HASH="$(php -r 'echo password_hash($argv[1], PASSWORD_ARGON2ID);' "$PASSWORD")"
"${mysql_cmd[@]}" <<SQL
DELETE FROM users WHERE username='${USERNAME}';
INSERT INTO users (uid,username,email,password_hash,firstname,lastname,role,is_active)
VALUES ('ac710000-0000-4000-8000-000000000001','${USERNAME}','active-session@example.test','${HASH}','Active','Session',888,1);
SQL

rm -f "$COOKIE"
curl -sS -c "$COOKIE" -b "$COOKIE" "${BASE_URL}/auth/login" > "$LOGIN_HTML"
TOKEN="$(extract_csrf "$LOGIN_HTML")"
test -n "$TOKEN"

STATUS="$(curl -sS -o "$LOGIN_POST_HTML" -D "$LOGIN_HEADERS" -w '%{http_code}' \
  -c "$COOKIE" -b "$COOKIE" \
  --data-urlencode "login=${USERNAME}" \
  --data-urlencode "password=${PASSWORD}" \
  --data-urlencode "csrf_token=${TOKEN}" \
  "${BASE_URL}/auth/login")"
test "$STATUS" = '302'

STATUS="$(curl -sS -o "$BEFORE_HTML" -w '%{http_code}' -c "$COOKIE" -b "$COOKIE" "${BASE_URL}/notes/")"
test "$STATUS" = '200'

"${mysql_cmd[@]}" -e "UPDATE users SET is_active=0 WHERE username='${USERNAME}'"

STATUS="$(curl -sS -o "$AFTER_HTML" -D "$PROTECTED_HEADERS" -w '%{http_code}' \
  -c "$COOKIE" -b "$COOKIE" "${BASE_URL}/notes/")"
test "$STATUS" = '302'
assert_prefixed_login_redirect "$PROTECTED_HEADERS"

# The second request proves the middleware cleared the old authenticated session,
# not merely denied one route while leaving it reusable.
STATUS="$(curl -sS -o "$SECOND_HTML" -D "$PROTECTED_HEADERS" -w '%{http_code}' \
  -c "$COOKIE" -b "$COOKIE" "${BASE_URL}/tasks/")"
test "$STATUS" = '302'
assert_prefixed_login_redirect "$PROTECTED_HEADERS"

grep -Fq "UserModel::select('id', 'role', 'is_active')" app/middlewares/LoginRequared.php
grep -Fq "UserModel::select('id', 'role', 'is_active')" app/middlewares/IsAdmin.php

echo 'Active-session invalidation contract: OK'
