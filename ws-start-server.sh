#!/bin/bash
if [ -f "ws-server.pid" ]; then
  pid=$(cat ws-server.pid)
  if kill -0 "$pid" 2>/dev/null; then
    echo "Websocket Server already started"
    exit 0
  else
    echo "No process with PID $pid found. Removing stale ws-server.pid."
    rm ws-server.pid
  fi
fi


# When the coverage harness is active (PCOV_SERVER_COVERAGE_DIR is exported by
# scripts/coverage-with-server.sh or the CI workflow), enable pcov on the WS
# server PHP process so the bootstrap hook in public/LitCalTestServer.php can
# collect per-message coverage. No-op in normal development / production.
if [ -n "${PCOV_SERVER_COVERAGE_DIR:-}" ]; then
  php -d pcov.enabled=1 -d pcov.directory=src public/LitCalTestServer.php > /dev/null 2>&1 &
else
  php public/LitCalTestServer.php > /dev/null 2>&1 &
fi

# Save PID
pid=$!
echo "$pid" > ws-server.pid

# Feedback
echo "Webosocket Server successfully started (PID: $pid)"
