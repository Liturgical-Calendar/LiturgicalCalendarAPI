#!/usr/bin/env bash
#
# Run the full PHPUnit suite against a pcov-instrumented dev server and emit a
# unified clover report at coverage/clover.xml.
#
# Pre-reqs:
#   - php with the pcov extension available (any of: installed system-wide,
#     loaded via php.ini, or buildable; the script just calls plain php).
#   - vendor/ populated (`composer install`).
#   - pcov.enabled=1 will be forced on the command line, so users don't need
#     it on in their default php.ini.
#
# Output:
#   coverage/phpunit-clover.xml  - in-process clover from PHPUnit
#   coverage/pcov-server/*.cov   - per-request pcov dumps from the dev server
#   coverage/clover.xml          - merged report (PHPUnit + per-request)
#
# Environment:
#   API_HOST   default 127.0.0.1
#   API_PORT   default 8000
#   PHP_CLI_SERVER_WORKERS  default 6

set -euo pipefail

cd "$(dirname "$0")/.."

API_HOST="${API_HOST:-127.0.0.1}"
API_PORT="${API_PORT:-8000}"
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-6}"

COV_DIR="$PWD/coverage/pcov-server"
CLOVER_RAW="$PWD/coverage/phpunit-clover.xml"
CLOVER_OUT="$PWD/coverage/clover.xml"
SERVER_PID_FILE="$PWD/server.coverage.pid"

# Clean any previous coverage state.
rm -rf "$COV_DIR" "$CLOVER_RAW" "$CLOVER_OUT"
mkdir -p "$COV_DIR" "$PWD/coverage"

# The bootstrap hook in public/index.php reads PCOV_SERVER_COVERAGE_DIR from
# the process environment (not $_ENV); exporting it propagates to the server.
export PCOV_SERVER_COVERAGE_DIR="$COV_DIR"

# Start the dev server with pcov enabled, in the background.
echo "Starting pcov-instrumented server on http://$API_HOST:$API_PORT ..."
php -d pcov.enabled=1 -d pcov.directory=src \
    -S "$API_HOST:$API_PORT" -t public \
    > /dev/null 2>&1 &
SERVER_PID=$!
echo "$SERVER_PID" > "$SERVER_PID_FILE"

# Ensure the server is killed even on test failure / abort.
cleanup() {
    if [ -f "$SERVER_PID_FILE" ]; then
        local pid
        pid="$(cat "$SERVER_PID_FILE")"
        # SIGTERM lets workers finish + run shutdown handlers (so the final
        # per-request dump for in-flight requests lands).
        kill -TERM "$pid" 2>/dev/null || true
        wait "$pid" 2>/dev/null || true
        rm -f "$SERVER_PID_FILE"
    fi
}
trap cleanup EXIT INT TERM

# Wait for the server to bind.
for _ in $(seq 1 30); do
    if nc -z "$API_HOST" "$API_PORT" 2>/dev/null; then
        echo "Server is ready."
        break
    fi
    sleep 0.5
done

if ! nc -z "$API_HOST" "$API_PORT" 2>/dev/null; then
    echo "Server failed to come up on $API_HOST:$API_PORT" >&2
    exit 1
fi

# Run PHPUnit with in-process coverage. Tests in phpunit_tests/Routes/* will
# hit the instrumented server; tests elsewhere run in-process.
echo "Running PHPUnit suite ..."
php -d pcov.enabled=1 -d pcov.directory=src \
    vendor/bin/phpunit \
        --display-warnings \
        --exclude-group slow \
        --coverage-clover "$CLOVER_RAW" \
        --log-junit "$PWD/coverage/junit.xml" \
    || PHPUNIT_EXIT=$?

# Stop the server before merging so all per-request shutdown handlers have
# fired and their dump files are on disk.
cleanup
trap - EXIT INT TERM

# Merge the per-request dumps into the PHPUnit clover.
echo "Merging pcov dumps ..."
php scripts/merge-pcov-coverage.php "$CLOVER_RAW" "$COV_DIR" "$CLOVER_OUT"

exit "${PHPUNIT_EXIT:-0}"
