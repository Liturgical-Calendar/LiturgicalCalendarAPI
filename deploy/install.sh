#!/bin/sh
# Render the systemd templates against /etc/litcal-deploy.env and install everything.
# Run as root on the deployment host, from a checkout of this repository:
#
#     sudo deploy/install.sh
#
# The templates in deploy/systemd/*.in carry placeholders, never real values — this
# repository is public. The real paths, accounts and unit names live only in the
# config file, which is not committed.
set -eu

CONFIG="${LITCAL_DEPLOY_ENV:-/etc/litcal-deploy.env}"
SRC_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
UNIT_DIR=/etc/systemd/system

if [ ! -r "$CONFIG" ]; then
  echo "error: $CONFIG missing. Copy deploy/litcal-deploy.env.example there and fill it in." >&2
  exit 1
fi
# shellcheck source=/dev/null
. "$CONFIG"

for var in API_ROOT PHP_BIN RUN_USER RUN_GROUP FPM_UNIT WS_UNIT; do
  eval "value=\${$var:-}"
  if [ -z "$value" ]; then
    echo "error: $var not set in $CONFIG" >&2
    exit 1
  fi
done

# One PathChanged= per app that is actually deployed here, so a host watching a
# subset does not get a unit referring to directories it does not have.
path_changed_lines=""
for root in "$API_ROOT" "${FRONTEND_ROOT:-}" "${FRONTEND_STAGING_ROOT:-}" "${TESTS_ROOT:-}"; do
  [ -n "$root" ] || continue
  path_changed_lines="${path_changed_lines}PathChanged=${root}/tmp/restart.txt
"
done

render() {
  sed \
    -e "s|@API_ROOT@|${API_ROOT}|g" \
    -e "s|@PHP_BIN@|${PHP_BIN}|g" \
    -e "s|@RUN_USER@|${RUN_USER}|g" \
    -e "s|@RUN_GROUP@|${RUN_GROUP}|g" \
    -e "/@PATH_CHANGED_LINES@/{r /dev/stdin
d
}" "$1"
}

echo "rendering units from $CONFIG"
printf '%s' "$path_changed_lines" | render "${SRC_DIR}/systemd/litcal-fpm-reload.path.in" > "${UNIT_DIR}/litcal-fpm-reload.path"
render "${SRC_DIR}/systemd/litcal-fpm-reload.service.in" < /dev/null > "${UNIT_DIR}/litcal-fpm-reload.service"
render "${SRC_DIR}/systemd/litcal-websocket.service.in"  < /dev/null > "${UNIT_DIR}/litcal-websocket.service"
chmod 0644 "${UNIT_DIR}/litcal-fpm-reload.path" "${UNIT_DIR}/litcal-fpm-reload.service" "${UNIT_DIR}/litcal-websocket.service"

install -m 0755 -o root -g root "${SRC_DIR}/sbin/litcal-fpm-reload.sh" /usr/local/sbin/litcal-fpm-reload.sh

systemctl daemon-reload
systemctl enable --now litcal-fpm-reload.path "$WS_UNIT"

echo "installed. verify with:"
echo "  systemctl status litcal-fpm-reload.path"
echo "  systemctl status $WS_UNIT"
