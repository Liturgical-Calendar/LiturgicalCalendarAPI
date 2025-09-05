#!/bin/bash
set -euo pipefail

# Colors
RED='\033[0;31m'
YELLOW='\033[0;33m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

stop_pidfile() {
  local file="$1"
  if [[ ! -f "$file" ]]; then
    echo -e "${YELLOW}No $file found. Is the server even running?${NC}"
    return 0
  fi

  local pid
  pid=$(<"$file")

  if ! [[ "$pid" =~ ^[0-9]+$ ]]; then
    echo -e "${RED}Invalid PID in $file: $pid. Expected a number.${NC}"
    rm -f "$file"
    return 0
  fi

  if ! kill -0 "$pid" 2>/dev/null; then
    echo -e "${YELLOW}Stale $file (no process with PID $pid). Removing.${NC}"
    rm -f "$file"
    return 0
  fi

  echo -e "${YELLOW}Stopping server with PID $pid from $file...${NC}"
  kill "$pid"

  # Wait 2 seconds, force kill if needed
  sleep 2
  if kill -0 "$pid" 2>/dev/null; then
    echo -e "${RED}Process $pid did not stop, sending SIGKILL...${NC}"
    kill -9 "$pid"
    sleep 1
  fi

  if kill -0 "$pid" 2>/dev/null; then
    echo -e "${RED}Process $pid is still running after SIGKILL!${NC}"
    return 1
  else
    echo -e "Server process $pid stopped."
    rm -f "$file"
  fi
}

# Try stopping either pidfile, priority: vscode → normal
if [[ -f "server.vscode.pid" ]]; then
  stop_pidfile "server.vscode.pid"
else
  stop_pidfile "server.pid"
fi
