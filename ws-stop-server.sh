#!/bin/bash
set -euo pipefail

if [ ! -f ws-server.pid ]; then
  echo "No ws-server.pid file found. Is the websocket server running?"
  exit 1
fi

pid=$(cat ws-server.pid)
if ! [[ "$pid" =~ ^[0-9]+$ ]]; then
  echo "Invalid PID in ws-server.pid: $pid. Expected a number."
  exit 1
fi

if ! kill -0 "$pid" 2>/dev/null; then
  echo "No process with PID $pid found. Removing stale ws-server.pid."
  rm ws-server.pid
  exit 1
fi

echo "Stopping PHP Websocket server with PID $pid"
kill "$pid"

# Wait 2 seconds, force kill if needed
sleep 2
if kill -0 "$pid" 2>/dev/null; then
  echo "Process $pid did not stop, sending SIGKILL"
  kill -9 "$pid"
  # Give it a moment and verify again
  sleep 1
  if ! kill -0 "$pid" 2>/dev/null; then
    echo "PHP Websocket server force-stopped."
    rm -f ws-server.pid
    exit 0
  else
    echo "Process $pid is still running after SIGKILL"
    exit 1
  fi
else
  echo "PHP Websocket server stopped."
  rm ws-server.pid
fi
