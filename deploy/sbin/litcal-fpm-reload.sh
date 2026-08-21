#!/bin/sh
# Installed at /usr/local/sbin/litcal-fpm-reload.sh (root:root, 0755).
# Triggered by litcal-fpm-reload.path when a deploy drops <approot>/tmp/restart.txt.
#
# Every host-specific value — paths, unit names, accounts — is read from
# /etc/litcal-deploy.env, which is NOT in the repository. This file is committed to a
# public repo and must stay free of anything that describes a real deployment.
#
# Two jobs, because a deploy updates two kinds of thing:
#
#   1. php-fpm is graceful-reloaded, which recycles workers and busts the gettext .mo
#      cache. One reload covers all pools, so it runs for any sentinel.
#
#   2. The WebSocket server is *restarted*, but only when the API sentinel fired. It
#      is a long-running ReactPHP process: it loads src/ into memory once, at start,
#      and never re-reads it. Without this, a deploy updates the REST API and leaves
#      the WebSocket endpoint serving whatever it loaded at boot — indefinitely, since
#      nothing else restarts it. Observed in the wild: a deployed API was ~25 hours
#      newer than the running WebSocket process, so an entire protocol change was on
#      disk and inert.
#
# Sentinels are removed only on success, so a failure retries on the next deploy.
set -u

CONFIG="${LITCAL_DEPLOY_ENV:-/etc/litcal-deploy.env}"
if [ ! -r "$CONFIG" ]; then
  logger -t litcal-fpm-reload "config $CONFIG missing or unreadable; refusing to guess"
  exit 1
fi
# shellcheck source=/dev/null
. "$CONFIG"

: "${API_ROOT:?API_ROOT not set in $CONFIG}"
: "${FPM_UNIT:?FPM_UNIT not set in $CONFIG}"
: "${WS_UNIT:?WS_UNIT not set in $CONFIG}"

API_SENTINEL="${API_ROOT}/tmp/restart.txt"

# Every watched app, skipping any left unset so a host can watch a subset.
SENTINELS="$API_SENTINEL"
for root in "${FRONTEND_ROOT:-}" "${FRONTEND_STAGING_ROOT:-}" "${TESTS_ROOT:-}"; do
  [ -n "$root" ] && SENTINELS="$SENTINELS ${root}/tmp/restart.txt"
done

# Claim the sentinels by renaming them, before doing any work.
#
# The naive version tests for the sentinel here and deletes it at the end — but the end
# is 8+ seconds later, past a service restart. A deploy landing inside that window drops
# a fresh sentinel that this run then deletes, so its changes are on disk and never
# reloaded. That is the same silent-no-op this whole script exists to prevent, so it is
# worth the rename: `mv` within a filesystem is atomic, a concurrent deploy writes a new
# `restart.txt` that this run cannot see, and systemd queues another run for it.
claim() {
  for sentinel in $1; do
    [ -f "$sentinel" ] && mv -f "$sentinel" "${sentinel}.claimed"
  done
  return 0
}

# Put the claims back, so a failure retries on the next deploy exactly as before.
release() {
  for sentinel in $1; do
    [ -f "${sentinel}.claimed" ] && mv -f "${sentinel}.claimed" "$sentinel"
  done
  return 0
}

claim "$SENTINELS"

# Whether the API sentinel is what fired, decided from the claim rather than the
# original: nothing after this point can be confused by a concurrent deploy.
api_deployed=0
if [ -f "${API_SENTINEL}.claimed" ]; then
  api_deployed=1
fi

logger -t litcal-fpm-reload "sentinel seen; reloading ${FPM_UNIT} (api=${api_deployed})"
if ! systemctl reload "$FPM_UNIT"; then
  logger -t litcal-fpm-reload "reload FAILED; sentinels kept for retry"
  release "$SENTINELS"
  exit 1
fi

if [ "$api_deployed" -eq 1 ]; then
  # Restart, not reload: there is no reload semantic for this process, and the point
  # is to re-read src/ from disk. Connected clients are dropped, which is acceptable
  # on a deploy — a run interrupted by one was going to be invalid anyway.
  # Fail closed. A failed or non-numeric query used to fall back to 0 on both sides, so
  # the comparison below said "no change" and the run reported a crash-looping service as
  # stable — silently disabling the one check that catches a fatal at startup.
  restarts_before="$(systemctl show "$WS_UNIT" -p NRestarts --value 2>/dev/null)"
  case "$restarts_before" in
    ''|*[!0-9]*)
      logger -t litcal-fpm-reload "cannot read NRestarts for ${WS_UNIT} (got '${restarts_before}'); sentinels kept for retry"
      release "$SENTINELS"
      exit 1
      ;;
  esac

  logger -t litcal-fpm-reload "api deployed; restarting ${WS_UNIT}"
  if ! systemctl restart "$WS_UNIT"; then
    logger -t litcal-fpm-reload "${WS_UNIT} restart FAILED; sentinels kept for retry"
    release "$SENTINELS"
    exit 1
  fi

  # The unit is Restart=always, so a process that dies at startup does not report
  # failure — it silently respawns every RestartSec until someone reads the journal.
  # A merged change once made every start die; the service survived only because
  # nothing restarted it. Wait past one restart interval and compare the counter, so a
  # crash-loop is reported at deploy time rather than discovered days later.
  sleep 8
  restarts_after="$(systemctl show "$WS_UNIT" -p NRestarts --value 2>/dev/null)"
  case "$restarts_after" in
    ''|*[!0-9]*)
      logger -t litcal-fpm-reload "cannot read NRestarts for ${WS_UNIT} after restart (got '${restarts_after}'); sentinels kept for retry"
      release "$SENTINELS"
      exit 1
      ;;
  esac
  if [ "$restarts_after" -gt "$restarts_before" ]; then
    logger -t litcal-fpm-reload "${WS_UNIT} is CRASH-LOOPING after deploy (NRestarts ${restarts_before}->${restarts_after}); check: journalctl -u ${WS_UNIT} -n 50"
    release "$SENTINELS"
    exit 1
  fi
  logger -t litcal-fpm-reload "${WS_UNIT} restarted and stable"
fi

# One at a time and quoted, so a path containing a space is removed rather than split
# into two that do not exist. `rm -f` is silent about a missing file but not about a
# permission error, and claiming success while a sentinel survives would report a deploy
# as done while leaving the trigger armed.
cleanup_failed=0
for sentinel in $SENTINELS; do
  if [ -e "${sentinel}.claimed" ] && ! rm -f -- "${sentinel}.claimed"; then
    logger -t litcal-fpm-reload "could not remove ${sentinel}.claimed"
    cleanup_failed=1
  fi
done
if [ "$cleanup_failed" -eq 1 ]; then
  logger -t litcal-fpm-reload "reload done but sentinel cleanup FAILED"
  exit 1
fi
logger -t litcal-fpm-reload "reload OK; sentinels cleared"
