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

# Whether the API sentinel is what fired. Read before the reload, because the reload
# clears sentinels on success and this must not depend on that ordering.
api_deployed=0
if [ -f "$API_SENTINEL" ]; then
  api_deployed=1
fi

logger -t litcal-fpm-reload "sentinel seen; reloading ${FPM_UNIT} (api=${api_deployed})"
if ! systemctl reload "$FPM_UNIT"; then
  logger -t litcal-fpm-reload "reload FAILED; sentinels kept for retry"
  exit 1
fi

if [ "$api_deployed" -eq 1 ]; then
  # Restart, not reload: there is no reload semantic for this process, and the point
  # is to re-read src/ from disk. Connected clients are dropped, which is acceptable
  # on a deploy — a run interrupted by one was going to be invalid anyway.
  restarts_before="$(systemctl show "$WS_UNIT" -p NRestarts --value 2>/dev/null || echo 0)"

  logger -t litcal-fpm-reload "api deployed; restarting ${WS_UNIT}"
  if ! systemctl restart "$WS_UNIT"; then
    logger -t litcal-fpm-reload "${WS_UNIT} restart FAILED; sentinels kept for retry"
    exit 1
  fi

  # The unit is Restart=always, so a process that dies at startup does not report
  # failure — it silently respawns every RestartSec until someone reads the journal.
  # A merged change once made every start die; the service survived only because
  # nothing restarted it. Wait past one restart interval and compare the counter, so a
  # crash-loop is reported at deploy time rather than discovered days later.
  sleep 8
  restarts_after="$(systemctl show "$WS_UNIT" -p NRestarts --value 2>/dev/null || echo 0)"
  if [ "$restarts_after" -gt "$restarts_before" ]; then
    logger -t litcal-fpm-reload "${WS_UNIT} is CRASH-LOOPING after deploy (NRestarts ${restarts_before}->${restarts_after}); check: journalctl -u ${WS_UNIT} -n 50"
    exit 1
  fi
  logger -t litcal-fpm-reload "${WS_UNIT} restarted and stable"
fi

rm -f $SENTINELS
logger -t litcal-fpm-reload "reload OK; sentinels cleared"
