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


php public/LitCalTestServer.php > /dev/null 2>&1 &

# Save PID
pid=$!
echo "$pid" > ws-server.pid

# Feedback
echo "Webosocket Server successfully started (PID: $pid)"
