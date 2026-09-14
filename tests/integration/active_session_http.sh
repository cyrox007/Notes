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

mysql_cmd=(mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "-p${DB_PASS}" "$DB_NAME")

cleanup() {
  set +e
  rm -f "$COOKIE" "$LOGIN_HTML" "$LOGIN_HEADERS" "$PROTECTED_HEADERS"
  "${mysql_cmd[@]}" -e "DELETE FROM users WHERE username='${USERNAME}'" >/dev/null 2>&1 || true
}
trap cleanup EXIT

extract_csrf() {
  sed -n 's/.*name="csrf_token" value="\([^"]*\)".*/\1/p' "$1" | head -n1
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

STATUS="$(curl -sS -o /tmp/active-session-login-post.html -D "$LOGIN_HEADERS" -w '%{http_code}' \
  -c "$COOKIE" -b "$COOKIE" \
  --data-urlencode "login=${USERNAME}" \
  --data-urlencode "password=${PASSWORD}" \
  --data-urlencode "csrf_token=${TOKEN}" \
  "${BASE_URL}/auth/login")"
test "$STATUS" = '302'

STATUS="$(curl -sS -o /tmp/active-session-before.html -w '%{http_code}' -c "$COOKIE" -b "$COOKIE" "${BASE_URL}/notes/")"
test "$STATUS" = '200'

"${mysql_cmd[@]}" -e "UPDATE users SET is_active=0 WHERE username='${USERNAME}'"

STATUS="$(curl -sS -o /tmp/active-session-after.html -D "$PROTECTED_HEADERS" -w '%{http_code}' \
  -c "$COOKIE" -b "$COOKIE" "${BASE_URL}/notes/")"
test "$STATUS" = '302'
grep -Eiq "^Location: ${BASE_URL#*://*/}/auth/login|^Location: /workspace/auth/login" "$PROTECTED_HEADERS" || {
  echo 'Inactive session was not redirected to the prefixed login route' >&2
  cat "$PROTECTED_HEADERS" >&2
  exit 1
}

# The second request proves the middleware cleared the old authenticated session,
# not merely denied one route while leaving it reusable.
STATUS="$(curl -sS -o /tmp/active-session-second.html -D "$PROTECTED_HEADERS" -w '%{http_code}' \
  -c "$COOKIE" -b "$COOKIE" "${BASE_URL}/tasks/")"
test "$STATUS" = '302'

grep -Fq "UserModel::select('id', 'role', 'is_active')" app/middlewares/LoginRequared.php
grep -Fq "UserModel::select('id', 'role', 'is_active')" app/middlewares/IsAdmin.php

echo 'Active-session invalidation contract: OK'
