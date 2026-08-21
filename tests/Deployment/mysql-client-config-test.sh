#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
HELPER="$ROOT/ops/deploy/write-mysql-client-config.php"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

[[ -f "$ROOT/vendor/autoload.php" ]] || fail 'Composer dependencies are required for this regression test.'

cat > "$TMP/.env" <<'ENV'
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=horus_regression
DB_USERNAME=horus_regression_user
DB_PASSWORD="password with spaces # and symbols !@%"
ENV

output="$TMP/mysql-client.cnf"
database="$(php "$HELPER" "$ROOT" "$TMP/.env" "$output")"

[[ "$database" == 'horus_regression' ]] || fail "Unexpected database name: $database"
[[ -s "$output" ]] || fail 'MySQL client config was not created.'
mode="$(stat -c '%a' "$output")"
[[ "$mode" == '600' ]] || fail "Expected MySQL client config mode 600, got $mode"
grep -Fxq 'host="127.0.0.1"' "$output" || fail 'Host was not written correctly.'
grep -Fxq 'port="3306"' "$output" || fail 'Port was not written correctly.'
grep -Fxq 'user="horus_regression_user"' "$output" || fail 'Username was not written correctly.'
grep -Fq 'password="password with spaces # and symbols !@%"' "$output" || fail 'Password was not preserved correctly.'

# DB_URL must override individual connection fields without exposing credentials
# on stdout.
cat > "$TMP/.env-url" <<'ENV'
DB_CONNECTION=mysql
DB_URL="mysql://url_user:url%20password@db.internal:3307/url_database"
DB_HOST=ignored.example
DB_PORT=3306
DB_DATABASE=ignored_database
DB_USERNAME=ignored_user
DB_PASSWORD=ignored_password
ENV

url_output="$TMP/mysql-url.cnf"
url_database="$(php "$HELPER" "$ROOT" "$TMP/.env-url" "$url_output")"
[[ "$url_database" == 'url_database' ]] || fail "Unexpected DB_URL database name: $url_database"
grep -Fxq 'host="db.internal"' "$url_output" || fail 'DB_URL host did not override DB_HOST.'
grep -Fxq 'port="3307"' "$url_output" || fail 'DB_URL port did not override DB_PORT.'
grep -Fxq 'user="url_user"' "$url_output" || fail 'DB_URL user did not override DB_USERNAME.'
grep -Fq 'password="url password"' "$url_output" || fail 'DB_URL password was not decoded correctly.'

if php "$HELPER" "$ROOT" "$TMP/.env" "$TMP/second.cnf" 2>/dev/null | grep -Fq 'password'; then
    fail 'Helper leaked a password to stdout.'
fi

echo 'MySQL backup helper regression tests passed.'
