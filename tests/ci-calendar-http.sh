#!/usr/bin/env bash
# Build an isolated native Dolibarr from the supplied Git checkout and run the calendar client scenarios.
set -euo pipefail
umask 077
module_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
: "${DOLIBARR_ROOT:?Point DOLIBARR_ROOT at a Dolibarr htdocs in a Git checkout}"
core_dir=$(git -C "$DOLIBARR_ROOT" rev-parse --show-toplevel)
php_bin=$(command -v "${CDAV_PHP_BIN:-php}")
run_dir=$(mktemp -d "${TMPDIR:-/tmp}/cdav-http.XXXXXXXX")
web_pid=''
db_pid=''
cleanup() {
    if [[ -n "$web_pid" ]]; then
        kill -- "-$web_pid" 2>/dev/null || true
        wait "$web_pid" 2>/dev/null || true
    fi
    if [[ -n "$db_pid" ]]; then
        kill "$db_pid" 2>/dev/null || true
        wait "$db_pid" 2>/dev/null || true
    fi
    rm -rf -- "$run_dir"
}
trap cleanup EXIT
trap 'exit 1' HUP INT TERM

export DOLIBARR_ROOT="$run_dir/htdocs"
mkdir -p "$run_dir/documents" "$run_dir/sessions"
git -C "$core_dir" archive HEAD htdocs | tar -x -C "$run_dir"
mkdir -p "$DOLIBARR_ROOT/custom/cdav"
git -C "$module_dir" ls-files -z --cached |
    rsync -a --from0 --files-from=- "$module_dir/" "$DOLIBARR_ROOT/custom/cdav/"
socket="$run_dir/maria.sock"
if ! mariadb-install-db --no-defaults --datadir="$run_dir/database" --auth-root-authentication-method=normal --skip-test-db > "$run_dir/db-init.log" 2>&1; then
    tail -n 30 "$run_dir/db-init.log" >&2
    exit 1
fi
mariadbd --no-defaults --datadir="$run_dir/database" --socket="$socket" --skip-networking \
    --pid-file="$run_dir/db.pid" --log-error="$run_dir/db.log" &
db_pid=$!
for ((attempt=0; attempt<150; attempt++)); do
    if mariadb-admin --no-defaults --socket="$socket" -uroot ping --silent >/dev/null 2>&1; then break; fi
    kill -0 "$db_pid" 2>/dev/null || break
    sleep 0.2
done
if ! mariadb-admin --no-defaults --socket="$socket" -uroot ping --silent >/dev/null 2>&1; then
    printf '%s\n' 'The temporary MariaDB server did not start.' >&2
    tail -n 30 "$run_dir/db.log" >&2
    exit 1
fi
export CDAV_CI_DB_PASSWORD
CDAV_CI_DB_PASSWORD=$("$php_bin" -r 'echo bin2hex(random_bytes(24));')
mariadb --no-defaults --socket="$socket" -uroot <<SQL
CREATE DATABASE cdav_calendar_test CHARACTER SET utf8mb4;
CREATE USER 'cdavci'@'localhost' IDENTIFIED BY '$CDAV_CI_DB_PASSWORD';
GRANT ALL PRIVILEGES ON cdav_calendar_test.* TO 'cdavci'@'localhost';
SQL
# The server is bound only on loopback. The per-run file also proves we reached our own listener.
port=$("$php_bin" -r '$s = stream_socket_server("tcp://127.0.0.1:0"); echo parse_url("tcp://".stream_socket_get_name($s, false), PHP_URL_PORT);')
export CDAV_CI_URL="http://127.0.0.1:$port"
php_flags=(-d "mysqli.default_socket=$socket" -d "session.save_path=$run_dir/sessions" -d date.timezone=Europe/Zurich)
cat > "$DOLIBARR_ROOT/conf/conf.php" <<'CONF'
<?php
$dolibarr_main_document_root = dirname(__DIR__);
$dolibarr_main_data_root = dirname($dolibarr_main_document_root).'/documents';
if (!defined('SYSLOG_FILE')) define('SYSLOG_FILE', $dolibarr_main_data_root.'/dolibarr.log');
$dolibarr_main_url_root = getenv('CDAV_CI_URL');
$dolibarr_main_url_root_alt = '/custom';
$dolibarr_main_document_root_alt = $dolibarr_main_document_root.'/custom';
$dolibarr_main_db_host = 'localhost';
$dolibarr_main_db_type = 'mysqli';
$dolibarr_main_db_name = 'cdav_calendar_test';
$dolibarr_main_db_user = 'cdavci';
$dolibarr_main_db_pass = getenv('CDAV_CI_DB_PASSWORD');
$dolibarr_main_db_prefix = 'llx_';
$dolibarr_main_authentication = 'dolibarr';
CONF
printf '%s\n' 'Installing a fresh Dolibarr database with the native CLI installer.'
cd "$DOLIBARR_ROOT/install"
"$php_bin" "${php_flags[@]}" step2.php set > "$run_dir/schema.log"
admin_password=$("$php_bin" -r 'echo bin2hex(random_bytes(24));')
"$php_bin" "${php_flags[@]}" step5.php '' '' en_US set ciadmin "$admin_password" "$admin_password" 1 > "$run_dir/admin.log"

cd "$DOLIBARR_ROOT"
"$php_bin" "${php_flags[@]}" "$module_dir/tests/ci-activate-modules.php"
listener_token=$("$php_bin" -r 'echo bin2hex(random_bytes(12));')
printf '%s' "$listener_token" > "$DOLIBARR_ROOT/$listener_token.txt"
setsid env PHP_CLI_SERVER_WORKERS=3 "$php_bin" "${php_flags[@]}" -S "127.0.0.1:$port" -t "$DOLIBARR_ROOT" > "$run_dir/http.log" 2>&1 &
web_pid=$!
for ((attempt=0; attempt<50; attempt++)); do
    if [[ "$(curl --noproxy '*' -fsS --max-time 1 "$CDAV_CI_URL/$listener_token.txt" 2>/dev/null)" == "$listener_token" ]]; then break; fi
    kill -0 "$web_pid"
    sleep 0.2
done
[[ "$(curl --noproxy '*' -fsS --max-time 2 "$CDAV_CI_URL/$listener_token.txt")" == "$listener_token" ]]
printf '%s\n' 'Checking project tasks, appointment edits and invitations against the local HTTP endpoint.'
CDAV_LIVE_TEST=1 CDAV_LIVE_URL="$CDAV_CI_URL/custom/cdav/server.php" \
    "$php_bin" "${php_flags[@]}" "$DOLIBARR_ROOT/custom/cdav/tests/live_integration.php" --calendar-clients
