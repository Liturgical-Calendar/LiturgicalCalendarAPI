#!/bin/sh
# Render every deploy/systemd/*.in template with realistic values and run `systemd-analyze verify` on the
# result, failing on ANY output. `verify` exits 0 on warnings such as
#
#     Unknown key name 'StartLimitIntervalSec' in section 'Service', ignoring.
#
# which is exactly how a misplaced directive shipped in litcal-jobs.service.in (#1019): systemd silently
# ignored it on the server. So the check is "verify said nothing", not "verify exited 0".
#
# The placeholders get values that exist on the machine running the check — this checkout as @API_ROOT@,
# the php on PATH, the current user and group — and the fpm-reload unit's /usr/local/sbin script is pointed
# at this repository's copy, so a clean template produces no output at all and nothing needs filtering.
#
# Usage: scripts/lint-systemd-units.sh        (composer lint:systemd)
set -eu

ROOT="$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
OUT="$(mktemp -d)"
trap 'rm -rf "$OUT"' EXIT

if ! command -v systemd-analyze >/dev/null 2>&1; then
  echo "systemd-analyze is not installed; cannot verify the unit templates." >&2
  exit 1
fi

PHP_BIN="$(command -v php || true)"
if [ -z "$PHP_BIN" ]; then
  echo "php is not on PATH; the templates' ExecStart lines need a real binary to verify against." >&2
  exit 1
fi
RUN_USER="$(id -un)"
RUN_GROUP="$(id -gn)"

for template in "$ROOT"/deploy/systemd/*.in; do
  unit="$OUT/$(basename "$template" .in)"
  sed \
    -e "s|@API_ROOT@|$ROOT|g" \
    -e "s|@PHP_BIN@|$PHP_BIN|g" \
    -e "s|@RUN_USER@|$RUN_USER|g" \
    -e "s|@RUN_GROUP@|$RUN_GROUP|g" \
    -e "s|@PATH_CHANGED_LINES@|PathChanged=$ROOT/tmp/restart.txt|" \
    -e "s|/usr/local/sbin/litcal-fpm-reload.sh|$ROOT/deploy/sbin/litcal-fpm-reload.sh|g" \
    "$template" > "$unit"
done

# All units in one call, from one directory, so the .path unit resolves its .service. The exit status is
# kept separately: a failing verify must fail this check even if it printed nothing, and warnings must fail
# it even though verify exits 0 on them.
if output="$(systemd-analyze verify --man=no "$OUT"/* 2>&1)"; then
  status=0
else
  status=$?
fi
if [ "$status" -ne 0 ] || [ -n "$output" ]; then
  echo "systemd-analyze verify reported problems in deploy/systemd/*.in (exit $status; rendered paths shown):" >&2
  [ -z "$output" ] || echo "$output" >&2
  exit 1
fi
echo "deploy/systemd: $(ls "$OUT" | wc -l | tr -d ' ') unit templates verified clean."
